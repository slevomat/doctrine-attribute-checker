<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Checker;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\Configuration as OrmConfiguration;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use function is_subclass_of;

/**
 * @phpstan-type EntityClassMetadata ClassMetadata<object>
 */
final class DoctrineMapping
{

	/**
	 * @param array<class-string, EntityClassMetadata> $metadataByClassName
	 */
	public function __construct(
		private EntityManagerInterface $entityManager,
		private array $metadataByClassName,
	)
	{
	}

	public function getOrmConfiguration(): OrmConfiguration
	{
		return $this->entityManager->getConfiguration();
	}

	public function getConnection(): Connection
	{
		return $this->entityManager->getConnection();
	}

	/**
	 * @param class-string $className
	 * @return EntityClassMetadata
	 */
	public function getMetadata(string $className): ClassMetadata
	{
		return $this->metadataByClassName[$className] ?? $this->entityManager->getClassMetadata($className);
	}

	/**
	 * @return array<class-string, EntityClassMetadata>
	 */
	public function getAllMetadata(): array
	{
		return $this->metadataByClassName;
	}

	/**
	 * @param class-string $className
	 * @return list<EntityClassMetadata>
	 */
	public function getConcreteMetadataInheritingFrom(string $className): array
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
	 * Columns of properties declared in a subclass of a single table inheritance hierarchy are shared with
	 * the other subclasses, so they may be nullable in the database even when the property is not.
	 *
	 * @param EntityClassMetadata $entityMetadata
	 * @param ?class-string $inheritedFrom Entity class the mapping is inherited from.
	 */
	public function isDeclaredInSingleTableSubclass(ClassMetadata $entityMetadata, ?string $inheritedFrom): bool
	{
		if (!$entityMetadata->isInheritanceTypeSingleTable() || $entityMetadata->name === $entityMetadata->rootEntityName) {
			return false;
		}

		return $inheritedFrom === null || $inheritedFrom !== $entityMetadata->rootEntityName;
	}

	public function getDefaultColumnName(DoctrineAttributeContext $context): ?string
	{
		if ($context->property === null) {
			return null;
		}

		return $this->entityManager->getConfiguration()->getNamingStrategy()
			->propertyToColumnName($context->property->name, $context->className);
	}

	public function getDefaultJoinColumnName(DoctrineAttributeContext $context): ?string
	{
		if ($context->property === null) {
			return null;
		}

		return $this->entityManager->getConfiguration()->getNamingStrategy()
			->joinColumnName($context->property->name, $context->className);
	}

}
