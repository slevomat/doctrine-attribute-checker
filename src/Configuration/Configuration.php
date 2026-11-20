<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Configuration;

use Slevomat\DoctrineAttributeChecker\Baseline\Baseline;
use Slevomat\DoctrineAttributeChecker\Rules;
use function array_key_exists;
use function strtolower;

/**
 * @phpstan-import-type BaselineEntry from Baseline
 */
final class Configuration
{

	/** @var array<string, true> */
	private array $additionalReservedWordsIndex = [];

	/**
	 * @param string $rootDirectory Directory of the main configuration file; base for relative paths.
	 * @param list<string> $disabledRules
	 * @param list<string> $forbiddenAttributes
	 * @param array<string, array<string, list<string>>> $allowedLifecycleCallbacks Entity class => method => events.
	 * @param list<string> $columnArgumentOrder
	 * @param list<string> $columnOptionOrder
	 * @param list<string> $forbiddenColumnOptions
	 * @param list<string> $forbiddenColumnArguments
	 * @param list<string> $forbiddenTableArguments
	 * @param list<string> $cascadeOrder
	 * @param list<string> $forbiddenCacheArguments
	 * @param list<string> $additionalReservedWords
	 * @param list<BaselineEntry> $ignoreErrors
	 */
	public function __construct(
		public readonly string $rootDirectory,
		public readonly array $disabledRules,
		public readonly ?string $entityManagerLoader,
		public readonly ?string $baselineFile,
		public readonly array $forbiddenAttributes,
		public readonly array $allowedLifecycleCallbacks,
		public readonly bool $allowCustomRepositoryClass,
		public readonly array $columnArgumentOrder,
		public readonly array $columnOptionOrder,
		public readonly array $forbiddenColumnOptions,
		public readonly array $forbiddenColumnArguments,
		public readonly array $forbiddenTableArguments,
		public readonly array $cascadeOrder,
		public readonly array $forbiddenCacheArguments,
		public readonly array $additionalReservedWords,
		public readonly array $ignoreErrors,
	)
	{
		foreach ($additionalReservedWords as $word) {
			$this->additionalReservedWordsIndex[strtolower($word)] = true;
		}
	}

	public function isRuleEnabled(string $rule): bool
	{
		foreach ($this->disabledRules as $pattern) {
			if (Rules::matches($pattern, $rule)) {
				return false;
			}
		}

		return true;
	}

	public function isAdditionalReservedWord(string $identifier): bool
	{
		return array_key_exists(strtolower($identifier), $this->additionalReservedWordsIndex);
	}

	/**
	 * Lifecycle callback attribute $attributeName is allowed on the entity class itself ($methodName null)
	 * or on the given entity method.
	 */
	public function isLifecycleCallbackAllowed(string $className, ?string $methodName, string $attributeName): bool
	{
		$methods = $this->allowedLifecycleCallbacks[$className] ?? null;
		if ($methods === null) {
			return false;
		}

		if ($methodName === null) {
			return $attributeName === 'HasLifecycleCallbacks';
		}

		foreach ($methods[$methodName] ?? [] as $event) {
			if ($event === $attributeName) {
				return true;
			}
		}

		return false;
	}

}
