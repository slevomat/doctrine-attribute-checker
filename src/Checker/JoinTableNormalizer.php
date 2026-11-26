<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Checker;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\JoinColumnMapping;
use Doctrine\ORM\Mapping\ManyToManyOwningSideMapping;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Property;
use ReflectionProperty;
use Slevomat\DoctrineAttributeChecker\Rule\RuleSet;
use Slevomat\DoctrineAttributeChecker\Source\AttributeSourceEditor;
use function array_filter;
use function array_merge;
use function array_values;
use function is_array;
use function ksort;
use function serialize;
use function sprintf;
use function str_ends_with;

/**
 * @phpstan-import-type EntityClassMetadata from DoctrineMapping
 */
final class JoinTableNormalizer
{

	public const array RULE_IDENTIFIERS = [
		'join-table.attribute-missing',
		'join-table.column-class',
		'join-table.duplicate-column',
		'join-table.nested-column',
	];

	public function __construct(
		private AttributeSourceEditor $sourceEditor,
		private DoctrineMapping $mapping,
		private Identifiers $identifiers,
		private RuleSet $ruleSet,
		private ViolationReporter $reporter,
	)
	{
	}

	/**
	 * @param class-string $className
	 * @param EntityClassMetadata $classMetadata
	 */
	public function process(
		Property $propertyNode,
		ReflectionProperty $property,
		string $className,
		string $file,
		ClassMetadata $classMetadata,
		ManyToManyOwningSideMapping $associationMapping,
	): void
	{
		$joinTableAttribute = null;
		foreach ($this->sourceEditor->getAttributes($propertyNode->attrGroups) as $attribute) {
			if ($this->sourceEditor->getAttributeClassName($attribute) === DoctrineAttributeContext::MAPPING_NAMESPACE . 'JoinTable') {
				$joinTableAttribute = $attribute;
				break;
			}
		}

		if ($joinTableAttribute === null) {
			$joinTableName = $this->identifiers->escape($associationMapping->joinTable->name);
			$message = 'ManyToMany associations must specify a JoinTable attribute with an explicit name.';
			if ($this->reporter->canFix('join-table.attribute-missing')) {
				$joinTableAttribute = $this->sourceEditor->addDoctrineAttribute(
					$propertyNode,
					'JoinTable',
					['name' => $joinTableName],
				);
				$this->reporter->markChanged();
			} else {
				$this->reporter->addViolationAt(
					$file,
					$propertyNode->getStartLine(),
					sprintf('%s::$%s', $className, $property->name),
					'join-table.attribute-missing',
					$message,
					true,
				);
				$joinTableAttribute = $this->sourceEditor->createDoctrineAttribute(
					$propertyNode,
					'JoinTable',
					['name' => $joinTableName],
				);
			}
		}

		$this->processNestedJoinColumns(
			$joinTableAttribute,
			$propertyNode,
			$className,
			sprintf('%s::$%s', $className, $property->name),
			$file,
			$property,
			$classMetadata,
			$associationMapping,
		);
	}

