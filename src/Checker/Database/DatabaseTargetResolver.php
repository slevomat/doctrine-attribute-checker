<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Checker\Database;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\FieldMapping;
use Doctrine\ORM\Mapping\JoinColumnMapping;
use Doctrine\ORM\Mapping\OneToOneOwningSideMapping;
use Doctrine\ORM\Mapping\ToOneOwningSideMapping;
use ReflectionNamedType;
use ReflectionProperty;
use Slevomat\DoctrineAttributeChecker\Checker\DoctrineAttributeContext;
use Slevomat\DoctrineAttributeChecker\Checker\DoctrineMapping;
use function array_values;
use function class_exists;
use function explode;
use function is_string;
use function ksort;
use function property_exists;
use function sprintf;

/**
 * @phpstan-import-type EntityClassMetadata from DoctrineMapping
 */
final class DatabaseTargetResolver
{

	public function __construct(
		private DoctrineMapping $mapping,
	)
	{
	}

	/**
	 * Returns the tables and field mappings whose database columns are mapped by the given property.
	 * Properties of a mapped superclass are compared with the tables of all entities that inherit them.
	 *
	 * @param EntityClassMetadata $classMetadata
	 * @return list<DatabaseFieldTarget>
	 */
	public function getFieldTargets(ClassMetadata $classMetadata, string $propertyName): array
	{
		if ($classMetadata->isEmbeddedClass) {
			return $this->getEmbeddableFieldTargets($classMetadata->name, $propertyName);
		}

		if (!$classMetadata->isMappedSuperclass) {
			$fieldMapping = $classMetadata->fieldMappings[$propertyName] ?? null;

			return $fieldMapping instanceof FieldMapping
				? [new DatabaseFieldTarget(
					$classMetadata->getTableName(),
					$fieldMapping,
					$this->mapping->isDeclaredInSingleTableSubclass($classMetadata, $fieldMapping->inherited),
				)]
				: [];
		}

		$targets = [];
		foreach ($this->mapping->getConcreteMetadataInheritingFrom($classMetadata->name) as $entityMetadata) {
			$fieldMapping = $entityMetadata->fieldMappings[$propertyName] ?? null;
			if (!$fieldMapping instanceof FieldMapping || $fieldMapping->declared !== $classMetadata->name) {
				continue;
			}

			$tableName = $entityMetadata->getTableName();
			$targets[sprintf('%s.%s', $tableName, $fieldMapping->columnName)] = new DatabaseFieldTarget(
				$tableName,
				$fieldMapping,
				$this->mapping->isDeclaredInSingleTableSubclass($entityMetadata, $fieldMapping->inherited),
			);
		}

		ksort($targets);

		return array_values($targets);
	}

	/**
	 * @return list<DatabaseJoinColumnTarget>
	 */
	public function getJoinColumnTargets(DoctrineAttributeContext $context): array
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
			return [new DatabaseJoinColumnTarget(
				$context->databaseTableName,
				$context->databaseReferencedTableName,
				$context->databaseOneToOne,
				$context->databaseJoinColumn,
				null,
				!$context->databaseJoinTable && $this->mapping->isDeclaredInSingleTableSubclass(
					$classMetadata,
					$context->property === null
						? null
						: ($classMetadata->associationMappings[$context->property->name] ?? null)?->inherited,
				),
			)];
		}

		$propertyName = $context->property?->name;
		if ($propertyName === null) {
			return [];
		}

		$entityMetadataList = $classMetadata->isMappedSuperclass
			? $this->mapping->getConcreteMetadataInheritingFrom($classMetadata->name)
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

			$targetMetadata = $this->mapping->getMetadata($associationMapping->targetEntity);
			$tableName = $entityMetadata->getTableName();
			$targets[$tableName] = new DatabaseJoinColumnTarget(
				$tableName,
				$targetMetadata->getTableName(),
				$associationMapping instanceof OneToOneOwningSideMapping,
				$joinColumnMapping,
				$associationMapping,
				$this->mapping->isDeclaredInSingleTableSubclass($entityMetadata, $associationMapping->inherited),
			);
		}

		ksort($targets);

		return array_values($targets);
	}

	public function getJoinColumnName(DoctrineAttributeContext $context, DatabaseJoinColumnTarget $target): ?string
	{
		$columnName = $context->arguments['name'] ?? $target->joinColumn->name;

		return is_string($columnName) ? $columnName : null;
	}

	public function getJoinColumnMapping(DatabaseJoinColumnTarget $target, string $columnName): JoinColumnMapping
	{
		foreach ($target->association->joinColumns ?? [] as $associationJoinColumn) {
			if ($associationJoinColumn->name === $columnName) {
				return $associationJoinColumn;
			}
		}

		return $target->joinColumn;
	}

	/**
	 * Properties of an embeddable are compared with the columns of all entities that embed it.
	 *
	 * @param class-string $className
	 * @return list<DatabaseFieldTarget>
	 */
	private function getEmbeddableFieldTargets(string $className, string $propertyName): array
	{
		$targets = [];
		foreach ($this->mapping->getAllMetadata() as $entityMetadata) {
			if ($entityMetadata->isMappedSuperclass || $entityMetadata->isEmbeddedClass) {
				continue;
			}

			foreach ($entityMetadata->fieldMappings as $fieldMapping) {
				if ($fieldMapping->originalClass !== $className || $fieldMapping->originalField !== $propertyName) {
					continue;
				}

				$tableName = $entityMetadata->getTableName();
				$targets[sprintf('%s.%s', $tableName, $fieldMapping->columnName)] = new DatabaseFieldTarget(
					$tableName,
					$fieldMapping,
					$this->mapping->isDeclaredInSingleTableSubclass($entityMetadata, $fieldMapping->inherited)
						|| $this->isEmbeddedInNullableProperty($entityMetadata, $fieldMapping),
				);
			}
		}

		ksort($targets);

		return array_values($targets);
	}

	/**
	 * Columns of an embeddable embedded by a nullable property (directly or through a nullable outer embeddable)
	 * are NULL when the property is null, so they may be nullable in the database even when the embeddable
	 * property is not.
	 *
	 * @param EntityClassMetadata $entityMetadata
	 */
	private function isEmbeddedInNullableProperty(ClassMetadata $entityMetadata, FieldMapping $fieldMapping): bool
	{
		if ($fieldMapping->declaredField === null) {
			return false;
		}

		$className = $entityMetadata->name;
		foreach (explode('.', $fieldMapping->declaredField) as $propertyName) {
			if (!class_exists($className) || !property_exists($className, $propertyName)) {
				return false;
			}

			$propertyType = (new ReflectionProperty($className, $propertyName))->getType();
			if ($propertyType === null || $propertyType->allowsNull()) {
				return true;
			}

			if (!$propertyType instanceof ReflectionNamedType || $propertyType->isBuiltin()) {
				return false;
			}

			$className = $propertyType->getName();
		}

		return false;
	}

}
