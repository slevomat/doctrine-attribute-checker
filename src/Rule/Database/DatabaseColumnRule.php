<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Rule\Database;

use Override;
use Slevomat\DoctrineAttributeChecker\Checker\Database\DatabaseColumnComparator;
use Slevomat\DoctrineAttributeChecker\Checker\Database\DatabaseExpectationApplier;
use Slevomat\DoctrineAttributeChecker\Checker\Database\DatabaseTargetResolver;
use Slevomat\DoctrineAttributeChecker\Checker\DoctrineAttributeContext;
use Slevomat\DoctrineAttributeChecker\Checker\ViolationReporter;
use Slevomat\DoctrineAttributeChecker\Configuration\Configuration;
use Slevomat\DoctrineAttributeChecker\Database\DatabaseSchemaSnapshot;
use Slevomat\DoctrineAttributeChecker\Rule\AttributeRule;
use function array_push;

final class DatabaseColumnRule implements AttributeRule
{

	public function __construct(
		private DatabaseSchemaSnapshot $databaseSchema,
		private DatabaseTargetResolver $targetResolver,
		private DatabaseColumnComparator $comparator,
		private DatabaseExpectationApplier $expectationApplier,
	)
	{
	}

	#[Override]
	public static function getIdentifiers(Configuration $configuration): array
	{
		return [
			'database.column-allows-null',
			'database.column-collation',
			'database.column-definition',
			'database.column-fixed',
			'database.column-length',
			'database.column-missing',
			'database.column-precision',
			'database.column-rejects-null',
			'database.column-scale',
			'database.column-type',
			'database.column-unique',
			'database.column-unsigned',
		];
	}

	#[Override]
	public function getAttributeNames(): array
	{
		return ['Column'];
	}

	#[Override]
	public function process(DoctrineAttributeContext $context, ViolationReporter $reporter): void
	{
		if ($context->property === null || $context->classMetadata === null) {
			return;
		}

		$targets = $this->targetResolver->getFieldTargets($context->classMetadata, $context->property->name);
		$nullableInEmbeddingTable = false;
		if ($context->classMetadata->isEmbeddedClass) {
			foreach ($targets as $target) {
				if ($this->databaseSchema->getColumn($target->table, $target->fieldMapping->columnName)?->nullable === true) {
					$nullableInEmbeddingTable = true;
					break;
				}
			}
		}

		$expectations = [];
		foreach ($targets as $target) {
			array_push(
				$expectations,
				...$this->comparator->compare(
					$context,
					$reporter,
					$target->table,
					$target->fieldMapping,
					$target->nullableColumnAllowed,
					nullableInEmbeddingTable: $nullableInEmbeddingTable,
				),
			);
		}

		$this->expectationApplier->apply($context, $reporter, $expectations);
	}

}