	/**
	 * @param class-string $className
	 * @param EntityClassMetadata $classMetadata
	 */
	private function processNestedJoinColumns(
		Attribute $joinTableAttribute,
		Property $owner,
		string $className,
		string $target,
		string $file,
		ReflectionProperty $property,
		ClassMetadata $classMetadata,
		ManyToManyOwningSideMapping $associationMapping,
	): void
	{
		$targetMetadata = $this->mapping->getMetadata($associationMapping->targetEntity);
		$sourceTableName = $classMetadata->getTableName();
		$targetTableName = $targetMetadata->getTableName();

		$nestedJoinColumnAttributes = $this->getNestedJoinColumnAttributes($joinTableAttribute, 'joinColumns');
		$nestedInverseJoinColumnAttributes = $this->getNestedJoinColumnAttributes(
			$joinTableAttribute,
			'inverseJoinColumns',
		);
		foreach (array_merge($nestedJoinColumnAttributes, $nestedInverseJoinColumnAttributes) as $nestedAttribute) {
			$this->ensureNestedJoinColumnClass(
				$nestedAttribute,
				$joinTableAttribute,
				$owner,
				$className,
				$target,
				$file,
				$property,
				$classMetadata,
			);
		}

		$directJoinColumnAttributes = [];
		$directInverseJoinColumnAttributes = [];
		foreach ($this->sourceEditor->getAttributes($owner->attrGroups) as $attribute) {
			$attributeClassName = $this->sourceEditor->getAttributeClassName($attribute);
			if ($attributeClassName === DoctrineAttributeContext::MAPPING_NAMESPACE . 'JoinColumn') {
				$directJoinColumnAttributes[] = $attribute;
			} elseif ($attributeClassName === DoctrineAttributeContext::MAPPING_NAMESPACE . 'InverseJoinColumn') {
				$directInverseJoinColumnAttributes[] = $attribute;
			}
		}

		$nestedJoinColumnAttributes = $this->removeDuplicateNestedJoinColumns(
			$nestedJoinColumnAttributes,
			$directJoinColumnAttributes,
			$joinTableAttribute,
			$owner,
			$className,
			$target,
			$file,
			$property,
			$classMetadata,
		);
		$nestedInverseJoinColumnAttributes = $this->removeDuplicateNestedJoinColumns(
			$nestedInverseJoinColumnAttributes,
			$directInverseJoinColumnAttributes,
			$joinTableAttribute,
			$owner,
			$className,
			$target,
			$file,
			$property,
			$classMetadata,
		);

		$joinColumnMappings = $this->getUniqueJoinColumnMappings($associationMapping->joinTable->joinColumns);
		$inverseJoinColumnMappings = $this->getUniqueJoinColumnMappings(
			$associationMapping->joinTable->inverseJoinColumns,
		);
		$hasExplicitJoinColumns = $nestedJoinColumnAttributes !== [] || $directJoinColumnAttributes !== [];
		$hasExplicitInverseJoinColumns = $nestedInverseJoinColumnAttributes !== []
			|| $directInverseJoinColumnAttributes !== [];
		$joinColumnIndex = 0;
		$inverseJoinColumnIndex = 0;

		foreach ($nestedJoinColumnAttributes as $nestedAttribute) {
			$mapping = $joinColumnMappings[$joinColumnIndex] ?? null;
			$joinColumnIndex++;
			if (!$mapping instanceof JoinColumnMapping) {
				continue;
			}

			$defaultName = $this->getDefaultManyToManyJoinColumnName(
				$associationMapping,
				$mapping,
				true,
				$hasExplicitJoinColumns,
				$hasExplicitInverseJoinColumns,
			);
			$this->processManyToManyJoinColumn(
				$nestedAttribute,
				$joinTableAttribute,
				$owner,
				$property,
				$classMetadata,
				$associationMapping,
				$this->sourceEditor->getAttributeClassName($nestedAttribute),
				$className,
				$target,
				$file,
				$mapping,
				$defaultName,
				$sourceTableName,
			);
		}

		foreach ($directJoinColumnAttributes as $directAttribute) {
			$mapping = $joinColumnMappings[$joinColumnIndex] ?? null;
			$joinColumnIndex++;
			if (!$mapping instanceof JoinColumnMapping) {
				continue;
			}

			$defaultName = $this->getDefaultManyToManyJoinColumnName(
				$associationMapping,
				$mapping,
				true,
				$hasExplicitJoinColumns,
				$hasExplicitInverseJoinColumns,
			);
			$this->processManyToManyJoinColumn(
				$directAttribute,
				null,
				$owner,
				$property,
				$classMetadata,
				$associationMapping,
				$this->sourceEditor->getAttributeClassName($directAttribute),
				$className,
				$target,
				$file,
				$mapping,
				$defaultName,
				$sourceTableName,
			);
		}

		foreach ($nestedInverseJoinColumnAttributes as $nestedAttribute) {
			$mapping = $inverseJoinColumnMappings[$inverseJoinColumnIndex] ?? null;
			$inverseJoinColumnIndex++;
			if (!$mapping instanceof JoinColumnMapping) {
				continue;
			}

			$defaultName = $this->getDefaultManyToManyJoinColumnName(
				$associationMapping,
				$mapping,
				false,
				$hasExplicitJoinColumns,
				$hasExplicitInverseJoinColumns,
			);
			$this->processManyToManyJoinColumn(
				$nestedAttribute,
				$joinTableAttribute,
				$owner,
				$property,
				$classMetadata,
				$associationMapping,
				DoctrineAttributeContext::MAPPING_NAMESPACE . 'InverseJoinColumn',
				$className,
				$target,
				$file,
				$mapping,
				$defaultName,
				$targetTableName,
			);
		}

		foreach ($directInverseJoinColumnAttributes as $directAttribute) {
			$mapping = $inverseJoinColumnMappings[$inverseJoinColumnIndex] ?? null;
			$inverseJoinColumnIndex++;
			if (!$mapping instanceof JoinColumnMapping) {
				continue;
			}

			$defaultName = $this->getDefaultManyToManyJoinColumnName(
				$associationMapping,
				$mapping,
				false,
				$hasExplicitJoinColumns,
				$hasExplicitInverseJoinColumns,
			);
			$this->processManyToManyJoinColumn(
				$directAttribute,
				null,
				$owner,
				$property,
				$classMetadata,
				$associationMapping,
				$this->sourceEditor->getAttributeClassName($directAttribute),
				$className,
				$target,
				$file,
				$mapping,
				$defaultName,
				$targetTableName,
			);
		}

		foreach ($joinColumnMappings as $index => $mapping) {
			if ($index < $joinColumnIndex) {
				continue;
			}

			$this->processSyntheticManyToManyJoinColumn(
				$joinTableAttribute,
				$owner,
				$className,
				$target,
				$file,
				$property,
				$classMetadata,
				$mapping,
				$associationMapping->joinTable->name,
				$this->getDefaultManyToManyJoinColumnName(
					$associationMapping,
					$mapping,
					true,
					$hasExplicitJoinColumns,
					$hasExplicitInverseJoinColumns,
				),
				$sourceTableName,
				'joinColumns',
			);
		}

		foreach ($inverseJoinColumnMappings as $index => $mapping) {
			if ($index < $inverseJoinColumnIndex) {
				continue;
			}

			$this->processSyntheticManyToManyJoinColumn(
				$joinTableAttribute,
				$owner,
				$className,
				$target,
				$file,
				$property,
				$classMetadata,
				$mapping,
				$associationMapping->joinTable->name,
				$this->getDefaultManyToManyJoinColumnName(
					$associationMapping,
					$mapping,
					false,
					$hasExplicitJoinColumns,
					$hasExplicitInverseJoinColumns,
				),
				$targetTableName,
				'inverseJoinColumns',
			);
		}

		foreach ($nestedJoinColumnAttributes as $nestedAttribute) {
			$this->convertNestedManyToManyJoinColumn(
				$nestedAttribute,
				$joinTableAttribute,
				$owner,
				'JoinColumn',
				$className,
				$target,
				$file,
			);
		}

		foreach ($nestedInverseJoinColumnAttributes as $nestedAttribute) {
			$this->convertNestedManyToManyJoinColumn(
				$nestedAttribute,
				$joinTableAttribute,
				$owner,
				'InverseJoinColumn',
				$className,
				$target,
				$file,
			);
		}
	}

