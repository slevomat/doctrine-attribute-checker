<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker;

use Slevomat\DoctrineAttributeChecker\Configuration\Configuration;
use function array_unique;
use function array_values;
use function sort;
use function sprintf;
use function str_ends_with;
use function str_starts_with;
use function substr;

/**
 * Identifiers of all rules reported by the checker.
 */
final class Rules
{

	public const array STATIC_RULES = [
		'column.argument-order',
		'column.empty-parentheses',
		'column.enum-type',
		'column.nullable',
		'column.options.order',
		'column.type',
		'column.type-json',
		'column.unique',
		'database.column-collation',
		'database.column-definition',
		'database.column-fixed',
		'database.column-length',
		'database.column-missing',
		'database.column-nullability',
		'database.column-precision',
		'database.column-scale',
		'database.column-type',
		'database.column-unique',
		'database.column-unsigned',
		'database.join-column-foreign-key-target',
		'database.join-column-missing',
		'database.join-column-nullability',
		'database.join-column-unique',
		'database.join-table-missing',
		'database.one-to-one-unique',
		'database.table-missing',
		'embedded.class',
		'entity.repository-class',
		'forbidden.attribute',
		'forbidden.lifecycle-callback',
		'generated-value.strategy',
		'identifier.default-name',
		'identifier.quoting',
		'identifier.reserved',
		'join-column.empty',
		'join-column.name',
		'join-column.nullable',
		'join-column.on-delete',
		'join-column.referenced-column-name',
		'join-column.unique',
		'join-table.attribute-missing',
		'join-table.column-class',
		'join-table.duplicate-column',
		'join-table.name',
		'join-table.nested-column',
		'manytomany.cascade-order',
		'manytomany.cascade-values',
		'manytomany.fetch',
		'manytomany.orphan-removal',
		'manytoone.cascade-order',
		'manytoone.cascade-values',
		'manytoone.fetch',
		'manytoone.target-entity',
		'onetomany.cascade-order',
		'onetomany.cascade-values',
		'onetomany.fetch',
		'onetomany.orphan-removal',
		'onetoone.cascade-order',
		'onetoone.cascade-values',
		'onetoone.fetch',
		'onetoone.orphan-removal',
		'onetoone.target-entity',
		'table.attribute-missing',
		'table.name',
		'table.unique-constraints',
	];

	public const string DATABASE_RULE_PREFIX = 'database.';

	/**
	 * @return list<string>
	 */
	public static function getAll(Configuration $configuration): array
	{
		$rules = self::STATIC_RULES;
		foreach ($configuration->forbiddenColumnOptions as $optionName) {
			$rules[] = sprintf('column.options.%s', $optionName);
		}

		foreach ($configuration->forbiddenColumnArguments as $argumentName) {
			$rules[] = sprintf('column.%s', $argumentName);
		}

		foreach ($configuration->forbiddenTableArguments as $argumentName) {
			$rules[] = sprintf('table.%s', $argumentName);
		}

		foreach ($configuration->forbiddenCacheArguments as $argumentName) {
			$rules[] = sprintf('cache.%s', $argumentName);
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
