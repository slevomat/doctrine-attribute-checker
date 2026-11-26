<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Checker;

use Slevomat\DoctrineAttributeChecker\Checker\Data\Inheritance\InheritanceBaseEntity;
use Slevomat\DoctrineAttributeChecker\Database\DatabaseColumn;
use Slevomat\DoctrineAttributeChecker\Database\DatabaseSchemaSnapshot;
use Slevomat\DoctrineAttributeChecker\Violation;
use function substr_count;

final class MappedSuperclassTest extends FactoryMetadataTestCase
{

	private const string FILE = __DIR__ . '/data/Inheritance/InheritanceEntities.php';

	public function testPropertiesAreComparedWithTablesOfInheritingEntities(): void
	{
		$result = $this->inspect($this->createInheritanceSchema(), false);

		self::assertSame([], $this->formatViolations($result['violations']));
	}

	public function testDifferentColumnsOfInheritingEntitiesAreReported(): void
	{
		$schema = $this->createInheritanceSchema([
			'inheritance_second.id' => self::charColumn(40, false),
		]);

		$result = $this->inspect($schema, false);
		self::assertSame(
			[
				'database.column-length: Column.length cannot match the database because the mapped columns differ: '
				. 'inheritance_first.id (36), inheritance_second.id (40).',
			],
			$this->formatViolations($result['violations']),
		);
		self::assertFalse($result['violations'][0]->fixable);

		$fixedResult = $this->inspect($schema, true);
		self::assertFalse($fixedResult['changed']);
	}

	public function testColumnIsFixedWhenAllInheritingEntitiesAgree(): void
	{
		$schema = $this->createInheritanceSchema([
			'inheritance_first.id' => self::charColumn(40, false),
			'inheritance_second.id' => self::charColumn(40, false),
		]);

		$result = $this->inspect($schema, false);
		self::assertSame(
			['database.column-length: Column.length must match the database column length.'],
			$this->formatViolations($result['violations']),
		);
		self::assertTrue($result['violations'][0]->fixable);

		$fixedResult = $this->inspect($schema, true);
		self::assertTrue($fixedResult['changed']);
		self::assertSame([], $fixedResult['violations']);
		self::assertSame(1, substr_count($fixedResult['contents'], "#[ORM\\Column(length: 40, options: ['fixed' => true])]"));
	}

	public function testJoinColumnsAreComparedWithTablesOfInheritingEntities(): void
	{
		$schema = $this->createInheritanceSchema([
			'inheritance_second.owner_id' => self::charColumn(36, false),
		]);

		$result = $this->inspect($schema, false);
		self::assertSame(
			['database.join-column-rejects-null: Doctrine join-column nullability is nullable but the database column is not nullable.'],
			$this->formatViolations($result['violations']),
		);
	}

	/**
	 * @return array{contents: string, changed: bool, violations: list<Violation>}
	 */
	private function inspect(DatabaseSchemaSnapshot $schema, bool $fix): array
	{
		return $this->inspectFixture(__DIR__ . '/data/Inheritance', self::FILE, InheritanceBaseEntity::class, $schema, $fix);
	}

	/**
	 * @param array<string, DatabaseColumn> $overrides
	 */
	private function createInheritanceSchema(array $overrides = []): DatabaseSchemaSnapshot
	{
		$columns = [
			'inheritance_target.id' => self::charColumn(36, false),
			'inheritance_first.id' => self::charColumn(36, false),
			'inheritance_first.owner_id' => self::charColumn(36, true),
			'inheritance_second.id' => self::charColumn(36, false),
			'inheritance_second.owner_id' => self::charColumn(36, true),
		];

		return $this->createSchema($overrides + $columns);
	}

}