	/** @param class-string $className */
	private function convertNestedManyToManyJoinColumn(
		Expr\New_ $nestedAttribute,
		Attribute $joinTableAttribute,
		Property $owner,
		string $shortName,
		string $className,
		string $target,
		string $file,
	): void
	{
		$context = new DoctrineAttributeContext(
			$nestedAttribute,
			$owner,
			$joinTableAttribute,
			DoctrineAttributeContext::MAPPING_NAMESPACE . $shortName,
			$className,
			$target,
			$file,
			$nestedAttribute->getStartLine(),
			null,
			null,
			null,
			$this->sourceEditor->getArgumentValues($nestedAttribute, $className),
			$this->sourceEditor,
		);
		$this->reporter->fixViolation(
			$context,
			'join-table.nested-column',
			'ManyToMany join columns must be declared as direct attributes.',
			function () use ($nestedAttribute, $joinTableAttribute, $owner, $shortName): bool {
				if (!$this->sourceEditor->removeNestedArrayItem($joinTableAttribute, $nestedAttribute)) {
					return false;
				}

				$attribute = $this->sourceEditor->createDoctrineAttribute($owner, $shortName, []);
				$attribute->args = array_values(array_filter(
					$nestedAttribute->args,
					static fn (Node $argument): bool => $argument instanceof Arg,
				));
				$this->appendManyToManyJoinColumnAttribute($owner, $joinTableAttribute, $attribute);

				return true;
			},
		);
	}

