<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Checker;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\JoinColumnMapping;
use Doctrine\ORM\Mapping\ManyToManyOwningSideMapping;
use Doctrine\ORM\Mapping\OneToOneOwningSideMapping;
use Doctrine\ORM\Mapping\ToOneOwningSideMapping;
use PhpParser\Node;
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
use function class_exists;
use function count;
use function in_array;
use function sprintf;
use function str_starts_with;

/**
 * @phpstan-type EntityClassMetadata ClassMetadata<object>
 * @phpstan-type EntityReflection ReflectionClass<object>
 */
final class DoctrineAttributeChecker
{

	private ?ParsedSourceFile $parsedSourceFile = null;

	private ViolationReporter $reporter;

	private DoctrineMapping $mapping;

	private Identifiers $identifiers;

	private RuleSet $ruleSet;

	private JoinTableNormalizer $joinTableNormalizer;

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
		$this->joinTableNormalizer = new JoinTableNormalizer(
			$sourceEditor,
			$this->mapping,
			$this->identifiers,
			$this->ruleSet,
			$this->reporter,
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
		if ($classMetadata !== null && $associationMapping instanceof ManyToManyOwningSideMapping) {
			$this->joinTableNormalizer->process(
				$propertyNode,
				$reflectionProperty,
				$className,
				$file,
				$classMetadata,
				$associationMapping,
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
