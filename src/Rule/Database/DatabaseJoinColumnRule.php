<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Rule\Database;

use Override;
use Slevomat\DoctrineAttributeChecker\Checker\Database\DatabaseExpectation;
use Slevomat\DoctrineAttributeChecker\Checker\Database\DatabaseExpectationApplier;
use Slevomat\DoctrineAttributeChecker\Checker\Database\DatabaseJoinColumnTarget;
use Slevomat\DoctrineAttributeChecker\Checker\Database\DatabaseTargetResolver;
use Slevomat\DoctrineAttributeChecker\Checker\DoctrineAttributeContext;
use Slevomat\DoctrineAttributeChecker\Checker\Identifiers;
use Slevomat\DoctrineAttributeChecker\Checker\ViolationReporter;
use Slevomat\DoctrineAttributeChecker\Configuration\Configuration;
use Slevomat\DoctrineAttributeChecker\Database\DatabaseForeignKey;
use Slevomat\DoctrineAttributeChecker\Database\DatabaseSchemaSnapshot;
use Slevomat\DoctrineAttributeChecker\Rule\AttributeRule;
use function array_map;
use function implode;
use function is_string;
use function sprintf;

final class DatabaseJoinColumnRule implements AttributeRule
{

	public function __construct(
		private DatabaseSchemaSnapshot $databaseSchema,
		private DatabaseTargetResolver $targetResolver,
		private DatabaseExpectationApplier $expectationApplier,
	)
	{
	}

	#[Override]
	public static function getIdentifiers(Configuration $configuration): array
	{
		return [
			'database.join-column-foreign-key-target',
			'database.join-column-allows-null',
			'database.join-column-missing',
			'database.join-column-rejects-null',
			'database.join-column-unique',
			'database.one-to-one-unique',
		];
	}

	#[Override]
	public function getAttributeNames(): array
	{
		return ['JoinColumn', 'InverseJoinColumn'];
	}

	#[Override]
	public function process(DoctrineAttributeContext $context, ViolationReporter $reporter): void
	{
		if ($context->classMetadata === null) {
			return;
		}

		$expectations = [];
		foreach ($this->targetResolver->getJoinColumnTargets($context) as $target) {
			$expectation = $this->checkTarget($context, $reporter, $target);
			if ($expectation === null) {
				continue;
			}

			$expectations[] = $expectation;
		}

		$this->expectationApplier->apply($context, $reporter, $expectations);
	}

	private function checkTarget(
		DoctrineAttributeContext $context,
		ViolationReporter $reporter,
		DatabaseJoinColumnTarget $target,
	): ?DatabaseExpectation
	{
		$databaseSchema = $this->databaseSchema;
		$tableName = $target->table;
		$joinColumnMapping = $target->joinColumn;
		$columnName = $context->arguments['name'] ?? $joinColumnMapping->name;
		if (!is_string($columnName)) {
			return null;
		}

		if ($target->association !== null) {
			foreach ($target->association->joinColumns as $associationJoinColumn) {
				if ($associationJoinColumn->name !== $columnName) {
					continue;
				}

				$joinColumnMapping = $associationJoinColumn;

				break;
			}
		}

		$databaseColumn = $databaseSchema->getColumn($tableName, $columnName);
		if ($databaseColumn === null) {
			$reporter->addViolation(
				$context,
				'database.join-column-missing',
				sprintf('Join column %s.%s does not exist in database %s.', $tableName, $columnName, $databaseSchema->getSchema()),
			);

			return null;
		}

		$expectedNullable = $joinColumnMapping->nullable ?? true;
		if ($expectedNullable && !$databaseColumn->nullable) {
			$reporter->addViolation(
				$context,
				'database.join-column-rejects-null',
				'Doctrine join-column nullability is nullable but the database column is not nullable.',
			);
		}

		if (!$expectedNullable && $databaseColumn->nullable && !$target->singleTableSubclass) {
			$reporter->addViolation(
				$context,
				'database.join-column-allows-null',
				'Doctrine join-column nullability is not nullable but the database column is nullable.',
			);
		}

		$this->checkForeignKey(
			$context,
			$reporter,
			$tableName,
			$columnName,
			$target->referencedTable,
			$joinColumnMapping->referencedColumnName,
		);

		$databaseUnique = $databaseSchema->isSingleColumnUnique($tableName, $columnName);
		$databasePrimaryKey = $databaseSchema->isSingleColumnPrimaryKey($tableName, $columnName);
		if ($target->oneToOne) {
			if (!$databaseUnique && !$databasePrimaryKey) {
				$reporter->addViolation(
					$context,
					'database.one-to-one-unique',
					'An owning OneToOne join column must have a single-column unique index in the database.',
				);
			}

			return null;
		}

		return new DatabaseExpectation(
			false,
			'unique',
			$databaseUnique ? true : null,
			'database.join-column-unique',
			'JoinColumn.unique must match the single-column unique index in the database.',
			sprintf('%s.%s', $tableName, $columnName),
		);
	}

	private function checkForeignKey(
		DoctrineAttributeContext $context,
		ViolationReporter $reporter,
		string $tableName,
		string $columnName,
		string $referencedTableName,
		string $referencedColumnName,
	): void
	{
		$foreignKeys = $this->databaseSchema->getForeignKeys($tableName, $columnName);
		if ($foreignKeys === []) {
			return;
		}

		$expectedReferencedTableName = Identifiers::unquote($referencedTableName);
		$expectedReferencedColumnName = Identifiers::unquote($referencedColumnName);
		foreach ($foreignKeys as $foreignKey) {
			if ($this->databaseSchema->areTargetsEquivalentViaPrimaryKeyForeignKeys(
				$foreignKey->referencedTable,
				$foreignKey->referencedColumn,
				$expectedReferencedTableName,
				$expectedReferencedColumnName,
			)) {
				return;
			}
		}

		$actualTargets = array_map(
			static fn (DatabaseForeignKey $foreignKey): string => sprintf(
				'%s.%s',
				$foreignKey->referencedTable,
				$foreignKey->referencedColumn,
			),
			$foreignKeys,
		);
		$reporter->addViolation(
			$context,
			'database.join-column-foreign-key-target',
			sprintf(
				'Join column %s.%s references %s, expected %s.%s.',
				$tableName,
				$columnName,
				implode(', ', $actualTargets),
				$expectedReferencedTableName,
				$expectedReferencedColumnName,
			),
		);
	}

}