	/**
	 * @return list<Expr\New_>
	 */
	private function getNestedJoinColumnAttributes(Attribute $joinTableAttribute, string $argumentName): array
	{
		$attributes = [];
		foreach ($joinTableAttribute->args as $argument) {
			if ($argument->name?->toString() !== $argumentName || !$argument->value instanceof Expr\Array_) {
				continue;
			}

			foreach ($argument->value->items as $item) {
				if (!$item->value instanceof Expr\New_) {
					continue;
				}

				$attributeClassName = $this->sourceEditor->getAttributeClassName($item->value);
				if (
					$attributeClassName === DoctrineAttributeContext::MAPPING_NAMESPACE . 'JoinColumn'
					|| $attributeClassName === DoctrineAttributeContext::MAPPING_NAMESPACE . 'InverseJoinColumn'
				) {
					$attributes[] = $item->value;
				}
			}
		}

		return $attributes;
	}

	/**
	 * @param class-string $className
	 * @param ClassMetadata<object> $classMetadata
	 */
	private function ensureNestedJoinColumnClass(
		Expr\New_ $attribute,
		Attribute $joinTableAttribute,
		Property $owner,
		string $className,
		string $target,
		string $file,
		ReflectionProperty $property,
		ClassMetadata $classMetadata,
	): void
	{
		$attributeClassName = $this->sourceEditor->getAttributeClassName($attribute);
		if ($attributeClassName === DoctrineAttributeContext::MAPPING_NAMESPACE . 'JoinColumn') {
			return;
		}

		$context = new DoctrineAttributeContext(
			$attribute,
			$owner,
			$joinTableAttribute,
			$attributeClassName,
			$className,
			$target,
			$file,
			$attribute->getStartLine(),
			$property,
			null,
			$classMetadata,
			$this->sourceEditor->getArgumentValues($attribute, $className),
			$this->sourceEditor,
		);
		$this->reporter->fixViolation(
			$context,
			'join-table.column-class',
			'JoinTable joinColumns and inverseJoinColumns must use JoinColumn attributes.',
			function () use ($attribute, $owner): bool {
				if (!$attribute->class instanceof Name) {
					return false;
				}

				$expectedName = $this->sourceEditor->createDoctrineAttribute($owner, 'JoinColumn', [])->name;
				if ($attribute->class->toString() === $expectedName->toString()) {
					return false;
				}

				$attribute->class = $expectedName;

				return true;
			},
		);
	}

