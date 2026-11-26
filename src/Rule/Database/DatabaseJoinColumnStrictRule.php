<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Rule\Database;

use Override;
use Slevomat\DoctrineAttributeChecker\Checker\Database\DatabaseExpectation;
use Slevomat\DoctrineAttributeChecker\Checker\Database\DatabaseExpectationApplier;
use Slevomat\DoctrineAttributeChecker\Checker\Database\DatabaseJoinColumnTarget;
use Slevomat\DoctrineAttributeChecker\Checker\Database\DatabaseTargetResolver;
use Slevomat\DoctrineAttributeChecker\Checker\DoctrineAttributeContext;
use Slevomat\DoctrineAttributeChecker\Checker\ViolationReporter;
use Slevomat\DoctrineAttributeChecker\Configuration\Configuration;
use Slevomat\DoctrineAttributeChecker\Database\DatabaseSchemaSnapshot;
use Slevomat\DoctrineAttributeChecker\Rule\AttributeRule;
use Slevomat\DoctrineAttributeChecker\Rule\StrictRule;
use function sprintf;

final class DatabaseJoinColumnStrictRule implements AttributeRule, StrictRule
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
			'database.join-column-allows-null',
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
		$tableName = $target->table;
		$columnName = $this->targetResolver->getJoinColumnName($context, $target);
		if ($columnName === null) {
			return null;
		}

		$databaseColumn = $this->databaseSchema->getColumn($tableName, $columnName);
		if ($databaseColumn === null) {
			return null;
		}

		$joinColumnMapping = $this->targetResolver->getJoinColumnMapping($target, $columnName);
		if (
			!($joinColumnMapping->nullable ?? true)
			&& $databaseColumn->nullable
			&& !$target->singleTableSubclass
		) {
			$reporter->addViolation(
				$context,
				'database.join-column-allows-null',
				'Doctrine join-column nullability is not nullable but the database column is nullable.',
			);
		}

		$databaseUnique = $this->databaseSchema->isSingleColumnUnique($tableName, $columnName);
		if ($target->oneToOne) {
			if (!$databaseUnique && !$this->databaseSchema->isSingleColumnPrimaryKey($tableName, $columnName)) {
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

}
