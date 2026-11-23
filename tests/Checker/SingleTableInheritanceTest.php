<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Checker;

use Slevomat\DoctrineAttributeChecker\Checker\Data\Inheritance\InheritanceCarEntity;
use Slevomat\DoctrineAttributeChecker\Checker\Data\Inheritance\InheritanceVehicleEntity;
use Slevomat\DoctrineAttributeChecker\Database\DatabaseColumn;
use Slevomat\DoctrineAttributeChecker\Database\DatabaseSchemaSnapshot;
use Slevomat\DoctrineAttributeChecker\Violation;

final class SingleTableInheritanceTest extends FactoryMetadataTestCase
{

	private const string FILE = __DIR__ . '/data/Inheritance/InheritanceEntities.php';

	public function testSubclassColumnsMayBeNullableInDatabase(): void
	{
		$result = $this->inspect(InheritanceCarEntity::class, $this->createVehicleSchema());

		self::assertSame([], $this->formatViolations($result['violations'], 'database.'));
	}

	public function testSubclassColumnsMayBeNotNullableInDatabase(): void
	{
		$result = $this->inspect(InheritanceCarEntity::class, $this->createVehicleSchema([
			'inheritance_vehicle.plate' => self::varcharColumn(10, false),
			'inheritance_vehicle.driver_id' => self::charColumn(36, false),
		]));

		self::assertSame([], $this->formatViolations($result['violations'], 'database.'));
	}

	public function testNullableSubclassPropertyMustBeNullableInDatabase(): void
	{
		$result = $this->inspect(InheritanceCarEntity::class, $this->createVehicleSchema([
			'inheritance_vehicle.color' => self::varcharColumn(10, false),
		]));

		self::assertSame(
			['database.column-nullability: PHP/Doctrine nullability is nullable but the database column is not nullable.'],
			$this->formatViolations($result['violations'], 'database.'),
		);
	}

	public function testRootColumnsKeepNullabilityCheck(): void
	{
		$result = $this->inspect(InheritanceVehicleEntity::class, $this->createVehicleSchema([
			'inheritance_vehicle.name' => self::varcharColumn(20, true),
		]));

		self::assertSame(
			['database.column-nullability: PHP/Doctrine nullability is not nullable but the database column is nullable.'],
			$this->formatViolations($result['violations'], 'database.'),
		);
	}

	/**
	 * @param class-string $className
	 * @return array{contents: string, changed: bool, violations: list<Violation>}
	 */
	private function inspect(string $className, DatabaseSchemaSnapshot $schema): array
	{
		return $this->inspectFixture(__DIR__ . '/data/Inheritance', self::FILE, $className, $schema, false);
	}

	/**
	 * @param array<string, DatabaseColumn> $overrides
	 */
	private function createVehicleSchema(array $overrides = []): DatabaseSchemaSnapshot
	{
		$columns = [
			'inheritance_target.id' => self::charColumn(36, false),
			'inheritance_vehicle.id' => self::charColumn(36, false),
			'inheritance_vehicle.type' => self::varcharColumn(255, false),
			'inheritance_vehicle.name' => self::varcharColumn(20, false),
			'inheritance_vehicle.plate' => self::varcharColumn(10, true),
			'inheritance_vehicle.color' => self::varcharColumn(10, true),
			'inheritance_vehicle.driver_id' => self::charColumn(36, true),
		];

		return $this->createSchema($overrides + $columns);
	}

}
