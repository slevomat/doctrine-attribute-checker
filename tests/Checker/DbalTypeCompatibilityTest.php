<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Checker;

use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Types\TypeRegistry;
use Doctrine\ORM\Mapping\FieldMapping;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slevomat\DoctrineAttributeChecker\Database\DatabaseColumn;

class DbalTypeCompatibilityTest extends TestCase
{

	#[DataProvider('dataCompatibility')]
	public function testCompatibility(
		string $fieldType,
		string $databaseType,
		string $columnType,
		?string $columnDefinition,
		bool $expected,
	): void
	{
		$connection = self::createStub(Connection::class);
		$connection->method('getDatabasePlatform')
			->willReturn(new MySQLPlatform());
		$dbalConfiguration = new DbalConfiguration();
		$dbalConfiguration->setTypeProvider(new TypeRegistry());
		$connection->method('getConfiguration')
			->willReturn($dbalConfiguration);
		$fieldMapping = FieldMapping::fromMappingArray([
			'type' => $fieldType,
			'fieldName' => 'value',
			'columnName' => 'value',
			'length' => 32,
			'precision' => 10,
			'scale' => 2,
			'columnDefinition' => $columnDefinition,
		]);
		$databaseColumn = new DatabaseColumn($databaseType, $columnType, null, null, null, false, null);

		self::assertSame($expected, DbalTypeCompatibility::isCompatible($fieldMapping, $databaseColumn, $connection));
	}

	/**
	 * @return list<array{0: string, 1: string, 2: string, 3: ?string, 4: bool}>
	 */
	public static function dataCompatibility(): array
	{
		return [
			['integer', 'int', 'int', null, true],
			['integer', 'bigint', 'bigint', null, true],
			['integer', 'varchar', 'varchar(32)', null, false],
			['decimal', 'double', 'double', null, true],
			['decimal', 'int', 'int', null, true],
			['decimal', 'varchar', 'varchar(32)', null, false],
			['string', 'varchar', 'varchar(32)', null, true],
			['string', 'text', 'text', null, true],
			['string', 'blob', 'blob', null, true],
			['string', 'datetime', 'datetime', null, true],
			['string', 'time', 'time', null, true],
			['string', 'year', 'year', null, true],
			['binary', 'blob', 'blob', null, true],
			['binary', 'varchar', 'varchar(32)', null, false],
			['date', 'datetime', 'datetime', null, true],
			['date', 'varchar', 'varchar(32)', null, false],
			['time', 'time', 'time', null, true],
			['json', 'json', 'json', null, true],
			['string', 'int', 'int(11)', 'INT(11)', true],
			['string', 'varchar', 'varchar(32)', '()', false],
		];
	}

}
