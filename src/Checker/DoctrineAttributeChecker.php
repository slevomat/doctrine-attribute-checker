<?php declare(strict_types = 1);

// spell-check-ignore: backticks insertable onetoone updatable

namespace Slevomat\DoctrineAttributeChecker\Checker;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\FieldMapping;
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
use ReflectionNamedType;
use ReflectionProperty;
use Slevomat\DoctrineAttributeChecker\Configuration\Configuration;
use Slevomat\DoctrineAttributeChecker\Database\DatabaseForeignKey;
use Slevomat\DoctrineAttributeChecker\Database\DatabaseSchemaSnapshot;
use Slevomat\DoctrineAttributeChecker\Source\AttributeSourceEditor;
use Slevomat\DoctrineAttributeChecker\Source\ParsedSourceFile;
use Slevomat\DoctrineAttributeChecker\Violation;
use function array_filter;
use function array_flip;
use function array_key_exists;
use function array_map;
use function array_merge;
use function array_push;
use function array_unique;
use function array_values;
use function class_exists;
use function count;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function is_subclass_of;
use function ksort;
use function ltrim;
use function serialize;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function strtolower;
use function strtoupper;
use function substr;
use function usort;

/**
 * @phpstan-type EntityClassMetadata ClassMetadata<object>
 * @phpstan-type EntityReflection ReflectionClass<object>
 */
final class DoctrineAttributeChecker
{

	private const string MAPPING_NAMESPACE = 'Doctrine\\ORM\\Mapping\\';

	private const array LIFECYCLE_CALLBACKS = [
		'PostLoad',
		'PostPersist',
		'PostRemove',
		'PostUpdate',
		'PrePersist',
		'PreRemove',
		'PreUpdate',
	];

	private const array LENGTH_DATABASE_TYPES = [
		'binary',
		'blob',
		'char',
		'longblob',
		'longtext',
		'mediumblob',
		'mediumtext',
		'text',
		'tinyblob',
		'tinytext',
		'varbinary',
		'varchar',
	];

	private const array NUMERIC_DATABASE_TYPES = [
		'bigint',
		'decimal',
		'double',
		'float',
		'int',
		'integer',
		'mediumint',
		'numeric',
		'real',
		'smallint',
		'tinyint',
		'year',
	];

	private bool $fix = false;

	private bool $changed = false;

	private ?ParsedSourceFile $parsedSourceFile = null;

	/** @var list<Violation> */
	private array $violations = [];

	/**
	 * @param array<class-string, ClassMetadata<object>> $metadataByClassName
	 * @param array<class-string, true> $mappedClassNames
	 */
	public function __construct(
		private EntityManagerInterface $entityManager,
		private Configuration $configuration,
		private AttributeSourceEditor $sourceEditor,
		private array $metadataByClassName,
		private array $mappedClassNames,
		private ?DatabaseSchemaSnapshot $databaseSchema,
	)
	{
	}

	/**
	 * @return array{contents: string, changed: bool, violations: list<Violation>}
	 */
	public function inspect(string $source, string $file, bool $fix): array
	{
		$this->fix = $fix;
		$this->changed = false;
		$this->violations = [];
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
			if ($classMetadata !== null) {
				$this->checkDatabaseTable($file, $classNode, $className, $classMetadata);
			}

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

			if (
				$classMetadata !== null
				&& !$classMetadata->isMappedSuperclass
				&& !$classMetadata->isEmbeddedClass
				&& $classMetadata->parentClasses === []
				&& !$reflectionClass->isAbstract()
				&& !$this->hasAttribute($classNode, 'Table')
			) {
				$tableName = $this->unquoteIdentifier($classMetadata->getTableName());
				$tableName = $this->isReservedIdentifier($tableName)
					? sprintf('`%s`', $tableName)
					: $tableName;
				if ($this->fix && $this->configuration->isRuleEnabled('table.attribute-missing')) {
					$this->sourceEditor->addDoctrineAttribute($classNode, 'Table', ['name' => $tableName]);
					$this->changed = true;
				} else {
					$this->addRawViolation(
						$file,
						$classNode->getStartLine(),
						$className,
						'table.attribute-missing',
						'Concrete entities must declare a Table attribute with an explicit name.',
						true,
					);
				}
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
			'contents' => $this->changed ? $this->sourceEditor->print($parsed) : $source,
			'changed' => $this->changed,
			'violations' => $this->violations,
		];
	}

