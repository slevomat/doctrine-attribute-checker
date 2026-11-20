<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Console;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Nette\Utils\FileSystem;
use Slevomat\DoctrineAttributeChecker\Baseline\Baseline;
use Slevomat\DoctrineAttributeChecker\Baseline\BaselineWriter;
use Slevomat\DoctrineAttributeChecker\Baseline\RelativePath;
use Slevomat\DoctrineAttributeChecker\Checker\DoctrineAttributeChecker;
use Slevomat\DoctrineAttributeChecker\Configuration\Configuration;
use Slevomat\DoctrineAttributeChecker\Configuration\ConfigurationLoader;
use Slevomat\DoctrineAttributeChecker\Database\DatabaseSchemaSnapshot;
use Slevomat\DoctrineAttributeChecker\Database\SchemaSource;
use Slevomat\DoctrineAttributeChecker\Rules;
use Slevomat\DoctrineAttributeChecker\Source\AttributeSourceEditor;
use Slevomat\DoctrineAttributeChecker\Violation;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;
use function array_filter;
use function array_push;
use function array_values;
use function count;
use function explode;
use function getcwd;
use function realpath;
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;
use function usort;
use const DIRECTORY_SEPARATOR;

#[AsCommand(name: 'orm:check-attributes', description: 'Check and optionally fix Doctrine mapping attributes.')]
final class CheckAttributesCommand extends Command
{

	private const string DEFAULT_BASELINE_FILE = 'doctrine-attributes-baseline.neon';

	/**
	 * @param list<SchemaSource> $additionalSchemaSources Databases whose tables are used when missing in the primary one.
	 */
	public function __construct(
		private readonly EntityManagerInterface $entityManager,
		private readonly array $additionalSchemaSources = [],
		private readonly ConfigurationLoader $configurationLoader = new ConfigurationLoader(),
	)
	{
		parent::__construct();
	}

	/**
	 * @param list<string> $files
	 */
	public function __invoke(
		OutputInterface $output,
		#[Argument(description: 'Only inspect the specified PHP files.')]
		array $files = [],
		#[Option(description: 'Apply automatic fixes to the inspected files.')]
		bool $fix = false,
		#[Option(description: 'Compare schema-related attributes with the database.')]
		bool $db = false,
		#[Option(description: 'Configuration file (default: doctrine-attributes.neon in the current directory).')]
		?string $configuration = null,
		#[Option(description: 'Write all current violations to the baseline file (default: the configured baseline).')]
		string|bool $generateBaseline = false,
	): int
	{
		$config = $this->configurationLoader->load($configuration);

		if ($generateBaseline !== false && ($fix || $files !== [])) {
			$output->writeln('<error>--generate-baseline cannot be combined with --fix or with file arguments.</error>');

			return self::INVALID;
		}

		$metadataByClassName = [];
		$mappedClassNames = [];
		$filesByClassName = [];
		/** @var list<ClassMetadata<object>> $metadata */
		$metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
		foreach ($metadata as $classMetadata) {
			$className = $classMetadata->name;
			$metadataByClassName[$className] = $classMetadata;
			$mappedClassNames[$className] = true;

			$classFile = $classMetadata->getReflectionClass()->getFileName();
			if ($classFile === false) {
				continue;
			}

			$canonicalFile = realpath($classFile);
			$filesByClassName[$className] = $canonicalFile === false ? $classFile : $canonicalFile;
		}

		$selectedFiles = $this->selectFiles($filesByClassName, $files);

		$checker = new DoctrineAttributeChecker(
			$this->entityManager,
			$config,
			new AttributeSourceEditor(),
			$metadataByClassName,
			$mappedClassNames,
			$db ? $this->loadDatabaseSchema() : null,
		);
		$violations = [];
		$changedFiles = [];
		$progressBar = new ProgressBar($output, count($selectedFiles));
		$progressBar->start();

		foreach ($selectedFiles as $file) {
			$source = FileSystem::read($file);
			$result = $checker->inspect($source, $file, $fix);
			if ($result['changed']) {
				FileSystem::write($file, $result['contents']);
				$changedFiles[] = $file;
				$result = $checker->inspect($result['contents'], $file, false);
			}

			array_push($violations, ...$result['violations']);
			$progressBar->advance();
		}

		$progressBar->finish();
		$output->writeln('');

		if ($generateBaseline !== false) {
			return $this->generateBaseline($output, $config, $generateBaseline, $violations);
		}

		$baseline = new Baseline($config->rootDirectory, $config->ignoreErrors);
		$violations = $baseline->filter($violations);
		$unmatchedBaselineEntries = $files === []
			? array_values(array_filter(
				$baseline->getUnmatchedEntries(),
				static fn (array $entry): bool => $db || !Rules::isDatabaseRule($entry['rule']),
			))
			: [];

		usort(
			$violations,
			static fn (Violation $a, Violation $b): int => [$a->file, $a->line, $a->rule]
				<=> [$b->file, $b->line, $b->rule],
		);

		foreach ($changedFiles as $file) {
			$output->writeln(sprintf('<info>Fixed %s.</info>', $this->displayPath($file)));
		}

		/** @var array<string, non-empty-list<Violation>> $violationsByEntity */
		$violationsByEntity = [];
		$fixableViolationCount = 0;
		foreach ($violations as $violation) {
			$entityName = explode('::', $violation->target, 2)[0];
			$violationsByEntity[$entityName][] = $violation;
			if ($violation->fixable) {
				$fixableViolationCount++;
			}
		}

		foreach ($violationsByEntity as $entityName => $entityViolations) {
			$table = new Table($output);
			$table->setHeaders([
				'Line',
				sprintf("%s\n%s", $entityName, $this->displayPath($entityViolations[0]->file)),
			]);

			foreach ($entityViolations as $violation) {
				$targetParts = explode('::', $violation->target, 2);
				$member = $targetParts[1] ?? null;
				$message = $member === null
					? $violation->message
					: sprintf('%s: %s', $member, $violation->message);
				$fixableMarker = !$fix && $violation->fixable
					? "\n🔧 Fixable with --fix"
					: '';
				$table->addRow([
					$violation->line,
					sprintf("%s\n🪪 %s%s", $message, $violation->rule, $fixableMarker),
				]);
			}

			$table->render();
		}

		if ($unmatchedBaselineEntries !== []) {
			$table = new Table($output);
			$table->setHeaders(['Rule', 'Target', 'File', 'Message']);

			foreach ($unmatchedBaselineEntries as $entry) {
				$table->addRow([$entry['rule'], $entry['target'], $entry['path'], $entry['message']]);
			}

			$table->render();
		}

		if ($violations !== [] || $unmatchedBaselineEntries !== []) {
			if ($violations !== []) {
				$entityCount = count($violationsByEntity);
				$fixableSummary = !$fix && $fixableViolationCount > 0
					? sprintf(', %d fixable with --fix', $fixableViolationCount)
					: '';
				$output->writeln(sprintf(
					'<error>[ERROR] Found %d %s in %d %s%s.</error>',
					count($violations),
					count($violations) === 1 ? 'error' : 'errors',
					$entityCount,
					$entityCount === 1 ? 'entity' : 'entities',
					$fixableSummary,
				));
			}

			if ($unmatchedBaselineEntries !== []) {
				$output->writeln(sprintf(
					'<error>[ERROR] Found %d unmatched Doctrine attribute baseline %s.</error>',
					count($unmatchedBaselineEntries),
					count($unmatchedBaselineEntries) === 1 ? 'entry' : 'entries',
				));
			}

			return self::FAILURE;
		}

		if ($selectedFiles === []) {
			$output->writeln('<info>No mapped Doctrine entity files were selected.</info>', OutputInterface::VERBOSITY_VERBOSE);
		} else {
			$output->writeln('<info>[OK] No errors found.</info>');
		}

		return self::SUCCESS;
	}

