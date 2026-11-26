<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Rule\Database;

use Override;
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
use function sprintf;

final class DatabaseJoinColumnRule implements AttributeRule
{

	public function __construct(
		private DatabaseSchemaSnapshot $databaseSchema,
		private DatabaseTargetResolver $targetResolver,
	)
	{
	}

	#[Override]
	public static function getIdentifiers(Configuration $configuration): array
	{
		return [
			'database.join-column-foreign-key-target',
			'database.join-column-missing',
			'database.join-column-rejects-null',
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

		foreach ($this->targetResolver->getJoinColumnTargets($context) as $target) {
			$this->checkTarget($context, $reporter, $target);
		}
	}

	private function checkTarget(
		DoctrineAttributeContext $context,
		ViolationReporter $reporter,
		DatabaseJoinColumnTarget $target,
	): void
	{
		$tableName = $target->table;
		$columnName = $this->targetResolver->getJoinColumnName($context, $target);
		if ($columnName === null) {
			return;
		}

		$joinColumnMapping = $this->targetResolver->getJoinColumnMapping($target, $columnName);
		$databaseColumn = $this->databaseSchema->getColumn($tableName, $columnName);
		if ($databaseColumn === null) {
			$reporter->addViolation(
				$context,
				'database.join-column-missing',
				sprintf(
					'Join column %s.%s does not exist in database %s.',
					$tableName,
					$columnName,
					$this->databaseSchema->getSchema(),
				),
			);

			return;
		}

		if (($joinColumnMapping->nullable ?? true) && !$databaseColumn->nullable) {
			$reporter->addViolation(
				$context,
				'database.join-column-rejects-null',
				'Doctrine join-column nullability is nullable but the database column is not nullable.',
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
