<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Rule\Database;

use Override;
use Slevomat\DoctrineAttributeChecker\Checker\Database\DatabaseColumnComparator;
use Slevomat\DoctrineAttributeChecker\Checker\Database\DatabaseExpectation;
use Slevomat\DoctrineAttributeChecker\Checker\Database\DatabaseExpectationApplier;
use Slevomat\DoctrineAttributeChecker\Checker\DoctrineAttributeContext;
use Slevomat\DoctrineAttributeChecker\Checker\ViolationReporter;
use Slevomat\DoctrineAttributeChecker\Configuration\Configuration;
use Slevomat\DoctrineAttributeChecker\Rule\AttributeRule;
use Slevomat\DoctrineAttributeChecker\Rule\StrictRule;
use function array_filter;
use function array_values;
use function in_array;

final class DatabaseDiscriminatorColumnStrictRule implements AttributeRule, StrictRule
{

	public function __construct(
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
			'database.column-unsigned',
		];
	}

	#[Override]
	public function getAttributeNames(): array
	{
		return ['DiscriminatorColumn'];
	}

	#[Override]
	public function process(DoctrineAttributeContext $context, ViolationReporter $reporter): void
	{
		if ($context->classMetadata === null) {
			return;
		}

		$fieldMapping = DatabaseDiscriminatorColumnRule::createFieldMapping($context->classMetadata);
		if ($fieldMapping === null) {
			return;
		}

		$expectations = array_filter(
			$this->comparator->getExpectations(
				$context,
				$reporter,
				$context->classMetadata->getTableName(),
				$fieldMapping,
				false,
				'DiscriminatorColumn',
			),
			static fn (DatabaseExpectation $expectation): bool => $expectation->option
				|| in_array($expectation->name, ['length', 'columnDefinition'], true),
		);

		$this->expectationApplier->apply($context, $reporter, array_values($expectations));
	}

}
