<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker;

use Slevomat\DoctrineAttributeChecker\Checker\JoinTableNormalizer;
use Slevomat\DoctrineAttributeChecker\Configuration\Configuration;
use Slevomat\DoctrineAttributeChecker\Rule\RuleSetFactory;
use function array_push;
use function array_unique;
use function array_values;
use function sort;
use function str_ends_with;
use function str_starts_with;
use function substr;

/**
 * Identifiers of all rules reported by the checker.
 */
final class Rules
{

	public const string DATABASE_RULE_PREFIX = 'database.';

	/**
	 * @return list<string>
	 */
	public static function getAll(Configuration $configuration): array
	{
		$rules = JoinTableNormalizer::RULE_IDENTIFIERS;
		foreach (RuleSetFactory::RULE_CLASSES as $ruleClass) {
			array_push($rules, ...$ruleClass::getIdentifiers($configuration));
		}

		$rules = array_values(array_unique($rules));
		sort($rules);

		return $rules;
	}

	public static function matches(string $pattern, string $rule): bool
	{
		if (str_ends_with($pattern, '*')) {
			return str_starts_with($rule, substr($pattern, 0, -1));
		}

		return $pattern === $rule;
	}

	public static function isDatabaseRule(string $rule): bool
	{
		return str_starts_with($rule, self::DATABASE_RULE_PREFIX);
	}

}
