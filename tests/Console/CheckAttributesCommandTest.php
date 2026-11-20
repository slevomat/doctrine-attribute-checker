<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Console;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Nette\Neon\Neon;
use Nette\Utils\FileSystem;
use Override;
use PHPUnit\Framework\TestCase;
use Slevomat\DoctrineAttributeChecker\Checker\Data\FixtureBasicEntity;
use Slevomat\DoctrineAttributeChecker\Checker\FixtureMetadata;
use Slevomat\DoctrineAttributeChecker\CheckerException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Webmozart\Assert\Assert;
use function array_values;
use function realpath;
use function sprintf;
use function sys_get_temp_dir;
use function uniqid;

class CheckAttributesCommandTest extends TestCase
{

	// Rules intentionally violated by other fixture entities are disabled.
	private const string BASE_CONFIGURATION = <<<'NEON'
		rules:
			disabled:
				- identifier.reserved
				- join-column.referenced-column-name
				- manytoone.target-entity
				- onetoone.target-entity

		NEON;

	private const string CONFIGURATION = <<<'NEON'
		baseline: build/baseline.neon

		identifiers:
			additionalReservedWords: [attribute_check_basic]
		NEON;

	public static ?EntityManagerInterface $loadedEntityManager = null;

	private string $directory;

	#[Override]
	protected function setUp(): void
	{
		$this->directory = sprintf('%s/%s', sys_get_temp_dir(), uniqid('doctrine-attribute-command-', true));
		FileSystem::createDir($this->directory);
		$canonicalDirectory = realpath($this->directory);
		self::assertNotFalse($canonicalDirectory);
		$this->directory = $canonicalDirectory;
	}

	#[Override]
	protected function tearDown(): void
	{
		FileSystem::delete($this->directory);
	}

	public function testReportsViolations(): void
	{
		$configurationFile = $this->writeConfiguration(self::CONFIGURATION);
		$tester = $this->createTester();

		$status = $tester->execute(['--configuration' => $configurationFile]);

		self::assertSame(Command::FAILURE, $status);
		self::assertStringContainsString('identifier.quoting', $tester->getDisplay());
		self::assertStringContainsString('[ERROR] Found 1 error in 1 entity, 1 fixable with --fix.', $tester->getDisplay());
	}

	public function testGeneratedBaselineIgnoresCurrentViolations(): void
	{
		$configurationFile = $this->writeConfiguration(self::CONFIGURATION);
		$tester = $this->createTester();

		$status = $tester->execute(['--configuration' => $configurationFile, '--generate-baseline' => true]);

		self::assertSame(Command::SUCCESS, $status, $tester->getDisplay());
		self::assertStringContainsString(
			'[OK] Baseline with 1 entry generated to build/baseline.neon.',
			$tester->getDisplay(),
		);
		$baseline = Neon::decodeFile(sprintf('%s/build/baseline.neon', $this->directory));
		Assert::isArray($baseline);
		Assert::isList($baseline['ignoreErrors']);
		Assert::isArray($baseline['ignoreErrors'][0]);
		self::assertSame('identifier.quoting', $baseline['ignoreErrors'][0]['rule']);
		self::assertSame(FixtureBasicEntity::class, $baseline['ignoreErrors'][0]['target']);

		$configurationFile = $this->writeConfiguration(sprintf(
			"includes:\n\t- build/baseline.neon\n\n%s",
			self::CONFIGURATION,
		));
		$status = $tester->execute(['--configuration' => $configurationFile]);

		self::assertSame(Command::SUCCESS, $status, $tester->getDisplay());
		self::assertStringContainsString('[OK] No errors found.', $tester->getDisplay());
	}

	public function testGenerateBaselineToExplicitFile(): void
	{
		$configurationFile = $this->writeConfiguration(self::CONFIGURATION);
		$baselineFile = sprintf('%s/custom.neon', $this->directory);

		$status = $this->createTester()->execute([
			'--configuration' => $configurationFile,
			'--generate-baseline' => $baselineFile,
		]);

		self::assertSame(Command::SUCCESS, $status);
		$baseline = Neon::decodeFile($baselineFile);
		Assert::isArray($baseline);
		Assert::isList($baseline['ignoreErrors']);
		self::assertCount(1, $baseline['ignoreErrors']);
	}

