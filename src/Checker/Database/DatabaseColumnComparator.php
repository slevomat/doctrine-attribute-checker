<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Checker\Database;

use Doctrine\ORM\Mapping\FieldMapping;
use ReflectionNamedType;
use Slevomat\DoctrineAttributeChecker\Checker\DbalTypeCompatibility;
use Slevomat\DoctrineAttributeChecker\Checker\DoctrineAttributeContext;
use Slevomat\DoctrineAttributeChecker\Checker\DoctrineMapping;
use Slevomat\DoctrineAttributeChecker\Checker\ViolationReporter;
use Slevomat\DoctrineAttributeChecker\Database\DatabaseSchemaSnapshot;
use function in_array;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function strtolower;

final class DatabaseColumnComparator
{

	public const array LENGTH_DATABASE_TYPES = [
		'binary',
		'blob',
		'char',
		'longblob',
		'longtext',
		'mediumblob',
		'mediumtext',
		'text',
		'tinyblob',
		'tinytext',
		'varbinary',
		'varchar',
	];

	private const array NUMERIC_DATABASE_TYPES = [
		'bigint',
		'decimal',
		'double',
		'float',
		'int',
		'integer',
		'mediumint',
		'numeric',
		'real',
		'smallint',
		'tinyint',
		'year',
	];

	public function __construct(
		private DoctrineMapping $mapping,
		private DatabaseSchemaSnapshot $databaseSchema,
	)
	{
	}

	/**
	 * @return list<DatabaseExpectation>
	 */
	public function compare(
		DoctrineAttributeContext $context,
		ViolationReporter $reporter,
		string $tableName,
		FieldMapping $fieldMapping,
		bool $nullableColumnAllowed,
		string $attributeName = 'Column',
		bool $nullableInEmbeddingTable = false,
	): array
	{
		$databaseSchema = $this->databaseSchema;
		$columnName = $fieldMapping->columnName;
		$databaseColumn = $databaseSchema->getColumn($tableName, $columnName);
		if ($databaseColumn === null) {
			$reporter->addViolation(
				$context,
				'database.column-missing',
				sprintf('Column %s.%s does not exist in database %s.', $tableName, $columnName, $databaseSchema->getSchema()),
			);

			return [];
		}

		if (!DbalTypeCompatibility::isCompatible($fieldMapping, $databaseColumn, $this->mapping->getConnection())) {
			$reporter->addViolation(
				$context,
				'database.column-type',
				sprintf(
					'Doctrine type %s is incompatible with database type %s.',
					$fieldMapping->type,
					$databaseColumn->columnType,
				),
			);
		}

		if (
			$fieldMapping->nullable !== null
			&& $databaseColumn->nullable !== $fieldMapping->nullable
			&& !(
				$nullableColumnAllowed
				&& $databaseColumn->nullable
			)
			&& !(
				$nullableInEmbeddingTable
				&& $fieldMapping->nullable
			)
		) {
			$reporter->addViolation(
				$context,
				'database.column-nullability',
				sprintf(
					'PHP/Doctrine nullability is %s but the database column is %s.',
					$fieldMapping->nullable ? 'nullable' : 'not nullable',
					$databaseColumn->nullable ? 'nullable' : 'not nullable',
				),
			);
		}

		$column = sprintf('%s.%s', $tableName, $columnName);
		$expectations = [];
		$expectations[] = new DatabaseExpectation(
			false,
			'unique',
			$databaseSchema->isSingleColumnUnique($tableName, $columnName) ? true : null,
			'database.column-unique',
			$attributeName . '.unique must match the single-column unique index in the database.',
			$column,
		);

		$isUuidType = str_starts_with(strtolower($fieldMapping->type), 'uuid');
		if (!$isUuidType && in_array($databaseColumn->dataType, self::LENGTH_DATABASE_TYPES, true)) {
			if ($databaseColumn->characterMaximumLength === null) {
				$reporter->addViolation(
					$context,
					'database.column-length',
					sprintf('The database length for %s.%s cannot be determined.', $tableName, $columnName),
				);
			} else {
				$expectations[] = new DatabaseExpectation(
					false,
					'length',
					$databaseColumn->characterMaximumLength,
					'database.column-length',
					$attributeName . '.length must match the database column length.',
					$column,
				);
			}
		} else {
			$expectations[] = new DatabaseExpectation(
				false,
				'length',
				null,
				'database.column-length',
				$attributeName . '.length is not applicable to this Doctrine/database type.',
				$column,
			);
		}

		if (in_array($databaseColumn->dataType, ['decimal', 'numeric'], true)) {
			if ($databaseColumn->numericPrecision === null || $databaseColumn->numericScale === null) {
				$reporter->addViolation(
					$context,
					'database.column-precision',
					sprintf('The decimal precision and scale for %s.%s cannot be determined.', $tableName, $columnName),
				);
			} else {
				$expectations[] = new DatabaseExpectation(
					false,
					'precision',
					$databaseColumn->numericPrecision,
					'database.column-precision',
					$attributeName . '.precision must match the database column precision.',
					$column,
				);
				$expectations[] = new DatabaseExpectation(
					false,
					'scale',
					$databaseColumn->numericScale,
					'database.column-scale',
					$attributeName . '.scale must match the database column scale.',
					$column,
				);
			}
		} else {
			foreach (['precision', 'scale'] as $argumentName) {
				$expectations[] = new DatabaseExpectation(
					false,
					$argumentName,
					null,
					'database.column-' . $argumentName,
					sprintf('%s.%s is not applicable to this Doctrine/database type.', $attributeName, $argumentName),
					$column,
				);
			}
		}

		$propertyType = $context->property?->getType();
		$isBooleanProperty = $propertyType instanceof ReflectionNamedType && $propertyType->getName() === 'bool';
		$isUnsigned = !$isBooleanProperty
			&& in_array($databaseColumn->dataType, self::NUMERIC_DATABASE_TYPES, true)
			&& str_contains($databaseColumn->columnType, 'unsigned');
		$expectations[] = new DatabaseExpectation(
			true,
			'unsigned',
			$isUnsigned ? true : null,
			'database.column-unsigned',
			$attributeName . '.options.unsigned must match the database column.',
			$column,
		);
		$expectations[] = new DatabaseExpectation(
			true,
			'fixed',
			$databaseColumn->dataType === 'char' ? true : null,
			'database.column-fixed',
			$attributeName . '.options.fixed must be true exactly for a database char column.',
			$column,
		);

		$tableCollation = $databaseSchema->getTableCollation($tableName);
		$columnCollation = $databaseColumn->collation;
		$expectations[] = new DatabaseExpectation(
			true,
			'collation',
			$columnCollation !== null && $columnCollation !== $tableCollation ? $columnCollation : null,
			'database.column-collation',
			$attributeName . '.options.collation must match a column-specific database collation.',
			$column,
		);

		$expectations[] = $databaseColumn->dataType === 'year'
			? new DatabaseExpectation(
				false,
				'columnDefinition',
				'YEAR',
				'database.column-definition',
				$attributeName . '.columnDefinition must be YEAR for a database year column.',
				$column,
			)
			: new DatabaseExpectation(
				false,
				'columnDefinition',
				null,
				'database.column-definition',
				$attributeName . '.columnDefinition is allowed only for a database year column.',
				$column,
			);

		return $expectations;
	}

}
