<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Checker;

use Slevomat\DoctrineAttributeChecker\Checker\Data\NullableEmbedded\NullableEmbeddedAddress;
use Slevomat\DoctrineAttributeChecker\Database\DatabaseColumn;
use Slevomat\DoctrineAttributeChecker\Database\DatabaseSchemaSnapshot;
use Slevomat\DoctrineAttributeChecker\Violation;

final class NullableEmbeddedTest extends FactoryMetadataTestCase
{

	private const string FILE = __DIR__ . '/data/NullableEmbedded/NullableEmbeddedEntities.php';

	public function testNullableColumnsOfNullableEmbeddedPropertiesAreAllowed(): void
	{
		$result = $this->inspect($this->createNullableEmbeddedSchema(), false);

		self::assertSame([], $this->formatViolations($result['violations'], 'database.'));
	}

	public function testNullableColumnOfNotNullableEmbeddedPropertyIsReported(): void
	{
		$result = $this->inspect($this->createNullableEmbeddedSchema([
			'nullable_embedded_required.address_city' => self::varcharColumn(80, true),
		]), false);

		self::assertSame(
			['database.column-nullability: PHP/Doctrine nullability is not nullable but the database column is nullable.'],
			$this->formatViolations($result['violations'], 'database.'),
		);
	}

	/**
	 * @return array{contents: string, changed: bool, violations: list<Violation>}
	 */
	private function inspect(DatabaseSchemaSnapshot $schema, bool $fix): array
	{
		return $this->inspectFixture(__DIR__ . '/data/NullableEmbedded', self::FILE, NullableEmbeddedAddress::class, $schema, $fix);
	}

	/**
	 * @param array<string, DatabaseColumn> $overrides
	 */
	private function createNullableEmbeddedSchema(array $overrides = []): DatabaseSchemaSnapshot
	{
		$columns = [
			'nullable_embedded_required.id' => new DatabaseColumn('int', 'int', null, 10, 0, false, null),
			'nullable_embedded_required.address_city' => self::varcharColumn(80, false),
			'nullable_embedded_optional.id' => new DatabaseColumn('int', 'int', null, 10, 0, false, null),
			'nullable_embedded_optional.address_city' => self::varcharColumn(80, true),
			'nullable_embedded_nested.id' => new DatabaseColumn('int', 'int', null, 10, 0, false, null),
			'nullable_embedded_nested.contact_address_city' => self::varcharColumn(80, true),
		];

		return $this->createSchema($overrides + $columns);
	}

}
