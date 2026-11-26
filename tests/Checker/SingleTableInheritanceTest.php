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

	private const string ALLOW_INHERITANCE = "attributes:\n\tforbidden: []\n";

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
			['database.column-rejects-null: PHP/Doctrine nullability is nullable but the database column is not nullable.'],
			$this->formatViolations($result['violations'], 'database.'),
		);
	}

	public function testRootColumnsKeepNullabilityCheck(): void
	{
		$result = $this->inspect(InheritanceVehicleEntity::class, $this->createVehicleSchema([
			'inheritance_vehicle.name' => self::varcharColumn(20, true),
		]));

		self::assertSame(
			['database.column-allows-null: PHP/Doctrine nullability is not nullable but the database column is nullable.'],
			$this->formatViolations($result['violations'], 'database.'),
		);
	}

	public function testDiscriminatorColumnMatchesDatabase(): void
	{
		$result = $this->inspect(InheritanceVehicleEntity::class, $this->createVehicleSchema(), configuration: self::ALLOW_INHERITANCE);

		self::assertSame([], $this->formatViolations($result['violations']));
	}

	public function testDiscriminatorColumnIsFixedFromDatabase(): void
	{
		$schema = $this->createVehicleSchema([
			'inheritance_vehicle.type' => new DatabaseColumn('varchar', 'varchar(30)', 30, null, null, false, 'ascii_general_ci'),
		]);

		$result = $this->inspect(InheritanceVehicleEntity::class, $schema, configuration: self::ALLOW_INHERITANCE);
		self::assertSame(
			[
				'database.column-length: DiscriminatorColumn.length must match the database column length.',
				'database.column-collation: DiscriminatorColumn.options.collation must match a column-specific database collation.',
			],
			$this->formatViolations($result['violations']),
		);

		$fixedResult = $this->inspect(InheritanceVehicleEntity::class, $schema, true, self::ALLOW_INHERITANCE);
		self::assertSame([], $this->formatViolations($fixedResult['violations']));
		self::assertStringContainsString(
			"#[ORM\\DiscriminatorColumn(name: 'type', length: 30, options: ['collation' => 'ascii_general_ci'])]",
			$fixedResult['contents'],
		);
	}

	public function testMissingOrNullableDiscriminatorColumnIsReported(): void
	{
		$result = $this->inspect(InheritanceVehicleEntity::class, $this->createVehicleSchema([
			'inheritance_vehicle.type' => self::varcharColumn(20, true),
		]), configuration: self::ALLOW_INHERITANCE);
		self::assertSame(
			['database.column-allows-null: PHP/Doctrine nullability is not nullable but the database column is nullable.'],
			$this->formatViolations($result['violations']),
		);

		$columns = [
			'inheritance_vehicle.id' => self::charColumn(36, false),
			'inheritance_vehicle.name' => self::varcharColumn(20, false),
		];
		$result = $this->inspect(InheritanceVehicleEntity::class, $this->createSchema($columns), configuration: self::ALLOW_INHERITANCE);
		self::assertSame(
			['database.column-missing: Column inheritance_vehicle.type does not exist in database attribute_check.'],
			$this->formatViolations($result['violations']),
		);
	}

	/**
	 * @param class-string $className
	 * @return array{contents: string, changed: bool, violations: list<Violation>}
	 */
	private function inspect(
		string $className,
		DatabaseSchemaSnapshot $schema,
		bool $fix = false,
		?string $configuration = null,
	): array
	{
		return $this->inspectFixture(__DIR__ . '/data/Inheritance', self::FILE, $className, $schema, $fix, $configuration);
	}

	/**
	 * @param array<string, DatabaseColumn> $overrides
	 */
	private function createVehicleSchema(array $overrides = []): DatabaseSchemaSnapshot
	{
		$columns = [
			'inheritance_target.id' => self::charColumn(36, false),
			'inheritance_vehicle.id' => self::charColumn(36, false),
			'inheritance_vehicle.type' => self::varcharColumn(20, false),
			'inheritance_vehicle.name' => self::varcharColumn(20, false),
			'inheritance_vehicle.plate' => self::varcharColumn(10, true),
			'inheritance_vehicle.color' => self::varcharColumn(10, true),
			'inheritance_vehicle.driver_id' => self::charColumn(36, true),
		];

		return $this->createSchema($overrides + $columns);
	}

}
