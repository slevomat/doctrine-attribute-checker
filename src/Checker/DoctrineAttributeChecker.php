<?php declare(strict_types = 1);

// spell-check-ignore: backticks insertable onetoone updatable

namespace Slevomat\DoctrineAttributeChecker\Checker;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\JoinColumnMapping;
use Doctrine\ORM\Mapping\ManyToManyOwningSideMapping;
use Doctrine\ORM\Mapping\OneToOneOwningSideMapping;
use Doctrine\ORM\Mapping\ToOneOwningSideMapping;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeFinder;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Slevomat\DoctrineAttributeChecker\Configuration\Configuration;
use Slevomat\DoctrineAttributeChecker\Database\DatabaseSchemaSnapshot;
use Slevomat\DoctrineAttributeChecker\Rule\RuleSet;
use Slevomat\DoctrineAttributeChecker\Rule\RuleSetFactory;
use Slevomat\DoctrineAttributeChecker\Source\AttributeSourceEditor;
use Slevomat\DoctrineAttributeChecker\Source\ParsedSourceFile;
use Slevomat\DoctrineAttributeChecker\Violation;
use function array_filter;
use function array_merge;
use function array_values;
use function class_exists;
use function count;
use function in_array;
use function is_array;
use function ksort;
use function serialize;
use function sprintf;
use function str_ends_with;
use function str_starts_with;

/**
 * @phpstan-type EntityClassMetadata ClassMetadata<object>
 * @phpstan-type EntityReflection ReflectionClass<object>
 */
final class DoctrineAttributeChecker
{

	public const array RULE_IDENTIFIERS = [
		'join-table.attribute-missing',
		'join-table.column-class',
		'join-table.duplicate-column',
		'join-table.nested-column',
	];

	private ?ParsedSourceFile $parsedSourceFile = null;

	private ViolationReporter $reporter;

	private DoctrineMapping $mapping;

	private Identifiers $identifiers;

	private RuleSet $ruleSet;

	/**
	 * @param array<class-string, ClassMetadata<object>> $metadataByClassName
	 * @param array<class-string, true> $mappedClassNames
	 */
	public function __construct(
		EntityManagerInterface $entityManager,
		Configuration $configuration,
		private AttributeSourceEditor $sourceEditor,
		private array $metadataByClassName,
		private array $mappedClassNames,
		?DatabaseSchemaSnapshot $databaseSchema,
	)
	{
		$this->reporter = new ViolationReporter($configuration);
		$this->mapping = new DoctrineMapping($entityManager, $metadataByClassName);
		$this->identifiers = new Identifiers($configuration);
		$this->ruleSet = RuleSetFactory::create(
			$configuration,
			$this->mapping,
			$this->identifiers,
			$sourceEditor,
			$databaseSchema,
		);
	}

	/**
	 * @return array{contents: string, changed: bool, violations: list<Violation>}
	 */
	public function inspect(string $source, string $file, bool $fix): array
	{
		$this->reporter->start($fix);
		$parsed = $this->sourceEditor->parse($source, $file);
		$this->parsedSourceFile = $parsed;

		$nodeFinder = new NodeFinder();
		$classNodes = $nodeFinder->findInstanceOf($parsed->statements, ClassLike::class);
		foreach ($classNodes as $classNode) {
			$resolvedName = $classNode->namespacedName;
			if (!$resolvedName instanceof Name) {
				continue;
			}

			$className = $resolvedName->toString();
			if (!isset($this->mappedClassNames[$className])) {
				continue;
			}

			if (!class_exists($className)) {
				continue;
			}

			$reflectionClass = new ReflectionClass($className);
			$classMetadata = $this->metadataByClassName[$className] ?? null;
			$this->processAttributeGroups(
				$classNode,
				$classNode->attrGroups,
				$className,
				$className,
				$file,
				null,
				null,
				$classMetadata,
			);

			if ($classMetadata !== null) {
				$this->ruleSet->processClass(
					new ClassContext($classNode, $className, $file, $reflectionClass, $classMetadata),
					$this->reporter,
				);
			}

			foreach ($classNode->stmts as $member) {
				if ($member instanceof Property) {
					$this->processProperty($member, $reflectionClass, $className, $file, $classMetadata);
				} elseif ($member instanceof ClassMethod) {
					$this->processMethod($member, $reflectionClass, $className, $file, $classMetadata);
				}
			}
		}

		return [
			'contents' => $this->reporter->hasChanges() ? $this->sourceEditor->print($parsed) : $source,
			'changed' => $this->reporter->hasChanges(),
			'violations' => $this->reporter->getViolations(),
		];
	}