	/**
	 * @param list<Expr\New_> $nestedAttributes
	 * @param list<Attribute> $directAttributes
	 * @param class-string $className
	 * @param EntityClassMetadata $classMetadata
	 * @return list<Expr\New_>
	 */
	private function removeDuplicateNestedJoinColumns(
		array $nestedAttributes,
		array $directAttributes,
		Attribute $joinTableAttribute,
		Property $owner,
		string $className,
		string $target,
		string $file,
		ReflectionProperty $property,
		ClassMetadata $classMetadata,
	): array
	{
		$seenArguments = [];
		foreach ($directAttributes as $directAttribute) {
			$arguments = $this->sourceEditor->getArgumentValues($directAttribute, $className);
			ksort($arguments);
			$options = $arguments['options'] ?? null;
			if (is_array($options)) {
				ksort($options);
				$arguments['options'] = $options;
			}
			$seenArguments[serialize($arguments)] = true;
		}

		$uniqueNestedAttributes = [];
		foreach ($nestedAttributes as $nestedAttribute) {
			$arguments = $this->sourceEditor->getArgumentValues($nestedAttribute, $className);
			$normalizedArguments = $arguments;
			ksort($normalizedArguments);
			$options = $normalizedArguments['options'] ?? null;
			if (is_array($options)) {
				ksort($options);
				$normalizedArguments['options'] = $options;
			}
			$argumentsKey = serialize($normalizedArguments);
			if (!isset($seenArguments[$argumentsKey])) {
				$seenArguments[$argumentsKey] = true;
				$uniqueNestedAttributes[] = $nestedAttribute;

				continue;
			}

			$context = new DoctrineAttributeContext(
				$nestedAttribute,
				$owner,
				$joinTableAttribute,
				$this->sourceEditor->getAttributeClassName($nestedAttribute),
				$className,
				$target,
				$file,
				$nestedAttribute->getStartLine(),
				$property,
				null,
				$classMetadata,
				$arguments,
				$this->sourceEditor,
			);
			$this->reporter->fixViolation(
				$context,
				'join-table.duplicate-column',
				'JoinTable contains a duplicate join-column definition.',
				fn (): bool => $this->sourceEditor->removeNestedArrayItem($joinTableAttribute, $nestedAttribute),
			);
		}

		return $uniqueNestedAttributes;
	}

	/**
	 * @param list<JoinColumnMapping> $mappings
	 * @return list<JoinColumnMapping>
	 */
	private function getUniqueJoinColumnMappings(array $mappings): array
	{
		$uniqueMappings = [];
		$seenMappingKeys = [];
		foreach ($mappings as $mapping) {
			$options = $mapping->options ?? [];
			ksort($options);
			$mappingKey = serialize([
				$mapping->name,
				$mapping->referencedColumnName,
				$mapping->nullable,
				$mapping->unique,
				$mapping->columnDefinition,
				$options,
			]);
			if (isset($seenMappingKeys[$mappingKey])) {
				continue;
			}

			$seenMappingKeys[$mappingKey] = true;
			$uniqueMappings[] = $mapping;
		}

		return $uniqueMappings;
	}

	/**
	 * @param class-string $className
	 * @param EntityClassMetadata $classMetadata
	 */
	private function processManyToManyJoinColumn(
		Attribute|Expr\New_ $attribute,
		?Attribute $container,
		Property $owner,
		ReflectionProperty $property,
		ClassMetadata $classMetadata,
		ManyToManyOwningSideMapping $associationMapping,
		string $attributeClassName,
		string $className,
		string $target,
		string $file,
		JoinColumnMapping $mapping,
		string $defaultName,
		string $referencedTableName,
	): void
	{
		$context = new DoctrineAttributeContext(
			$attribute,
			$owner,
			$container,
			$attributeClassName,
			$className,
			$target,
			$file,
			$attribute->getStartLine(),
			$property,
			null,
			$classMetadata,
			$this->sourceEditor->getArgumentValues($attribute, $className),
			$this->sourceEditor,
			$defaultName,
			$associationMapping->joinTable->name,
			$mapping,
			$referencedTableName,
			false,
			true,
		);
		$this->ruleSet->processJoinColumn($context, $this->reporter);
	}

