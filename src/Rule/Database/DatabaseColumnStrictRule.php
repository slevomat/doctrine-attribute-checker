<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Rule\Database;

use Override;
use Slevomat\DoctrineAttributeChecker\Checker\Database\DatabaseColumnComparator;
use Slevomat\DoctrineAttributeChecker\Checker\Database\DatabaseExpectationApplier;
use Slevomat\DoctrineAttributeChecker\Checker\Database\DatabaseTargetResolver;
use Slevomat\DoctrineAttributeChecker\Checker\DoctrineAttributeContext;
use Slevomat\DoctrineAttributeChecker\Checker\ViolationReporter;
use Slevomat\DoctrineAttributeChecker\Configuration\Configuration;
use Slevomat\DoctrineAttributeChecker\Rule\AttributeRule;
use Slevomat\DoctrineAttributeChecker\Rule\StrictRule;
use function array_push;

final class DatabaseColumnStrictRule implements AttributeRule, StrictRule
{

	public function __construct(
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
			'database.column-precision',
			'database.column-scale',
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

		$expectations = [];
		foreach ($this->targetResolver->getFieldTargets($context->classMetadata, $context->property->name) as $target) {
			array_push(
				$expectations,
				...$this->comparator->getExpectations(
					$context,
					$reporter,
					$target->table,
					$target->fieldMapping,
					$target->nullableColumnAllowed,
				),
			);
		}

		$this->expectationApplier->apply($context, $reporter, $expectations);
	}

}
