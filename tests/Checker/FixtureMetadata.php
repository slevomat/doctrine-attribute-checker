<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Checker;

use Doctrine\ORM\Configuration;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\DefaultTypedFieldMapper;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\Persistence\Mapping\RuntimeReflectionService;
use Slevomat\DoctrineAttributeChecker\Checker\Data\FixtureAddress;
use Slevomat\DoctrineAttributeChecker\Checker\Data\FixtureAssociationEntity;
use Slevomat\DoctrineAttributeChecker\Checker\Data\FixtureBasicEntity;
use Slevomat\DoctrineAttributeChecker\Checker\Data\FixtureBooleanEntity;
use Slevomat\DoctrineAttributeChecker\Checker\Data\FixtureCompositeAssociationEntity;
use Slevomat\DoctrineAttributeChecker\Checker\Data\FixtureCompositeTargetEntity;
use Slevomat\DoctrineAttributeChecker\Checker\Data\FixtureReservedEntity;
use Slevomat\DoctrineAttributeChecker\Checker\Data\FixtureTargetEntity;
use Slevomat\DoctrineAttributeChecker\Checker\Data\FixtureTypedAssociationEntity;
use Slevomat\DoctrineAttributeChecker\Checker\Data\FixtureTypedOneToOneEntity;

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols
require_once __DIR__ . '/data/DoctrineAttributeCheckerEntities.php';

final class FixtureMetadata
{

	public const string FIXTURE_FILE = __DIR__ . '/data/DoctrineAttributeCheckerEntities.php';

	public static function createOrmConfiguration(): Configuration
	{
		$configuration = new Configuration();
		$configuration->setNamingStrategy(new FixtureNamingStrategy());
		$configuration->setTypedFieldMapper(new DefaultTypedFieldMapper());

		return $configuration;
	}

	/**
	 * @return array<class-string, ClassMetadata<object>>
	 */
	public static function load(Configuration $configuration): array
	{
		$classes = [
			FixtureAddress::class,
			FixtureAssociationEntity::class,
			FixtureBasicEntity::class,
			FixtureBooleanEntity::class,
			FixtureCompositeAssociationEntity::class,
			FixtureCompositeTargetEntity::class,
			FixtureReservedEntity::class,
			FixtureTargetEntity::class,
			FixtureTypedAssociationEntity::class,
			FixtureTypedOneToOneEntity::class,
		];
		$driver = new AttributeDriver([__DIR__ . '/data']);
		$reflectionService = new RuntimeReflectionService();
		$metadataByClassName = [];
		foreach ($classes as $className) {
			$metadata = new ClassMetadata(
				$className,
				$configuration->getNamingStrategy(),
				$configuration->getTypedFieldMapper(),
			);
			$metadata->initializeReflection($reflectionService);
			$driver->loadMetadataForClass($className, $metadata);
			$metadataByClassName[$className] = $metadata;
		}

		return $metadataByClassName;
	}

}