	private function getDefaultManyToManyJoinColumnName(
		ManyToManyOwningSideMapping $associationMapping,
		JoinColumnMapping $joinColumnMapping,
		bool $isJoinColumn,
		bool $hasExplicitJoinColumns,
		bool $hasExplicitInverseJoinColumns,
	): string
	{
		$isSelfReferencing = $associationMapping->sourceEntity === $associationMapping->targetEntity;
		$hasExplicitColumns = $isJoinColumn ? $hasExplicitJoinColumns : $hasExplicitInverseJoinColumns;
		$reference = $isSelfReferencing && !$hasExplicitColumns
			? ($isJoinColumn ? 'source' : 'target')
			: null;

		return $this->mapping->getOrmConfiguration()->getNamingStrategy()->joinKeyColumnName(
			$isJoinColumn ? $associationMapping->sourceEntity : $associationMapping->targetEntity,
			$reference ?? $joinColumnMapping->referencedColumnName,
		);
	}

	/**
	 * @param class-string $className
	 * @param EntityClassMetadata $classMetadata
	 * @param 'joinColumns'|'inverseJoinColumns' $joinTableArgumentName
	 */
	private function processSyntheticManyToManyJoinColumn(
		Attribute $joinTableAttribute,
		Property $owner,
		string $className,
		string $target,
		string $file,
		ReflectionProperty $property,
		ClassMetadata $classMetadata,
		JoinColumnMapping $mapping,
		string $joinTableName,
		string $defaultName,
		string $referencedTableName,
		string $joinTableArgumentName,
	): void
	{
		$shortName = match ($joinTableArgumentName) {
			'joinColumns' => 'JoinColumn',
			'inverseJoinColumns' => 'InverseJoinColumn',
		};
		$attributeClassName = DoctrineAttributeContext::MAPPING_NAMESPACE . $shortName;
		$attribute = $this->sourceEditor->createDoctrineAttribute($owner, $shortName, []);
		$context = new DoctrineAttributeContext(
			$attribute,
			$owner,
			null,
			$attributeClassName,
			$className,
			$target,
			$file,
			$owner->getStartLine(),
			$property,
			null,
			$classMetadata,
			[],
			$this->sourceEditor,
			$defaultName,
			$joinTableName,
			$mapping,
			$referencedTableName,
			false,
			true,
			true,
		);
		$this->ruleSet->processJoinColumn($context, $this->reporter);

		if (!$this->reporter->isFixing() || $context->arguments === []) {
			return;
		}

		$this->appendManyToManyJoinColumnAttribute($owner, $joinTableAttribute, $attribute);
		$this->reporter->markChanged();
	}

	private function appendManyToManyJoinColumnAttribute(
		Property $owner,
		Attribute $joinTableAttribute,
		Attribute $attribute,
	): void
	{
		$lastJoinColumnAttribute = $joinTableAttribute;
		$foundJoinTable = false;
		foreach ($this->sourceEditor->getAttributes($owner->attrGroups) as $existingAttribute) {
			if ($existingAttribute === $joinTableAttribute) {
				$foundJoinTable = true;

				continue;
			}

			if (!$foundJoinTable) {
				continue;
			}

			$existingAttributeClassName = $this->sourceEditor->getAttributeClassName($existingAttribute);
			if (
				!str_ends_with($existingAttributeClassName, '\\JoinColumn')
				&& !str_ends_with($existingAttributeClassName, '\\InverseJoinColumn')
			) {
				break;
			}

			$lastJoinColumnAttribute = $existingAttribute;
		}

		$this->sourceEditor->insertAttributeAfter($owner, $lastJoinColumnAttribute, $attribute);
	}

}
