<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Configuration;

use Nette\Neon\Neon;
use Nette\Schema\Expect;
use Nette\Schema\Processor;
use Nette\Schema\Schema;
use Nette\Schema\ValidationException;
use Slevomat\DoctrineAttributeChecker\Baseline\Baseline;
use Slevomat\DoctrineAttributeChecker\CheckerException;
use Slevomat\DoctrineAttributeChecker\Rules;
use function array_is_list;
use function array_key_exists;
use function array_merge;
use function dirname;
use function getcwd;
use function implode;
use function in_array;
use function is_array;
use function is_file;
use function is_string;
use function realpath;
use function sprintf;
use function str_starts_with;
use const DIRECTORY_SEPARATOR;

/**
 * Loads the checker configuration from a NEON file merged on top of config/default.neon.
 *
 * @phpcsSuppress SlevomatCodingStandard.TypeHints.DisallowMixedTypeHint.DisallowedMixedTypeHint
 *
 * @phpstan-type ConfigurationData array{
 *     rules: object{disabled: list<string>},
 *     entityManagerLoader: ?string,
 *     baseline: ?string,
 *     attributes: object{
 *         forbidden: list<string>,
 *         allowedLifecycleCallbacks: array<string, array<string, list<string>>>,
 *         allowCustomRepositoryClass: bool,
 *     },
 *     column: object{
 *         argumentOrder: list<string>,
 *         optionOrder: list<string>,
 *         forbiddenOptions: list<string>,
 *         forbiddenArguments: list<string>,
 *     },
 *     table: object{forbiddenArguments: list<string>},
 *     association: object{cascadeOrder: list<string>},
 *     cache: object{forbiddenArguments: list<string>},
 *     identifiers: object{additionalReservedWords: list<string>},
 *     ignoreErrors: list<BaselineEntry>,
 * }
 * @phpstan-type RawConfiguration array<array-key, mixed>
 * @phpstan-import-type BaselineEntry from Baseline
 */
final class ConfigurationLoader
{

	public const string DEFAULT_CONFIGURATION_FILE = 'doctrine-attributes.neon';

	private const string INCLUDES_KEY = 'includes';

	private const string IGNORE_ERRORS_KEY = 'ignoreErrors';

	/**
	 * Loads the given configuration file. Without a file, doctrine-attributes.neon from the current working
	 * directory is used when it exists, otherwise only the defaults are used.
	 */
	public function load(?string $file = null): Configuration
	{
		$currentDirectory = getcwd();
		if ($currentDirectory === false) {
			throw new CheckerException('Cannot determine the current working directory.');
		}

		if ($file === null) {
			$defaultFile = $currentDirectory . DIRECTORY_SEPARATOR . self::DEFAULT_CONFIGURATION_FILE;
			if (!is_file($defaultFile)) {
				return $this->create($this->readFile(self::getDefaultsFile(), []), $currentDirectory);
			}

			$file = $defaultFile;
		}

		$canonicalFile = realpath($file);
		if ($canonicalFile === false || !is_file($canonicalFile)) {
			throw new CheckerException(sprintf('Configuration file %s does not exist.', $file));
		}

		$data = $this->merge(
			$this->readFile(self::getDefaultsFile(), []),
			$this->readFile($canonicalFile, []),
		);

		return $this->create($data, dirname($canonicalFile));
	}

	public static function getDefaultsFile(): string
	{
		return dirname(__DIR__, 2) . '/config/default.neon';
	}

	/**
	 * @param list<string> $stack Files being loaded, used to detect circular includes.
	 * @return RawConfiguration
	 */
	private function readFile(string $file, array $stack): array
	{
		if (in_array($file, $stack, true)) {
			throw new CheckerException(sprintf('Circular include of configuration file %s.', $file));
		}

		$decoded = Neon::decodeFile($file);
		if ($decoded === null) {
			return [];
		}

		if (!is_array($decoded)) {
			throw new CheckerException(sprintf('Configuration file %s must contain a NEON mapping.', $file));
		}

		$includes = $decoded[self::INCLUDES_KEY] ?? [];
		unset($decoded[self::INCLUDES_KEY]);
		if (!is_array($includes) || !array_is_list($includes)) {
			throw new CheckerException(sprintf('Includes in configuration file %s must be a list.', $file));
		}

		$data = [];
		$stack[] = $file;
		foreach ($includes as $include) {
			if (!is_string($include)) {
				throw new CheckerException(sprintf('Includes in configuration file %s must be file paths.', $file));
			}

			$includedFile = $this->resolvePath(dirname($file), $include);
			$canonicalIncludedFile = realpath($includedFile);
			if ($canonicalIncludedFile === false || !is_file($canonicalIncludedFile)) {
				throw new CheckerException(sprintf('Configuration file %s included from %s does not exist.', $include, $file));
			}

			$data = $this->merge($data, $this->readFile($canonicalIncludedFile, $stack));
		}

		return $this->merge($data, $decoded);
	}

