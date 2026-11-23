<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Checker;

use Slevomat\DoctrineAttributeChecker\Checker\Data\Embeddable\EmbeddableAddress;
use Slevomat\DoctrineAttributeChecker\Database\DatabaseColumn;
use Slevomat\DoctrineAttributeChecker\Database\DatabaseSchemaSnapshot;
use Slevomat\DoctrineAttributeChecker\Violation;

final class EmbeddableTest extends FactoryMetadataTestCase
{

	private const string FILE = __DIR__ . '/data/Embeddable/EmbeddableEntities.php';

	public function testPropertiesAreComparedWithColumnsOfEmbeddingEntities(): void
	{
		$result = $this->inspect($this->createEmbeddableSchema(), false);

		self::assertSame([], $this->formatViolations($result['violations'], 'database.'));
	}

	public function testColumnIsFixedWhenAllEmbeddingEntitiesAgree(): void
	{
		$schema = $this->createEmbeddableSchema([
			'embeddable_home.address_city' => self::varcharColumn(100, false),
			'embeddable_office.billing_city' => self::varcharColumn(100, false),
		]);

		$result = $this->inspect($schema, false);
		self::assertSame(
			['database.column-length: Column.length must match the database column length.'],
			$this->formatViolations($result['violations'], 'database.'),
		);

		$fixedResult = $this->inspect($schema, true);
		self::assertTrue($fixedResult['changed']);
		self::assertStringContainsString("#[ORM\\Column(length: 100)]\n\tpublic string \$city;", $fixedResult['contents']);
		self::assertSame([], $this->formatViolations($fixedResult['violations'], 'database.'));
	}

	public function testDifferentColumnsOfEmbeddingEntitiesAreReported(): void
	{
		$result = $this->inspect($this->createEmbeddableSchema([
			'embeddable_office.billing_city' => self::varcharColumn(100, false),
		]), false);

		self::assertSame(
			[
				'database.column-length: Column.length cannot match the database because the mapped columns differ: '
				. 'embeddable_home.address_city (80), embeddable_office.billing_city (100).',
			],
			$this->formatViolations($result['violations'], 'database.'),
		);
	}

	public function testNullablePropertyAllowsNotNullColumnWhenAnotherEmbeddingColumnIsNullable(): void
	{
		$result = $this->inspect($this->createEmbeddableSchema([
			'embeddable_office.billing_zip' => self::varcharColumn(10, false),
		]), false);

		self::assertSame([], $this->formatViolations($result['violations'], 'database.'));
	}

	public function testNullablePropertyIsReportedWhenNoEmbeddingColumnIsNullable(): void
	{
		$result = $this->inspect($this->createEmbeddableSchema([
			'embeddable_home.address_zip' => self::varcharColumn(10, false),
			'embeddable_office.billing_zip' => self::varcharColumn(10, false),
		]), false);

		self::assertSame(
			['database.column-nullability: PHP/Doctrine nullability is nullable but the database column is not nullable.'],
			$this->formatViolations($result['violations'], 'database.'),
		);
	}

	public function testNotNullablePropertyIsReportedForNullableEmbeddingColumn(): void
	{
		$result = $this->inspect($this->createEmbeddableSchema([
			'embeddable_office.billing_city' => self::varcharColumn(80, true),
		]), false);

		self::assertSame(
			['database.column-nullability: PHP/Doctrine nullability is not nullable but the database column is nullable.'],
			$this->formatViolations($result['violations'], 'database.'),
		);
	}

	public function testMissingColumnIsReported(): void
	{
		$columns = [
			'embeddable_home.id' => new DatabaseColumn('int', 'int', null, 10, 0, false, null),
			'embeddable_home.address_city' => self::varcharColumn(80, false),
			'embeddable_home.address_zip' => self::varcharColumn(10, true),
		];

		$result = $this->inspect($this->createSchema($columns), false);

		self::assertSame(
			[
				'database.column-missing: Column embeddable_office.billing_city does not exist in database attribute_check.',
				'database.column-missing: Column embeddable_office.billing_zip does not exist in database attribute_check.',
			],
			$this->formatViolations($result['violations'], 'database.'),
		);
	}

	/**
	 * @return array{contents: string, changed: bool, violations: list<Violation>}
	 */
	private function inspect(DatabaseSchemaSnapshot $schema, bool $fix): array
	{
		return $this->inspectFixture(__DIR__ . '/data/Embeddable', self::FILE, EmbeddableAddress::class, $schema, $fix);
	}

	/**
	 * @param array<string, DatabaseColumn> $overrides
	 */
	private function createEmbeddableSchema(array $overrides = []): DatabaseSchemaSnapshot
	{
		$columns = [
			'embeddable_home.id' => new DatabaseColumn('int', 'int', null, 10, 0, false, null),
			'embeddable_home.address_city' => self::varcharColumn(80, false),
			'embeddable_home.address_zip' => self::varcharColumn(10, true),
			'embeddable_office.id' => new DatabaseColumn('int', 'int', null, 10, 0, false, null),
			'embeddable_office.billing_city' => self::varcharColumn(80, false),
			'embeddable_office.billing_zip' => self::varcharColumn(10, true),
		];

		return $this->createSchema($overrides + $columns);
	}

}