	/**
	 * @param EntityReflection $reflectionClass
	 * @param class-string $className
	 * @param ?EntityClassMetadata $classMetadata
	 */
	private function processProperty(
		Property $propertyNode,
		ReflectionClass $reflectionClass,
		string $className,
		string $file,
		?ClassMetadata $classMetadata,
	): void
	{
		if (count($propertyNode->props) !== 1) {
			return;
		}

		$propertyName = $propertyNode->props[0]->name->toString();
		if (!$reflectionClass->hasProperty($propertyName)) {
			return;
		}

		$reflectionProperty = $reflectionClass->getProperty($propertyName);
		if ($reflectionProperty->getDeclaringClass()->name !== $className) {
			return;
		}

		$hasExplicitJoinColumn = false;
		foreach ($this->sourceEditor->getAttributes($propertyNode->attrGroups) as $attribute) {
			if ($this->sourceEditor->getAttributeClassName($attribute) === DoctrineAttributeContext::MAPPING_NAMESPACE . 'JoinColumn') {
				$hasExplicitJoinColumn = true;
				break;
			}
		}

		$this->processAttributeGroups(
			$propertyNode,
			$propertyNode->attrGroups,
			$className,
			sprintf('%s::$%s', $className, $propertyName),
			$file,
			$reflectionProperty,
			null,
			$classMetadata,
		);

		$associationMapping = $classMetadata?->associationMappings[$propertyName] ?? null;
		if ($associationMapping instanceof ManyToManyOwningSideMapping) {
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
						sprintf('%s::$%s', $className, $propertyName),
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
				sprintf('%s::$%s', $className, $propertyName),
				$file,
				$reflectionProperty,
				$classMetadata,
			);
		}

