<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Checker;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\Mapping\FieldMapping;
use Slevomat\DoctrineAttributeChecker\Database\DatabaseColumn;
use function in_array;
use function preg_match;
use function strtolower;

final class DbalTypeCompatibility
{

	private const array INTEGER_TYPES = ['bigint', 'int', 'integer', 'mediumint', 'smallint', 'tinyint', 'year'];

	private const array FRACTIONAL_TYPES = ['decimal', 'double', 'float', 'numeric', 'real'];

	private const array CHARACTER_TYPES = [
		'char',
		'enum',
		'json',
		'longtext',
		'mediumtext',
		'set',
		'text',
		'tinytext',
		'varchar',
	];

	private const array BINARY_TYPES = ['binary', 'blob', 'longblob', 'mediumblob', 'tinyblob', 'varbinary'];

	private const array TEMPORAL_TYPES = ['date', 'datetime', 'timestamp'];

	private const array TIME_TYPES = ['time'];

	public static function isCompatible(
		FieldMapping $fieldMapping,
		DatabaseColumn $databaseColumn,
		Connection $connection,
	): bool
	{
		$declaration = $fieldMapping->columnDefinition;
		if ($declaration === null) {
			$platform = $connection->getDatabasePlatform();
			$type = $connection->getConfiguration()->getTypeProvider()->get($fieldMapping->type);
			// Only the SQL type name is compared, so omitted dimensions need minimal valid placeholders.
			$declarationOptions = ['name' => $fieldMapping->columnName] + ($fieldMapping->options ?? []) + [
				'length' => $fieldMapping->length ?? 1,
				'precision' => $fieldMapping->precision ?? 1,
				'scale' => $fieldMapping->scale ?? 0,
			];
			$declaration = $type->getSQLDeclaration($declarationOptions, $platform);
		}

		if (preg_match('~^\s*([a-z]+)~i', $declaration, $matches) !== 1) {
			return false;
		}

		$expectedType = strtolower($matches[1]);
		$actualType = $databaseColumn->dataType;
		if ($expectedType === $actualType) {
			return true;
		}

		if (
			in_array($expectedType, self::INTEGER_TYPES, true)
			&& in_array($actualType, self::INTEGER_TYPES, true)
		) {
			return true;
		}

		if (
			in_array($expectedType, self::FRACTIONAL_TYPES, true)
			&& (
				in_array($actualType, self::FRACTIONAL_TYPES, true)
				|| in_array($actualType, self::INTEGER_TYPES, true)
			)
		) {
			return true;
		}

		if (
			in_array($expectedType, self::CHARACTER_TYPES, true)
			&& in_array($actualType, self::CHARACTER_TYPES, true)
		) {
			return true;
		}

		if (
			in_array($expectedType, self::BINARY_TYPES, true)
			&& in_array($actualType, self::BINARY_TYPES, true)
		) {
			return true;
		}

		if (
			in_array($expectedType, self::CHARACTER_TYPES, true)
			&& (
				in_array($actualType, self::BINARY_TYPES, true)
				|| in_array($actualType, self::TEMPORAL_TYPES, true)
				|| in_array($actualType, self::TIME_TYPES, true)
				|| $actualType === 'year'
			)
		) {
			return true;
		}

		return in_array($expectedType, self::TEMPORAL_TYPES, true)
			&& in_array($actualType, self::TEMPORAL_TYPES, true);
	}

}
