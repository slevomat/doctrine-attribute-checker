<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Rule\Database;

use Doctrine\ORM\Mapping\FieldMapping;
use Override;
use Slevomat\DoctrineAttributeChecker\Checker\Database\DatabaseColumnComparator;
use Slevomat\DoctrineAttributeChecker\Checker\Database\DatabaseExpectation;
use Slevomat\DoctrineAttributeChecker\Checker\Database\DatabaseExpectationApplier;
use Slevomat\DoctrineAttributeChecker\Checker\DoctrineAttributeContext;
use Slevomat\DoctrineAttributeChecker\Checker\ViolationReporter;
use Slevomat\DoctrineAttributeChecker\Configuration\Configuration;
use Slevomat\DoctrineAttributeChecker\Rule\AttributeRule;
use function array_filter;
use function array_values;
use function in_array;

final class DatabaseDiscriminatorColumnRule implements AttributeRule
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
			'database.column-collation',
			'database.column-definition',
			'database.column-fixed',
			'database.column-length',
			'database.column-missing',
			'database.column-nullability',
			'database.column-precision',
			'database.column-type',
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
		$discriminatorColumn = $context->classMetadata?->discriminatorColumn;
		if ($context->classMetadata === null || $discriminatorColumn === null) {
			return;
		}

		$fieldMapping = FieldMapping::fromMappingArray([
			'type' => $discriminatorColumn->type,
			'fieldName' => $discriminatorColumn->fieldName,
			'columnName' => $discriminatorColumn->name,
			'length' => $discriminatorColumn->length,
			'columnDefinition' => $discriminatorColumn->columnDefinition,
			'options' => $discriminatorColumn->options,
			'nullable' => false,
		]);
		$expectations = array_filter(
			$this->comparator->compare(
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