		if (!$hasExplicitJoinColumn) {
			$this->processImplicitToOneJoinColumn(
				$propertyNode,
				$reflectionProperty,
				$className,
				$propertyName,
				$file,
				$classMetadata,
			);
		}
	}

	/**
	 * @param class-string $className
	 * @param ?EntityClassMetadata $classMetadata
	 */
	private function processImplicitToOneJoinColumn(
		Property $propertyNode,
		ReflectionProperty $reflectionProperty,
		string $className,
		string $propertyName,
		string $file,
		?ClassMetadata $classMetadata,
	): void
	{
		$associationMapping = $classMetadata?->associationMappings[$propertyName] ?? null;
		if (!$associationMapping instanceof ToOneOwningSideMapping) {
			return;
		}

		$joinColumnMapping = $associationMapping->joinColumns[0] ?? null;
		if (!$joinColumnMapping instanceof JoinColumnMapping) {
			return;
		}

		$targetMetadata = $this->mapping->getMetadata($associationMapping->targetEntity);
		$defaultName = $this->mapping->getOrmConfiguration()->getNamingStrategy()
			->joinColumnName($propertyName, $className);
		$attribute = $this->sourceEditor->createDoctrineAttribute($propertyNode, 'JoinColumn', []);
		$arguments = [];
		$context = new DoctrineAttributeContext(
			$attribute,
			$propertyNode,
			null,
			DoctrineAttributeContext::MAPPING_NAMESPACE . 'JoinColumn',
			$className,
			sprintf('%s::$%s', $className, $propertyName),
			$file,
			$propertyNode->getStartLine(),
			$reflectionProperty,
			null,
			$classMetadata,
			$arguments,
			$this->sourceEditor,
			$defaultName,
			$classMetadata->getTableName(),
			$joinColumnMapping,
			$targetMetadata->getTableName(),
			$associationMapping instanceof OneToOneOwningSideMapping,
			false,
			true,
		);
		$this->ruleSet->processJoinColumn($context, $this->reporter);

		if (!$this->reporter->isFixing() || $context->arguments === []) {
			return;
		}

		$this->sourceEditor->appendAttribute($propertyNode, $attribute);
		$this->reporter->markChanged();
	}

	/**
	 * @param class-string $className
	 * @param ?EntityClassMetadata $classMetadata
	 */
	private function processNestedJoinColumns(
		Attribute $joinTableAttribute,
		ClassLike|ClassMethod|Property $owner,
		string $className,
		string $target,
		string $file,
		?ReflectionProperty $property,
		?ClassMetadata $classMetadata,
	): void
	{
		if ($property === null || !($owner instanceof Property) || $classMetadata === null) {
			return;
		}

		$associationMapping = $classMetadata->associationMappings[$property->name] ?? null;
		if (!$associationMapping instanceof ManyToManyOwningSideMapping) {
			return;
		}

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

	/**
	 * @param EntityReflection $reflectionClass
	 * @param class-string $className
	 * @param ?EntityClassMetadata $classMetadata
	 */
	private function processMethod(
		ClassMethod $methodNode,
		ReflectionClass $reflectionClass,
		string $className,
		string $file,
		?ClassMetadata $classMetadata,
	): void
	{
		$methodName = $methodNode->name->toString();
		if (!$reflectionClass->hasMethod($methodName)) {
			return;
		}

		$reflectionMethod = $reflectionClass->getMethod($methodName);
		if ($reflectionMethod->getDeclaringClass()->name !== $className) {
			return;
		}

		$this->processAttributeGroups(
			$methodNode,
			$methodNode->attrGroups,
			$className,
			sprintf('%s::%s()', $className, $methodName),
			$file,
			null,
			$reflectionMethod,
			$classMetadata,
		);
	}

	/**
	 * @param array<array-key, Node\AttributeGroup> $attributeGroups
	 * @param class-string $className
	 * @param ?EntityClassMetadata $classMetadata
	 */
	private function processAttributeGroups(
		ClassLike|ClassMethod|Property $owner,
		array $attributeGroups,
		string $className,
		string $target,
		string $file,
		?ReflectionProperty $property,
		?ReflectionMethod $method,
		?ClassMetadata $classMetadata,
	): void
	{
		$associationMapping = $property === null
			? null
			: ($classMetadata?->associationMappings[$property->name] ?? null);
		$isManyToManyOwningSide = $associationMapping instanceof ManyToManyOwningSideMapping;

		foreach ($this->sourceEditor->getAttributes($attributeGroups) as $attribute) {
			$attributeClassName = $this->sourceEditor->getAttributeClassName($attribute);
			if (!str_starts_with($attributeClassName, DoctrineAttributeContext::MAPPING_NAMESPACE)) {
				continue;
			}

			if (
				$isManyToManyOwningSide
				&& in_array($attributeClassName, [
					DoctrineAttributeContext::MAPPING_NAMESPACE . 'JoinColumn',
					DoctrineAttributeContext::MAPPING_NAMESPACE . 'InverseJoinColumn',
				], true)
			) {
				continue;
			}

			$arguments = $this->sourceEditor->getArgumentValues($attribute, $className);
			$context = new DoctrineAttributeContext(
				$attribute,
				$owner,
				null,
				$attributeClassName,
				$className,
				$target,
				$file,
				$attribute->getStartLine(),
				$property,
				$method,
				$classMetadata,
				$arguments,
				$this->sourceEditor,
				sourceFile: $this->parsedSourceFile,
			);

			$this->ruleSet->processAttribute($context, $this->reporter);
		}
	}

}
