<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Rule\Database;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\FieldMapping;
use Override;
use Slevomat\DoctrineAttributeChecker\Checker\Database\DatabaseColumnComparator;
use Slevomat\DoctrineAttributeChecker\Checker\DoctrineAttributeContext;
use Slevomat\DoctrineAttributeChecker\Checker\ViolationReporter;
use Slevomat\DoctrineAttributeChecker\Configuration\Configuration;
use Slevomat\DoctrineAttributeChecker\Rule\AttributeRule;

final class DatabaseDiscriminatorColumnRule implements AttributeRule
{

	public function __construct(
		private DatabaseColumnComparator $comparator,
	)
	{
	}

	/**
	 * @param ClassMetadata<object> $classMetadata
	 */
	public static function createFieldMapping(ClassMetadata $classMetadata): ?FieldMapping
	{
		$discriminatorColumn = $classMetadata->discriminatorColumn;
		if ($discriminatorColumn === null) {
			return null;
		}

		return FieldMapping::fromMappingArray([
			'type' => $discriminatorColumn->type,
			'fieldName' => $discriminatorColumn->fieldName,
			'columnName' => $discriminatorColumn->name,
			'length' => $discriminatorColumn->length,
			'columnDefinition' => $discriminatorColumn->columnDefinition,
			'options' => $discriminatorColumn->options,
			'nullable' => false,
		]);
	}

	#[Override]
	public static function getIdentifiers(Configuration $configuration): array
	{
		return ['database.column-missing', 'database.column-type'];
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

		$fieldMapping = self::createFieldMapping($context->classMetadata);
		if ($fieldMapping === null) {
			return;
		}

		$this->comparator->checkCompatibility($context, $reporter, $context->classMetadata->getTableName(), $fieldMapping);
	}

}