	private function hasAttribute(ClassLike|ClassMethod|Property $owner, string $shortName): bool
	{
		$expectedClassName = self::MAPPING_NAMESPACE . $shortName;
		foreach ($this->sourceEditor->getAttributes($owner->attrGroups) as $attribute) {
			if ($this->sourceEditor->getAttributeClassName($attribute) === $expectedClassName) {
				return true;
			}
		}

		return false;
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
			if ($this->sourceEditor->getAttributeClassName($attribute) === self::MAPPING_NAMESPACE . 'JoinColumn') {
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
				if ($this->sourceEditor->getAttributeClassName($attribute) === self::MAPPING_NAMESPACE . 'JoinTable') {
					$joinTableAttribute = $attribute;
					break;
				}
			}

			if ($joinTableAttribute === null) {
				$joinTableName = $this->unquoteIdentifier($associationMapping->joinTable->name);
				$joinTableName = $this->isReservedIdentifier($joinTableName)
					? sprintf('`%s`', $joinTableName)
					: $joinTableName;
				$message = 'ManyToMany associations must specify a JoinTable attribute with an explicit name.';
				if ($this->fix && $this->configuration->isRuleEnabled('join-table.attribute-missing')) {
					$joinTableAttribute = $this->sourceEditor->addDoctrineAttribute(
						$propertyNode,
						'JoinTable',
						['name' => $joinTableName],
					);
					$this->changed = true;
				} else {
					$this->addRawViolation(
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

		$targetMetadata = $this->metadataByClassName[$associationMapping->targetEntity]
			?? $this->entityManager->getClassMetadata($associationMapping->targetEntity);
		$defaultName = $this->entityManager->getConfiguration()->getNamingStrategy()
			->joinColumnName($propertyName, $className);
		$attribute = $this->sourceEditor->createDoctrineAttribute($propertyNode, 'JoinColumn', []);
		$arguments = [];
		$context = new DoctrineAttributeContext(
			$attribute,
			$propertyNode,
			null,
			self::MAPPING_NAMESPACE . 'JoinColumn',
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
		$this->checkJoinColumn($context);

		if (!$this->fix || $context->arguments === []) {
			return;
		}

		$this->sourceEditor->appendAttribute($propertyNode, $attribute);
		$this->changed = true;
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

		$targetMetadata = $this->metadataByClassName[$associationMapping->targetEntity]
			?? $this->entityManager->getClassMetadata($associationMapping->targetEntity);
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
			if ($attributeClassName === self::MAPPING_NAMESPACE . 'JoinColumn') {
				$directJoinColumnAttributes[] = $attribute;
			} elseif ($attributeClassName === self::MAPPING_NAMESPACE . 'InverseJoinColumn') {
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
				self::MAPPING_NAMESPACE . 'InverseJoinColumn',
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
			self::MAPPING_NAMESPACE . $shortName,
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
		$this->fixViolation(
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
					$attributeClassName === self::MAPPING_NAMESPACE . 'JoinColumn'
					|| $attributeClassName === self::MAPPING_NAMESPACE . 'InverseJoinColumn'
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
		if ($attributeClassName === self::MAPPING_NAMESPACE . 'JoinColumn') {
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
		$this->fixViolation(
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
			$this->fixViolation(
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
		$this->checkJoinColumn($context);
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

		return $this->entityManager->getConfiguration()->getNamingStrategy()->joinKeyColumnName(
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
		$attributeClassName = self::MAPPING_NAMESPACE . $shortName;
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
		$this->checkJoinColumn($context);

		if (!$this->fix || $context->arguments === []) {
			return;
		}

		$this->appendManyToManyJoinColumnAttribute($owner, $joinTableAttribute, $attribute);
		$this->changed = true;
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
			if (!str_starts_with($attributeClassName, self::MAPPING_NAMESPACE)) {
				continue;
			}

			if (
				$isManyToManyOwningSide
				&& in_array($attributeClassName, [
					self::MAPPING_NAMESPACE . 'JoinColumn',
					self::MAPPING_NAMESPACE . 'InverseJoinColumn',
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
			);

			$this->checkAttribute($context);
			if (
				$attributeClassName === self::MAPPING_NAMESPACE . 'Column'
				&& $attribute->args === []
				&& $this->parsedSourceFile !== null
				&& $this->sourceEditor->hasEmptyParentheses($attribute, $this->parsedSourceFile)
			) {
				$this->fixViolation(
					$context,
					'column.empty-parentheses',
					'Column attributes without arguments should not use empty parentheses.',
					fn (): bool => $this->sourceEditor->removeEmptyParentheses($attribute, $owner),
				);
			}
		}
	}

	private function checkAttribute(DoctrineAttributeContext $context): void
	{
		$shortName = substr($context->attributeClassName, strlen(self::MAPPING_NAMESPACE));
		if (in_array($shortName, $this->configuration->forbiddenAttributes, true)) {
			$this->checkForbiddenAttribute($context, $shortName);

			return;
		}

		if (in_array($shortName, self::LIFECYCLE_CALLBACKS, true) || $shortName === 'HasLifecycleCallbacks') {
			if ($this->isAllowedLifecycleCallback($context, $shortName)) {
				return;
			}

			$this->addViolation(
				$context,
				'forbidden.lifecycle-callback',
				sprintf('Doctrine lifecycle callback attribute %s is not used.', $shortName),
			);

			return;
		}

		switch ($shortName) {
			case 'Column':
				$this->checkColumn($context);

				return;
			case 'JoinColumn':
			case 'InverseJoinColumn':
				$this->checkJoinColumn($context);

				return;
			case 'Table':
				$this->checkTable($context);

				return;
			case 'JoinTable':
				$this->checkJoinTable($context);

				return;
			case 'ManyToOne':
			case 'OneToOne':
				$this->checkToOneAssociation($context, $shortName);

				return;
			case 'OneToMany':
			case 'ManyToMany':
				$this->checkToManyAssociation($context, $shortName);

				return;
			case 'Embedded':
				$this->checkEmbedded($context);

				return;
			case 'Entity':
				$this->checkEntity($context);

				return;
			case 'Cache':
				$this->checkCache($context);

				return;
			case 'GeneratedValue':
				$this->checkGeneratedValue($context);

				return;
		}
	}

	private function checkForbiddenAttribute(DoctrineAttributeContext $context, string $shortName): void
	{
		$this->addViolation(
			$context,
			'forbidden.attribute',
			sprintf('Doctrine attribute %s is forbidden.', $shortName),
		);
	}

	private function isAllowedLifecycleCallback(DoctrineAttributeContext $context, string $shortName): bool
	{
		if ($context->property !== null) {
			return false;
		}

		return $this->configuration->isLifecycleCallbackAllowed(
			$context->className,
			$context->method?->name,
			$shortName,
		);
	}

	private function checkEntity(DoctrineAttributeContext $context): void
	{
		if (!array_key_exists('repositoryClass', $context->arguments)) {
			return;
		}

		$repositoryClass = $context->arguments['repositoryClass'];
		if (is_string($repositoryClass)) {
			$repositoryClass = ltrim($repositoryClass, '\\');
		}

		if ($repositoryClass === $this->entityManager->getConfiguration()->getDefaultRepositoryClassName()) {
			$this->fixViolation(
				$context,
				'entity.repository-class',
				'Entity.repositoryClass is redundant because the repository class is configured globally.',
				static fn (): bool => $context->removeArgument('repositoryClass'),
			);

			return;
		}

		if ($this->configuration->allowCustomRepositoryClass) {
			return;
		}

		$this->addViolation(
			$context,
			'entity.repository-class',
			'Entity.repositoryClass is forbidden; repositories are standalone classes.',
		);
	}

	private function checkCache(DoctrineAttributeContext $context): void
	{
		foreach ($this->configuration->forbiddenCacheArguments as $argumentName) {
			if (!array_key_exists($argumentName, $context->arguments)) {
				continue;
			}

			$this->addViolation(
				$context,
				'cache.' . $argumentName,
				sprintf('Cache.%s is forbidden; use the configured cache defaults.', $argumentName),
			);
		}
	}

	private function checkGeneratedValue(DoctrineAttributeContext $context): void
	{
		if (!array_key_exists('strategy', $context->arguments)) {
			return;
		}

		$strategy = $context->arguments['strategy'];
		if (!is_string($strategy)) {
			$this->addViolation(
				$context,
				'generated-value.strategy',
				'GeneratedValue.strategy must be a string.',
			);

			return;
		}

		if (strtoupper($strategy) === 'AUTO') {
			$this->fixViolation(
				$context,
				'generated-value.strategy',
				'GeneratedValue.strategy is redundant when it uses the default AUTO strategy.',
				static fn (): bool => $context->removeArgument('strategy'),
			);

			return;
		}

		$this->addViolation(
			$context,
			'generated-value.strategy',
			'GeneratedValue.strategy is forbidden; use the default AUTO strategy.',
		);
	}

	private function checkColumn(DoctrineAttributeContext $context): void
	{
		$this->checkIdentifierName($context, 'name', $this->getDefaultColumnName($context));
		$this->checkColumnType($context);

		if (array_key_exists('nullable', $context->arguments)) {
			$this->fixViolation(
				$context,
				'column.nullable',
				'Column.nullable is inferred from the PHP property type.',
				static fn (): bool => $context->removeArgument('nullable'),
			);
		}

		if (($context->arguments['unique'] ?? null) === false) {
			$this->fixViolation(
				$context,
				'column.unique',
				'Column.unique=false is the default and should be omitted.',
				static fn (): bool => $context->removeArgument('unique'),
			);
		}

		$options = $context->arguments['options'] ?? [];
		if (is_array($options)) {
			foreach ($this->configuration->forbiddenColumnOptions as $optionName) {
				if (!array_key_exists($optionName, $options)) {
					continue;
				}

				$this->fixViolation(
					$context,
					'column.options.' . $optionName,
					sprintf('Column.options.%s is not maintained in Doctrine mapping.', $optionName),
					static fn (): bool => $context->removeOption($optionName),
				);
			}

		}

		foreach ($this->configuration->forbiddenColumnArguments as $argumentName) {
			if (!array_key_exists($argumentName, $context->arguments)) {
				continue;
			}

			$this->addViolation(
				$context,
				'column.' . $argumentName,
				sprintf('Column.%s is forbidden.', $argumentName),
			);
		}

		if ($this->databaseSchema !== null) {
			$this->checkDatabaseColumn($context);
		}

		$options = $context->arguments['options'] ?? [];
		if (is_array($options) && !$context->optionsAreSorted($this->configuration->columnOptionOrder)) {
			$this->fixViolation(
				$context,
				'column.options.order',
				'Column.options must use the canonical key order.',
				fn (): bool => $context->sortOptions($this->configuration->columnOptionOrder),
			);
		}

		if (!$context->argumentsAreSorted($this->configuration->columnArgumentOrder)) {
			$this->fixViolation(
				$context,
				'column.argument-order',
				'Column arguments must use the canonical order.',
				fn (): bool => $context->sortArguments($this->configuration->columnArgumentOrder),
			);
		}
	}

	private function checkColumnType(DoctrineAttributeContext $context): void
	{
		if ($context->property === null) {
			return;
		}

		$typedFieldMapper = $this->entityManager->getConfiguration()->getTypedFieldMapper();
		if ($typedFieldMapper === null) {
			return;
		}

		$inferredMapping = $typedFieldMapper->validateAndComplete(
			['fieldName' => $context->property->name],
			$context->property,
		);
		$inferredType = $inferredMapping['type'] ?? null;
		if ($inferredType === 'json' && !array_key_exists('type', $context->arguments)) {
			$this->fixViolation(
				$context,
				'column.type-json',
				'Column.type must be explicitly specified for a JSON property.',
				static fn (): bool => $context->setArgument('type', 'json'),
			);
		} elseif (
			$inferredType !== 'json'
			&& is_string($inferredType)
			&& ($context->arguments['type'] ?? null) === $inferredType
		) {
			$this->fixViolation(
				$context,
				'column.type',
				sprintf('Column.type is inferred as %s from the PHP property type.', $inferredType),
				static fn (): bool => $context->removeArgument('type'),
			);
		}

		$inferredEnumType = $inferredMapping['enumType'] ?? null;
		if (is_string($inferredEnumType) && ($context->arguments['enumType'] ?? null) === $inferredEnumType) {
			$this->fixViolation(
				$context,
				'column.enum-type',
				sprintf('Column.enumType is inferred as %s from the PHP property type.', $inferredEnumType),
				static fn (): bool => $context->removeArgument('enumType'),
			);
		}
	}

	private function checkJoinColumn(DoctrineAttributeContext $context): void
	{
		if (array_key_exists('onDelete', $context->arguments)) {
			$this->fixViolation(
				$context,
				'join-column.on-delete',
				sprintf('%s.onDelete is not maintained in Doctrine mapping.', $this->shortAttributeName($context)),
				static fn (): bool => $context->removeArgument('onDelete'),
			);
		}

		if ($context->databaseJoinTable) {
			$name = $context->arguments['name'] ?? null;
			if (!is_string($name)) {
				$defaultName = $context->databaseJoinColumn->name
					?? $context->defaultName
					?? $this->getDefaultJoinColumnName($context);
				if ($defaultName === null) {
					$this->addViolation(
						$context,
						'join-column.name',
						'ManyToMany join columns must specify a name explicitly.',
					);
				} else {
					$this->fixViolation(
						$context,
						'join-column.name',
						'ManyToMany join columns must specify a name explicitly.',
						static fn (): bool => $context->setArgument('name', $defaultName),
					);
				}
			}

			$this->checkQuotedIdentifier($context, 'name');
		} else {
			$this->checkIdentifierName(
				$context,
				'name',
				$context->defaultName ?? $this->getDefaultJoinColumnName($context),
			);
		}

		if (($context->arguments['referencedColumnName'] ?? null) === 'id') {
			$this->fixViolation(
				$context,
				'join-column.referenced-column-name',
				'JoinColumn.referencedColumnName is inferred as id.',
				static fn (): bool => $context->removeArgument('referencedColumnName'),
			);
		} elseif (array_key_exists('referencedColumnName', $context->arguments)) {
			$this->checkQuotedIdentifier($context, 'referencedColumnName');
		}

		if (array_key_exists('nullable', $context->arguments)) {
			$propertyType = $context->property?->getType();
			if (
				$context->arguments['nullable'] === false
				&& (
					$propertyType === null
					|| $propertyType->allowsNull()
				)
			) {
				$this->addViolation(
					$context,
					'join-column.nullable',
					'JoinColumn.nullable=false conflicts with the nullable PHP property type.',
				);
			} else {
				$this->fixViolation(
					$context,
					'join-column.nullable',
					'JoinColumn.nullable is inferred from the PHP property type.',
					static fn (): bool => $context->removeArgument('nullable'),
				);
			}
		}

		if (($context->arguments['unique'] ?? null) === false) {
			$this->fixViolation(
				$context,
				'join-column.unique',
				'JoinColumn.unique=false is the default and should be omitted.',
				static fn (): bool => $context->removeArgument('unique'),
			);
		}

		if ($this->databaseSchema !== null) {
			$this->checkDatabaseJoinColumn($context);
		}

		if ($context->arguments === [] && !$context->synthetic && !$context->databaseJoinTable) {
			$this->fixViolation(
				$context,
				'join-column.empty',
				'JoinColumn has no arguments and can be inferred.',
				static fn (): bool => $context->removeFromSource(),
			);
		}
	}

	private function checkTable(DoctrineAttributeContext $context): void
	{
		if (!array_key_exists('name', $context->arguments) || !is_string($context->arguments['name'])) {
			$tableName = $context->classMetadata?->getTableName();
			if ($tableName !== null) {
				$this->fixViolation(
					$context,
					'table.name',
					'Table.name must be specified explicitly.',
					static fn (): bool => $context->setArgument('name', $tableName),
				);
			} else {
				$this->addViolation(
					$context,
					'table.name',
					'Table.name must be specified explicitly.',
				);
			}
		}

		if (isset($context->arguments['name']) && is_string($context->arguments['name'])) {
			$this->checkQuotedIdentifier($context, 'name');
		}

		if (array_key_exists('uniqueConstraints', $context->arguments)) {
			$this->fixViolation(
				$context,
				'table.unique-constraints',
				'Table.uniqueConstraints is not maintained in Doctrine mapping; unique indexes are synchronized from the database.',
				static fn (): bool => $context->removeArgument('uniqueConstraints'),
			);
		}

		foreach ($this->configuration->forbiddenTableArguments as $argumentName) {
			if (!array_key_exists($argumentName, $context->arguments)) {
				continue;
			}

			$this->addViolation(
				$context,
				'table.' . $argumentName,
				sprintf('Table.%s is not maintained in Doctrine mapping.', $argumentName),
			);
		}
	}

	private function checkJoinTable(DoctrineAttributeContext $context): void
	{
		if (!array_key_exists('name', $context->arguments) || !is_string($context->arguments['name'])) {
			$propertyName = $context->property?->name;
			$associationMapping = $propertyName === null
				? null
				: ($context->classMetadata?->associationMappings[$propertyName] ?? null);
			if (!$associationMapping instanceof ManyToManyOwningSideMapping) {
				$this->addViolation(
					$context,
					'join-table.name',
					'JoinTable.name must be specified explicitly.',
				);

				return;
			}

			$this->fixViolation(
				$context,
				'join-table.name',
				'JoinTable.name must be specified explicitly.',
				static fn (): bool => $context->setArgument('name', $associationMapping->joinTable->name),
			);
		}

		$this->checkQuotedIdentifier($context, 'name');
	}

	private function checkToOneAssociation(DoctrineAttributeContext $context, string $attributeName): void
	{
		$this->checkCascadeOrder($context, $attributeName);

		if (($context->arguments['fetch'] ?? null) === 'LAZY') {
			$this->fixViolation(
				$context,
				strtolower($attributeName) . '.fetch',
				sprintf('%s.fetch is LAZY by default.', $attributeName),
				static fn (): bool => $context->removeArgument('fetch'),
			);
		}

		if ($attributeName === 'OneToOne' && ($context->arguments['orphanRemoval'] ?? null) === false) {
			$this->fixViolation(
				$context,
				'onetoone.orphan-removal',
				'OneToOne.orphanRemoval is false by default.',
				static fn (): bool => $context->removeArgument('orphanRemoval'),
			);
		}

		if ($context->property === null || !array_key_exists('targetEntity', $context->arguments)) {
			return;
		}

		$propertyType = $context->property->getType();
		if (!$propertyType instanceof ReflectionNamedType || $propertyType->isBuiltin()) {
			return;
		}

		$targetEntity = $context->arguments['targetEntity'];
		if (!is_string($targetEntity)) {
			$this->addViolation(
				$context,
				strtolower($attributeName) . '.target-entity',
				sprintf('%s.targetEntity must be a class name string.', $attributeName),
			);

			return;
		}

		$targetEntity = ltrim($targetEntity, '\\');
		if ($targetEntity === $propertyType->getName()) {
			$this->fixViolation(
				$context,
				strtolower($attributeName) . '.target-entity',
				sprintf('%s.targetEntity is inferred from the PHP property type.', $attributeName),
				static fn (): bool => $context->removeArgument('targetEntity'),
			);

			return;
		}

		$this->addViolation(
			$context,
			strtolower($attributeName) . '.target-entity',
			sprintf(
				'%s.targetEntity %s does not match the property type %s.',
				$attributeName,
				$targetEntity,
				$propertyType->getName(),
			),
		);
	}

	private function checkToManyAssociation(DoctrineAttributeContext $context, string $attributeName): void
	{
		$this->checkCascadeOrder($context, $attributeName);

		if (($context->arguments['fetch'] ?? null) === 'LAZY') {
			$this->fixViolation(
				$context,
				strtolower($attributeName) . '.fetch',
				sprintf('%s.fetch is LAZY by default.', $attributeName),
				static fn (): bool => $context->removeArgument('fetch'),
			);
		}

		if (($context->arguments['orphanRemoval'] ?? null) === false) {
			$this->fixViolation(
				$context,
				strtolower($attributeName) . '.orphan-removal',
				sprintf('%s.orphanRemoval is false by default.', $attributeName),
				static fn (): bool => $context->removeArgument('orphanRemoval'),
			);
		}
	}

	private function checkCascadeOrder(DoctrineAttributeContext $context, string $attributeName): void
	{
		$cascade = $context->arguments['cascade'] ?? null;
		if (!is_array($cascade)) {
			return;
		}

		$cascadeValues = [];
		foreach ($cascade as $action) {
			if (!is_string($action)) {
				$this->addViolation(
					$context,
					strtolower($attributeName) . '.cascade-values',
					sprintf('%s.cascade must contain only strings.', $attributeName),
				);

				return;
			}

			$cascadeValues[] = $action;
		}
		if ($cascadeValues === []) {
			return;
		}

		$sortedCascade = array_values(array_unique($cascadeValues));
		$order = array_flip($this->configuration->cascadeOrder);
		usort(
			$sortedCascade,
			static fn (string $a, string $b): int => ($order[$a] ?? count($order))
				<=> ($order[$b] ?? count($order))
				?: $a <=> $b,
		);
		if ($sortedCascade === $cascadeValues) {
			return;
		}

		$cascadeOrder = $this->configuration->cascadeOrder;
		$this->fixViolation(
			$context,
			strtolower($attributeName) . '.cascade-order',
			sprintf('%s.cascade must use the canonical order and contain no duplicates.', $attributeName),
			static fn (): bool => $context->normalizeStringArrayArgument('cascade', $cascadeOrder),
		);
	}

	private function checkEmbedded(DoctrineAttributeContext $context): void
	{
		if ($context->property === null || !array_key_exists('class', $context->arguments)) {
			return;
		}

		$propertyType = $context->property->getType();
		if (!$propertyType instanceof ReflectionNamedType || $propertyType->isBuiltin()) {
			return;
		}

		$embeddedClass = $context->arguments['class'];
		if (!is_string($embeddedClass)) {
			$this->addViolation(
				$context,
				'embedded.class',
				'Embedded.class must be a class name string.',
			);

			return;
		}

		$embeddedClass = ltrim($embeddedClass, '\\');
		if ($embeddedClass !== $propertyType->getName()) {
			return;
		}

		$this->fixViolation(
			$context,
			'embedded.class',
			'Embedded.class is inferred from the PHP property type.',
			static fn (): bool => $context->removeArgument('class'),
		);
	}

	private function checkIdentifierName(
		DoctrineAttributeContext $context,
		string $argumentName,
		?string $defaultName,
	): void
	{
		$value = $context->arguments[$argumentName] ?? null;
		if ($value === null && $defaultName !== null && $this->isReservedIdentifier($defaultName)) {
			$this->fixViolation(
				$context,
				'identifier.reserved',
				sprintf('The reserved MySQL identifier %s must be escaped with backticks.', $defaultName),
				static fn (): bool => $context->setArgument($argumentName, sprintf('`%s`', $defaultName)),
			);

			return;
		}

		if (!is_string($value)) {
			return;
		}

		$this->checkQuotedIdentifier($context, $argumentName);
		$plainName = $this->unquoteIdentifier($value);
		if (
			$defaultName !== null
			&& $plainName === $defaultName
			&& !$this->isReservedIdentifier($plainName)
		) {
			$this->fixViolation(
				$context,
				'identifier.default-name',
				sprintf('%s.%s matches its inferred name and is redundant.', $this->shortAttributeName($context), $argumentName),
				static fn (): bool => $context->removeArgument($argumentName),
			);
		}
	}

	private function checkQuotedIdentifier(DoctrineAttributeContext $context, string $argumentName): void
	{
		$value = $context->arguments[$argumentName] ?? null;
		if (!is_string($value)) {
			return;
		}

		$quoted = str_starts_with($value, '`') && str_ends_with($value, '`') && strlen($value) > 1;
		$plainName = $this->unquoteIdentifier($value);
		$mustBeQuoted = $this->isReservedIdentifier($plainName);
		if ($quoted === $mustBeQuoted) {
			return;
		}

		$expected = $mustBeQuoted ? sprintf('`%s`', $plainName) : $plainName;
		$this->fixViolation(
			$context,
			'identifier.quoting',
			sprintf('Identifier %s must be %s.', $plainName, $mustBeQuoted ? 'escaped with backticks' : 'unquoted'),
			static fn (): bool => $context->setArgument($argumentName, $expected),
		);
	}

	private function checkDatabaseColumn(DoctrineAttributeContext $context): void
	{
		$databaseSchema = $this->databaseSchema;
		if ($databaseSchema === null || $context->property === null || $context->classMetadata === null) {
			return;
		}

		$expectations = [];
		foreach ($this->getDatabaseFieldTargets($context->classMetadata, $context->property->name) as $target) {
			array_push(
				$expectations,
				...$this->checkDatabaseColumnInTable($context, $databaseSchema, $target['table'], $target['fieldMapping']),
			);
		}

		$this->applyDatabaseExpectations($context, $expectations);
	}

	/**
	 * Returns the tables and field mappings whose database columns are mapped by the given property.
	 * Properties of a mapped superclass are compared with the tables of all entities that inherit them.
	 *
	 * @param EntityClassMetadata $classMetadata
	 * @return list<array{table: string, fieldMapping: FieldMapping}>
	 */
	private function getDatabaseFieldTargets(ClassMetadata $classMetadata, string $propertyName): array
	{
		if (!$classMetadata->isMappedSuperclass) {
			$fieldMapping = $classMetadata->fieldMappings[$propertyName] ?? null;

			return $fieldMapping instanceof FieldMapping
				? [['table' => $classMetadata->getTableName(), 'fieldMapping' => $fieldMapping]]
				: [];
		}

		$targets = [];
		foreach ($this->getConcreteMetadataInheritingFrom($classMetadata->name) as $entityMetadata) {
			$fieldMapping = $entityMetadata->fieldMappings[$propertyName] ?? null;
			if (!$fieldMapping instanceof FieldMapping || $fieldMapping->declared !== $classMetadata->name) {
				continue;
			}

			$tableName = $entityMetadata->getTableName();
			$targets[sprintf('%s.%s', $tableName, $fieldMapping->columnName)] = [
				'table' => $tableName,
				'fieldMapping' => $fieldMapping,
			];
		}

		ksort($targets);

		return array_values($targets);
	}

	/**
	 * @param class-string $className
	 * @return list<EntityClassMetadata>
	 */
	private function getConcreteMetadataInheritingFrom(string $className): array
	{
		$result = [];
		foreach ($this->metadataByClassName as $metadata) {
			if (
				$metadata->isMappedSuperclass
				|| $metadata->isEmbeddedClass
				|| !is_subclass_of($metadata->name, $className)
			) {
				continue;
			}

			$result[] = $metadata;
		}

		return $result;
	}

	/**
	 * @return list<DatabaseExpectation>
	 */
	private function checkDatabaseColumnInTable(
		DoctrineAttributeContext $context,
		DatabaseSchemaSnapshot $databaseSchema,
		string $tableName,
		FieldMapping $fieldMapping,
	): array
	{
		$columnName = $fieldMapping->columnName;
		$databaseColumn = $databaseSchema->getColumn($tableName, $columnName);
		if ($databaseColumn === null) {
			$this->addViolation(
				$context,
				'database.column-missing',
				sprintf('Column %s.%s does not exist in database %s.', $tableName, $columnName, $databaseSchema->getSchema()),
			);

			return [];
		}

		if (!DbalTypeCompatibility::isCompatible($fieldMapping, $databaseColumn, $this->entityManager->getConnection())) {
			$this->addViolation(
				$context,
				'database.column-type',
				sprintf(
					'Doctrine type %s is incompatible with database type %s.',
					$fieldMapping->type,
					$databaseColumn->columnType,
				),
			);
		}

		if ($fieldMapping->nullable !== null && $databaseColumn->nullable !== $fieldMapping->nullable) {
			$this->addViolation(
				$context,
				'database.column-nullability',
				sprintf(
					'PHP/Doctrine nullability is %s but the database column is %s.',
					$fieldMapping->nullable ? 'nullable' : 'not nullable',
					$databaseColumn->nullable ? 'nullable' : 'not nullable',
				),
			);
		}

		$column = sprintf('%s.%s', $tableName, $columnName);
		$expectations = [];
		$expectations[] = new DatabaseExpectation(
			false,
			'unique',
			$databaseSchema->isSingleColumnUnique($tableName, $columnName) ? true : null,
			'database.column-unique',
			'Column.unique must match the single-column unique index in the database.',
			$column,
		);

		$isUuidType = str_starts_with(strtolower($fieldMapping->type), 'uuid');
		if (!$isUuidType && in_array($databaseColumn->dataType, self::LENGTH_DATABASE_TYPES, true)) {
			if ($databaseColumn->characterMaximumLength === null) {
				$this->addViolation(
					$context,
					'database.column-length',
					sprintf('The database length for %s.%s cannot be determined.', $tableName, $columnName),
				);
			} else {
				$expectations[] = new DatabaseExpectation(
					false,
					'length',
					$databaseColumn->characterMaximumLength,
					'database.column-length',
					'Column.length must match the database column length.',
					$column,
				);
			}
		} else {
			$expectations[] = new DatabaseExpectation(
				false,
				'length',
				null,
				'database.column-length',
				'Column.length is not applicable to this Doctrine/database type.',
				$column,
			);
		}

		if (in_array($databaseColumn->dataType, ['decimal', 'numeric'], true)) {
			if ($databaseColumn->numericPrecision === null || $databaseColumn->numericScale === null) {
				$this->addViolation(
					$context,
					'database.column-precision',
					sprintf('The decimal precision and scale for %s.%s cannot be determined.', $tableName, $columnName),
				);
			} else {
				$expectations[] = new DatabaseExpectation(
					false,
					'precision',
					$databaseColumn->numericPrecision,
					'database.column-precision',
					'Column.precision must match the database column precision.',
					$column,
				);
				$expectations[] = new DatabaseExpectation(
					false,
					'scale',
					$databaseColumn->numericScale,
					'database.column-scale',
					'Column.scale must match the database column scale.',
					$column,
				);
			}
		} else {
			foreach (['precision', 'scale'] as $argumentName) {
				$expectations[] = new DatabaseExpectation(
					false,
					$argumentName,
					null,
					'database.column-' . $argumentName,
					sprintf('Column.%s is not applicable to this Doctrine/database type.', $argumentName),
					$column,
				);
			}
		}

		$propertyType = $context->property?->getType();
		$isBooleanProperty = $propertyType instanceof ReflectionNamedType && $propertyType->getName() === 'bool';
		$isUnsigned = !$isBooleanProperty
			&& in_array($databaseColumn->dataType, self::NUMERIC_DATABASE_TYPES, true)
			&& str_contains($databaseColumn->columnType, 'unsigned');
		$expectations[] = new DatabaseExpectation(
			true,
			'unsigned',
			$isUnsigned ? true : null,
			'database.column-unsigned',
			'Column.options.unsigned must match the database column.',
			$column,
		);
		$expectations[] = new DatabaseExpectation(
			true,
			'fixed',
			$databaseColumn->dataType === 'char' ? true : null,
			'database.column-fixed',
			'Column.options.fixed must be true exactly for a database char column.',
			$column,
		);

		$tableCollation = $databaseSchema->getTableCollation($tableName);
		$columnCollation = $databaseColumn->collation;
		$expectations[] = new DatabaseExpectation(
			true,
			'collation',
			$columnCollation !== null && $columnCollation !== $tableCollation ? $columnCollation : null,
			'database.column-collation',
			'Column.options.collation must match a column-specific database collation.',
			$column,
		);

		$expectations[] = $databaseColumn->dataType === 'year'
			? new DatabaseExpectation(
				false,
				'columnDefinition',
				'YEAR',
				'database.column-definition',
				'Column.columnDefinition must be YEAR for a database year column.',
				$column,
			)
			: new DatabaseExpectation(
				false,
				'columnDefinition',
				null,
				'database.column-definition',
				'Column.columnDefinition is allowed only for a database year column.',
				$column,
			);

		return $expectations;
	}

	/**
	 * Synchronizes the attribute with the expected values. When a property maps several database columns
	 * (e.g. a mapped superclass property inherited by several entities) whose expected values differ,
	 * a non-fixable violation is reported instead.
	 *
	 * @param list<DatabaseExpectation> $expectations
	 */
	private function applyDatabaseExpectations(DoctrineAttributeContext $context, array $expectations): void
	{
		/** @var array<string, non-empty-list<DatabaseExpectation>> $groups */
		$groups = [];
		foreach ($expectations as $expectation) {
			$groups[($expectation->option ? 'option:' : 'argument:') . $expectation->name][] = $expectation;
		}

		foreach ($groups as $group) {
			$first = $group[0];
			$distinctValues = [];
			foreach ($group as $expectation) {
				$distinctValues[serialize($expectation->value)] = true;
			}

			if (count($distinctValues) > 1) {
				$this->addViolation(
					$context,
					$first->rule,
					sprintf(
						'%s.%s%s cannot match the database because the mapped columns differ: %s.',
						$this->shortAttributeName($context),
						$first->option ? 'options.' : '',
						$first->name,
						implode(', ', array_map(
							static fn (DatabaseExpectation $expectation): string => sprintf(
								'%s (%s)',
								$expectation->column,
								$expectation->formatValue(),
							),
							$group,
						)),
					),
				);

				continue;
			}

			if ($first->option) {
				$this->syncOption($context, $first->name, $first->value, $first->rule, $first->message);
			} else {
				$this->syncArgument($context, $first->name, $first->value, $first->rule, $first->message);
			}
		}
	}

	private function syncArgument(
		DoctrineAttributeContext $context,
		string $argumentName,
		string|int|float|bool|null $expectedValue,
		string $rule,
		string $message,
	): void
	{
		if ($expectedValue === null) {
			if (!array_key_exists($argumentName, $context->arguments)) {
				return;
			}

			$this->fixViolation(
				$context,
				$rule,
				$message,
				static fn (): bool => $context->removeArgument($argumentName),
			);

			return;
		}

		if (
			array_key_exists($argumentName, $context->arguments)
			&& $context->arguments[$argumentName] === $expectedValue
		) {
			return;
		}

		$this->fixViolation(
			$context,
			$rule,
			$message,
			static fn (): bool => $context->setArgument($argumentName, $expectedValue),
		);
	}

	private function syncOption(
		DoctrineAttributeContext $context,
		string $optionName,
		string|int|float|bool|null $expectedValue,
		string $rule,
		string $message,
	): void
	{
		$options = $context->arguments['options'] ?? [];
		$hasOption = is_array($options) && array_key_exists($optionName, $options);
		if ($expectedValue === null) {
			if (!$hasOption) {
				return;
			}

			$this->fixViolation(
				$context,
				$rule,
				$message,
				static fn (): bool => $context->removeOption($optionName),
			);

			return;
		}

		if ($hasOption && $options[$optionName] === $expectedValue) {
			return;
		}

		$this->fixViolation(
			$context,
			$rule,
			$message,
			static fn (): bool => $context->setOption($optionName, $expectedValue),
		);
	}

	private function checkDatabaseJoinColumn(DoctrineAttributeContext $context): void
	{
		$databaseSchema = $this->databaseSchema;
		if ($databaseSchema === null || $context->classMetadata === null) {
			return;
		}

		$expectations = [];
		foreach ($this->getDatabaseJoinColumnTargets($context) as $target) {
			$expectation = $this->checkDatabaseJoinColumnInTable(
				$context,
				$databaseSchema,
				$target['table'],
				$target['referencedTable'],
				$target['oneToOne'],
				$target['joinColumn'],
				$target['association'],
			);
			if ($expectation === null) {
				continue;
			}

			$expectations[] = $expectation;
		}

		$this->applyDatabaseExpectations($context, $expectations);
	}

	/**
	 * @return list<array{
	 *     table: string,
	 *     referencedTable: string,
	 *     oneToOne: bool,
	 *     joinColumn: JoinColumnMapping,
	 *     association: ?ToOneOwningSideMapping
	 * }>
	 */
	private function getDatabaseJoinColumnTargets(DoctrineAttributeContext $context): array
	{
		$classMetadata = $context->classMetadata;
		if ($classMetadata === null) {
			return [];
		}

		if (
			$context->databaseJoinColumn !== null
			&& $context->databaseTableName !== null
			&& $context->databaseReferencedTableName !== null
			&& (
				$context->databaseJoinTable
				|| !$classMetadata->isMappedSuperclass
			)
		) {
			return [[
				'table' => $context->databaseTableName,
				'referencedTable' => $context->databaseReferencedTableName,
				'oneToOne' => $context->databaseOneToOne,
				'joinColumn' => $context->databaseJoinColumn,
				'association' => null,
			]];
		}

		$propertyName = $context->property?->name;
		if ($propertyName === null) {
			return [];
		}

		$entityMetadataList = $classMetadata->isMappedSuperclass
			? $this->getConcreteMetadataInheritingFrom($classMetadata->name)
			: [$classMetadata];
		$targets = [];
		foreach ($entityMetadataList as $entityMetadata) {
			$associationMapping = $entityMetadata->associationMappings[$propertyName] ?? null;
			if (!$associationMapping instanceof ToOneOwningSideMapping) {
				continue;
			}

			if ($classMetadata->isMappedSuperclass && $associationMapping->declared !== $classMetadata->name) {
				continue;
			}

			$joinColumnMapping = $associationMapping->joinColumns[0] ?? null;
			if (!$joinColumnMapping instanceof JoinColumnMapping) {
				continue;
			}

			$targetMetadata = $this->metadataByClassName[$associationMapping->targetEntity]
				?? $this->entityManager->getClassMetadata($associationMapping->targetEntity);
			$tableName = $entityMetadata->getTableName();
			$targets[$tableName] = [
				'table' => $tableName,
				'referencedTable' => $targetMetadata->getTableName(),
				'oneToOne' => $associationMapping instanceof OneToOneOwningSideMapping,
				'joinColumn' => $joinColumnMapping,
				'association' => $associationMapping,
			];
		}

		ksort($targets);

		return array_values($targets);
	}

	private function checkDatabaseJoinColumnInTable(
		DoctrineAttributeContext $context,
		DatabaseSchemaSnapshot $databaseSchema,
		string $tableName,
		string $referencedTableName,
		bool $isOneToOne,
		JoinColumnMapping $joinColumnMapping,
		?ToOneOwningSideMapping $associationMapping,
	): ?DatabaseExpectation
	{
		$columnName = $context->arguments['name'] ?? $joinColumnMapping->name;
		if (!is_string($columnName)) {
			return null;
		}

		if ($associationMapping !== null) {
			foreach ($associationMapping->joinColumns as $associationJoinColumn) {
				if ($associationJoinColumn->name !== $columnName) {
					continue;
				}

				$joinColumnMapping = $associationJoinColumn;

				break;
			}
		}

		$databaseColumn = $databaseSchema->getColumn($tableName, $columnName);
		if ($databaseColumn === null) {
			$this->addViolation(
				$context,
				'database.join-column-missing',
				sprintf('Join column %s.%s does not exist in database %s.', $tableName, $columnName, $databaseSchema->getSchema()),
			);

			return null;
		}

		$expectedNullable = $joinColumnMapping->nullable ?? true;
		if ($databaseColumn->nullable !== $expectedNullable) {
			$this->addViolation(
				$context,
				'database.join-column-nullability',
				sprintf(
					'Doctrine join-column nullability is %s but the database column is %s.',
					$expectedNullable ? 'nullable' : 'not nullable',
					$databaseColumn->nullable ? 'nullable' : 'not nullable',
				),
			);
		}

		$this->checkDatabaseJoinColumnForeignKey(
			$context,
			$databaseSchema,
			$tableName,
			$columnName,
			$referencedTableName,
			$joinColumnMapping,
		);

		$databaseUnique = $databaseSchema->isSingleColumnUnique($tableName, $columnName);
		$databasePrimaryKey = $databaseSchema->isSingleColumnPrimaryKey($tableName, $columnName);
		if ($isOneToOne) {
			if (!$databaseUnique && !$databasePrimaryKey) {
				$this->addViolation(
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

	private function checkDatabaseJoinColumnForeignKey(
		DoctrineAttributeContext $context,
		DatabaseSchemaSnapshot $databaseSchema,
		string $tableName,
		string $columnName,
		string $referencedTableName,
		JoinColumnMapping $joinColumnMapping,
	): void
	{
		$foreignKeys = $databaseSchema->getForeignKeys($tableName, $columnName);
		if ($foreignKeys === []) {
			return;
		}

		$expectedReferencedTableName = $this->unquoteIdentifier($referencedTableName);
		$expectedReferencedColumnName = $this->unquoteIdentifier($joinColumnMapping->referencedColumnName);
		foreach ($foreignKeys as $foreignKey) {
			if ($databaseSchema->areTargetsEquivalentViaPrimaryKeyForeignKeys(
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
		$this->addViolation(
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

	/**
	 * @param class-string $className
	 * @param EntityClassMetadata $classMetadata
	 */
	private function checkDatabaseTable(
		string $file,
		ClassLike $classNode,
		string $className,
		ClassMetadata $classMetadata,
	): void
	{
		$databaseSchema = $this->databaseSchema;
		if (
			$databaseSchema === null
			|| $classMetadata->isMappedSuperclass
			|| $classMetadata->isEmbeddedClass
			|| (new ReflectionClass($className))->isAbstract()
		) {
			return;
		}

		$tableSchema = $classMetadata->table['schema'] ?? null;
		if (is_string($tableSchema) && $tableSchema !== $databaseSchema->getSchema()) {
			return;
		}

		$tableName = $classMetadata->getTableName();
		if (!$databaseSchema->hasTable($tableName)) {
			$this->addRawViolation(
				$file,
				$classNode->getStartLine(),
				$className,
				'database.table-missing',
				sprintf('Table %s does not exist in database %s.', $tableName, $databaseSchema->getSchema()),
				false,
			);
		}

		foreach ($classMetadata->associationMappings as $associationMapping) {
			if (!$associationMapping instanceof ManyToManyOwningSideMapping) {
				continue;
			}

			$joinTableName = $associationMapping->joinTable->name;
			if (!$databaseSchema->hasTable($joinTableName)) {
				$this->addRawViolation(
					$file,
					$classNode->getStartLine(),
					sprintf('%s::$%s', $className, $associationMapping->fieldName),
					'database.join-table-missing',
					sprintf(
						'Join table %s does not exist in database %s.',
						$joinTableName,
						$databaseSchema->getSchema(),
					),
					false,
				);
			}
		}
	}

	private function addRawViolation(
		string $file,
		int $line,
		string $target,
		string $rule,
		string $message,
		bool $fixable,
	): void
	{
		if (!$this->configuration->isRuleEnabled($rule)) {
			return;
		}

		$this->addUniqueViolation(new Violation($file, $line, $target, $rule, $message, $fixable));
	}

	private function getDefaultColumnName(DoctrineAttributeContext $context): ?string
	{
		if ($context->property === null) {
			return null;
		}

		return $this->entityManager->getConfiguration()->getNamingStrategy()
			->propertyToColumnName($context->property->name, $context->className);
	}

	private function getDefaultJoinColumnName(DoctrineAttributeContext $context): ?string
	{
		if ($context->property === null) {
			return null;
		}

		return $this->entityManager->getConfiguration()->getNamingStrategy()
			->joinColumnName($context->property->name, $context->className);
	}

	private function shortAttributeName(DoctrineAttributeContext $context): string
	{
		return substr($context->attributeClassName, strlen(self::MAPPING_NAMESPACE));
	}

	private function isReservedIdentifier(string $identifier): bool
	{
		return MySqlReservedWords::isReserved($identifier)
			|| $this->configuration->isAdditionalReservedWord($identifier);
	}

	private function unquoteIdentifier(string $identifier): string
	{
		if (str_starts_with($identifier, '`') && str_ends_with($identifier, '`') && strlen($identifier) > 1) {
			return substr($identifier, 1, -1);
		}

		return $identifier;
	}

	/** @param callable(): bool $fix */
	private function fixViolation(
		DoctrineAttributeContext $context,
		string $rule,
		string $message,
		callable $fix,
	): void
	{
		if (!$this->configuration->isRuleEnabled($rule)) {
			return;
		}

		if ($this->fix && $fix()) {
			$this->changed = true;

			return;
		}

		$this->addViolation($context, $rule, $message, !$this->fix);
	}

	private function addViolation(
		DoctrineAttributeContext $context,
		string $rule,
		string $message,
		bool $fixable = false,
	): void
	{
		if (!$this->configuration->isRuleEnabled($rule)) {
			return;
		}

		$this->addUniqueViolation(new Violation(
			$context->file,
			$context->line,
			$context->target,
			$rule,
			$message,
			$fixable,
		));
	}

	/**
	 * A property mapped to several database columns may produce the same violation more than once.
	 */
	private function addUniqueViolation(Violation $violation): void
	{
		foreach ($this->violations as $existingViolation) {
			if (
				$existingViolation->file === $violation->file
				&& $existingViolation->line === $violation->line
				&& $existingViolation->target === $violation->target
				&& $existingViolation->rule === $violation->rule
				&& $existingViolation->message === $violation->message
			) {
				return;
			}
		}

		$this->violations[] = $violation;
	}

}