	/**
	 * @param array<class-string, string> $filesByClassName
	 * @param list<string> $requestedFiles
	 * @return array<string, string>
	 */
	private function selectFiles(array $filesByClassName, array $requestedFiles): array
	{
		$selectedFiles = [];
		if ($requestedFiles === []) {
			foreach ($filesByClassName as $file) {
				$selectedFiles[$file] = $file;
			}

			return $selectedFiles;
		}

		foreach ($requestedFiles as $requestedFile) {
			$canonicalFile = realpath($requestedFile);
			if ($canonicalFile === false) {
				continue;
			}

			foreach ($filesByClassName as $classFile) {
				if ($classFile === $canonicalFile) {
					$selectedFiles[$classFile] = $classFile;
					break;
				}
			}
		}

		return $selectedFiles;
	}

	private function loadDatabaseSchema(): DatabaseSchemaSnapshot
	{
		$schema = DatabaseSchemaSnapshot::load($this->entityManager->getConnection());
		foreach ($this->additionalSchemaSources as $source) {
			$schema = $schema->withSecondary(DatabaseSchemaSnapshot::load($source->connection, $source->queryPrefix));
		}

		return $schema;
	}

	/**
	 * @param list<Violation> $violations
	 */
	private function generateBaseline(
		OutputInterface $output,
		Configuration $config,
		string|true $baselineOption,
		array $violations,
	): int
	{
		$baselineFile = $baselineOption === true
			? $config->baselineFile ?? $config->rootDirectory . DIRECTORY_SEPARATOR . self::DEFAULT_BASELINE_FILE
			: $baselineOption;
		$entryCount = (new BaselineWriter())->write($baselineFile, $config->rootDirectory, $violations);

		$output->writeln(sprintf(
			'<info>[OK] Baseline with %d %s generated to %s.</info>',
			$entryCount,
			$entryCount === 1 ? 'entry' : 'entries',
			RelativePath::fromRoot($config->rootDirectory, $baselineFile),
		));

		return self::SUCCESS;
	}

	private function displayPath(string $file): string
	{
		$currentDirectory = getcwd();
		if ($currentDirectory === false) {
			return $file;
		}

		$canonicalDirectory = realpath($currentDirectory);
		if ($canonicalDirectory === false || !str_starts_with($file, $canonicalDirectory . '/')) {
			return $file;
		}

		return substr($file, strlen($canonicalDirectory) + 1);
	}

}