	/**
	 * Nested mappings are merged, lists and scalars are replaced; ignoreErrors lists are concatenated.
	 *
	 * @param RawConfiguration $base
	 * @param RawConfiguration $override
	 * @return RawConfiguration
	 */
	private function merge(array $base, array $override): array
	{
		foreach ($override as $key => $value) {
			if (
				$key === self::IGNORE_ERRORS_KEY
				&& is_array($value)
				&& is_array($base[$key] ?? null)
			) {
				$base[$key] = array_merge($base[$key], $value);

				continue;
			}

			if (
				is_array($value)
				&& !array_is_list($value)
				&& array_key_exists($key, $base)
				&& is_array($base[$key])
				&& !array_is_list($base[$key])
			) {
				$base[$key] = $this->merge($base[$key], $value);

				continue;
			}

			$base[$key] = $value;
		}

		return $base;
	}

	/**
	 * @param RawConfiguration $data
	 */
	private function create(array $data, string $rootDirectory): Configuration
	{
		try {
			/** @var ConfigurationData $normalized */
			$normalized = (new Processor())->process($this->getSchema(), $data);
		} catch (ValidationException $e) {
			throw new CheckerException(
				sprintf('Invalid Doctrine attribute checker configuration: %s', implode(' ', $e->getMessages())),
				$e,
			);
		}

		$baselineFile = $normalized['baseline'] === null
			? null
			: $this->resolvePath($rootDirectory, $normalized['baseline']);

		$entityManagerLoader = $normalized['entityManagerLoader'] === null
			? null
			: $this->resolvePath($rootDirectory, $normalized['entityManagerLoader']);

		$configuration = new Configuration(
			$rootDirectory,
			$normalized['rules']->disabled,
			$entityManagerLoader,
			$baselineFile,
			$normalized['attributes']->forbidden,
			$normalized['attributes']->allowedLifecycleCallbacks,
			$normalized['attributes']->allowCustomRepositoryClass,
			$normalized['column']->argumentOrder,
			$normalized['column']->optionOrder,
			$normalized['column']->forbiddenOptions,
			$normalized['column']->forbiddenArguments,
			$normalized['table']->forbiddenArguments,
			$normalized['association']->cascadeOrder,
			$normalized['cache']->forbiddenArguments,
			$normalized['identifiers']->additionalReservedWords,
			$normalized['ignoreErrors'],
		);

		$this->validateRules($configuration);

		return $configuration;
	}

	private function validateRules(Configuration $configuration): void
	{
		$knownRules = Rules::getAll($configuration);
		foreach ($configuration->disabledRules as $pattern) {
			$matched = false;
			foreach ($knownRules as $rule) {
				if (Rules::matches($pattern, $rule)) {
					$matched = true;
					break;
				}
			}

			if (!$matched) {
				throw new CheckerException(sprintf('Disabled rule %s does not match any known rule.', $pattern));
			}
		}
	}

	private function getSchema(): Schema
	{
		$stringList = static fn (): Schema => Expect::listOf(Expect::string()->min(1));

		return Expect::structure([
			'rules' => Expect::structure([
				'disabled' => $stringList(),
			]),
			'entityManagerLoader' => Expect::string()->nullable(),
			'baseline' => Expect::string()->nullable(),
			'attributes' => Expect::structure([
				'forbidden' => $stringList(),
				'allowedLifecycleCallbacks' => Expect::arrayOf(
					Expect::arrayOf($stringList(), Expect::string()),
					Expect::string(),
				),
				'allowCustomRepositoryClass' => Expect::bool(false),
			]),
			'column' => Expect::structure([
				'argumentOrder' => $stringList(),
				'optionOrder' => $stringList(),
				'forbiddenOptions' => $stringList(),
				'forbiddenArguments' => $stringList(),
			]),
			'table' => Expect::structure([
				'forbiddenArguments' => $stringList(),
			]),
			'association' => Expect::structure([
				'cascadeOrder' => $stringList(),
			]),
			'cache' => Expect::structure([
				'forbiddenArguments' => $stringList(),
			]),
			'identifiers' => Expect::structure([
				'additionalReservedWords' => $stringList(),
			]),
			'ignoreErrors' => Expect::listOf(Expect::structure([
				'rule' => Expect::string()->required()->min(1),
				'target' => Expect::string()->required()->min(1),
				'path' => Expect::string()->required()->min(1),
				'message' => Expect::string()->required()->min(1),
			])->castTo('array')),
		])->castTo('array');
	}

	private function resolvePath(string $directory, string $path): string
	{
		if (str_starts_with($path, '/')) {
			return $path;
		}

		return $directory . DIRECTORY_SEPARATOR . $path;
	}

}