	public function testUnmatchedBaselineEntryIsReported(): void
	{
		$configurationFile = $this->writeConfiguration(<<<'NEON'
			ignoreErrors:
				-
					rule: table.name
					target: App\Missing
					path: app/Missing.php
					message: Table.name must be specified explicitly.
			NEON);
		$tester = $this->createTester();

		$status = $tester->execute(['--configuration' => $configurationFile]);

		self::assertSame(Command::FAILURE, $status);
		self::assertStringContainsString('[ERROR] Found 1 unmatched Doctrine attribute baseline entry.', $tester->getDisplay());
	}

	public function testUnmatchedDatabaseBaselineEntryIsIgnoredWithoutDatabase(): void
	{
		$configurationFile = $this->writeConfiguration(<<<'NEON'
			ignoreErrors:
				-
					rule: database.table-missing
					target: App\Missing
					path: app/Missing.php
					message: Missing table.
			NEON);
		$tester = $this->createTester();

		$status = $tester->execute(['--configuration' => $configurationFile]);

		self::assertSame(Command::SUCCESS, $status, $tester->getDisplay());
	}

	public function testGenerateBaselineCannotBeCombinedWithFix(): void
	{
		$configurationFile = $this->writeConfiguration(self::CONFIGURATION);
		$tester = $this->createTester();

		$status = $tester->execute([
			'--configuration' => $configurationFile,
			'--generate-baseline' => true,
			'--fix' => true,
		]);

		self::assertSame(Command::INVALID, $status);
		self::assertStringContainsString('cannot be combined', $tester->getDisplay());
	}

	private function writeConfiguration(string $content): string
	{
		$file = sprintf('%s/doctrine-attributes.neon', $this->directory);
		FileSystem::write($file, sprintf("%s\n%s", self::BASE_CONFIGURATION, $content));

		return $file;
	}

	public function testEntityManagerIsLoadedByConfiguredLoader(): void
	{
		self::$loadedEntityManager = $this->createEntityManager();
		FileSystem::write(
			sprintf('%s/build/entity-manager.php', $this->directory),
			sprintf("<?php\n\nreturn \\%s::%s;\n", self::class, '$loadedEntityManager'),
		);
		$configurationFile = $this->writeConfiguration(sprintf(
			"entityManagerLoader: build/entity-manager.php\n%s",
			self::CONFIGURATION,
		));
		$tester = new CommandTester(new CheckAttributesCommand());

		try {
			$status = $tester->execute(['--configuration' => $configurationFile]);
		} finally {
			self::$loadedEntityManager = null;
		}

		self::assertSame(Command::FAILURE, $status);
		self::assertStringContainsString('[ERROR] Found 1 error in 1 entity, 1 fixable with --fix.', $tester->getDisplay());
	}

	public function testEntityManagerLoaderIsRequiredWithoutEntityManager(): void
	{
		$configurationFile = $this->writeConfiguration(self::CONFIGURATION);

		$this->expectException(CheckerException::class);
		$this->expectExceptionMessage('Option entityManagerLoader must be configured');

		(new CommandTester(new CheckAttributesCommand()))->execute(['--configuration' => $configurationFile]);
	}

	private function createTester(): CommandTester
	{
		return new CommandTester(new CheckAttributesCommand($this->createEntityManager()));
	}

	private function createEntityManager(): EntityManagerInterface
	{
		$configuration = FixtureMetadata::createOrmConfiguration();
		$metadataByClassName = FixtureMetadata::load($configuration);

		$metadataFactory = self::createStub(ClassMetadataFactory::class);
		$metadataFactory->method('getAllMetadata')
			->willReturn(array_values($metadataByClassName));

		$entityManager = self::createStub(EntityManagerInterface::class);
		$entityManager->method('getConfiguration')
			->willReturn($configuration);
		$entityManager->method('getMetadataFactory')
			->willReturn($metadataFactory);
		$entityManager->method('getClassMetadata')
			->willReturnCallback(
				static fn (string $className): ClassMetadata => $metadataByClassName[$className],
			);

		return $entityManager;
	}

}
