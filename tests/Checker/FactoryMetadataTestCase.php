<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Checker;

use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Types\TypeRegistry;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use PHPUnit\Framework\TestCase;
use Slevomat\DoctrineAttributeChecker\Database\DatabaseColumn;
use Slevomat\DoctrineAttributeChecker\Database\DatabaseSchemaSnapshot;
use function array_values;
use function explode;
use function str_contains;
use const CASE_LOWER;

/**
 * Loads fixture metadata through the real ClassMetadataFactory, so that inheritance and embeddables are resolved.
 */
abstract class FactoryMetadataTestCase extends TestCase
{

	protected function createEntityManager(string $directory): EntityManager
	{
		$configuration = new Configuration();
		$configuration->setMetadataDriverImpl(new AttributeDriver([$directory]));
		$configuration->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER));
		$configuration->enableNativeLazyObjects(true);

		return new EntityManager($this->createConnection(), $configuration);
	}

	/**
	 * @return array<class-string, ClassMetadata<object>>
	 */
	protected function loadAll(EntityManager $entityManager): array
	{
		$metadataByClassName = [];
		foreach ($entityManager->getMetadataFactory()->getAllMetadata() as $metadata) {
			$metadataByClassName[$metadata->name] = $metadata;
		}

		return $metadataByClassName;
	}

	/**
	 * @param array<string, DatabaseColumn> $columns Columns keyed by table.column.
	 * @param list<string> $uniqueColumns
	 */
	protected function createSchema(array $columns, array $uniqueColumns = []): DatabaseSchemaSnapshot
	{
		$tableRows = [];
		$columnRows = [];
		foreach ($columns as $key => $column) {
			[$tableName, $columnName] = explode('.', $key, 2);
			$tableRows[$tableName] = ['TABLE_NAME' => $tableName, 'TABLE_COLLATION' => 'utf8mb4_general_ci'];
			$columnRows[] = [
				'TABLE_NAME' => $tableName,
				'COLUMN_NAME' => $columnName,
				'IS_NULLABLE' => $column->nullable ? 'YES' : 'NO',
				'DATA_TYPE' => $column->dataType,
				'COLUMN_TYPE' => $column->columnType,
				'CHARACTER_MAXIMUM_LENGTH' => $column->characterMaximumLength,
				'NUMERIC_PRECISION' => $column->numericPrecision,
				'NUMERIC_SCALE' => $column->numericScale,
				'COLLATION_NAME' => $column->collation,
			];
		}

		$uniqueRows = [];
		foreach ($uniqueColumns as $key) {
			[$tableName, $columnName] = explode('.', $key, 2);
			$uniqueRows[] = ['TABLE_NAME' => $tableName, 'COLUMN_NAME' => $columnName];
		}

		$connection = self::createStub(Connection::class);
		$connection->method('getDatabase')
			->willReturn('attribute_check');
		$connection->method('fetchAllAssociative')
			->willReturnCallback(static function (string $query) use ($tableRows, $columnRows, $uniqueRows): array {
				if (str_contains($query, 'information_schema.TABLES')) {
					return array_values($tableRows);
				}

				if (str_contains($query, 'information_schema.COLUMNS')) {
					return $columnRows;
				}

				if (str_contains($query, 'information_schema.STATISTICS') && str_contains($query, 'INDEX_NAME <>')) {
					return $uniqueRows;
				}

				return [];
			});

		return DatabaseSchemaSnapshot::load($connection);
	}

	protected function createConnection(): Connection
	{
		$connection = self::createStub(Connection::class);
		$connection->method('getDatabasePlatform')
			->willReturn(new MySQLPlatform());
		$dbalConfiguration = new DbalConfiguration();
		$dbalConfiguration->setTypeProvider(new TypeRegistry());
		$connection->method('getConfiguration')
			->willReturn($dbalConfiguration);

		return $connection;
	}

}
