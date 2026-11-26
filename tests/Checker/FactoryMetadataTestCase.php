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
use Nette\Utils\FileSystem;
use PHPUnit\Framework\TestCase;
use Slevomat\DoctrineAttributeChecker\Configuration\Configuration as CheckerConfiguration;
use Slevomat\DoctrineAttributeChecker\Configuration\ConfigurationLoader;
use Slevomat\DoctrineAttributeChecker\Database\DatabaseColumn;
use Slevomat\DoctrineAttributeChecker\Database\DatabaseSchemaSnapshot;
use Slevomat\DoctrineAttributeChecker\Source\AttributeSourceEditor;
use Slevomat\DoctrineAttributeChecker\Violation;
use function array_values;
use function explode;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function sys_get_temp_dir;
use function uniqid;
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
			foreach ($metadata->embeddedClasses as $embeddedClass) {
				$metadataByClassName[$embeddedClass->class] = $entityManager->getClassMetadata($embeddedClass->class);
			}
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
				'COLUMN_DEFAULT' => $column->hasDefault ? '' : null,
				'EXTRA' => ($column->autoIncrement ? 'auto_increment' : '') . ($column->generated ? 'STORED GENERATED' : ''),
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

	/**
	 * @param class-string $className
	 * @return array{contents: string, changed: bool, violations: list<Violation>}
	 */
	protected function inspectFixture(
		string $directory,
		string $file,
		string $className,
		DatabaseSchemaSnapshot $schema,
		bool $fix,
		?string $configuration = null,
	): array
	{
		$entityManager = $this->createEntityManager($directory);
		$checker = new DoctrineAttributeChecker(
			$entityManager,
			$this->loadConfiguration($configuration),
			new AttributeSourceEditor(),
			$this->loadAll($entityManager),
			[$className => true],
			$schema,
		);

		return $checker->inspect(FileSystem::read($file), $file, $fix);
	}

	private function loadConfiguration(?string $configuration): CheckerConfiguration
	{
		if ($configuration === null) {
			return (new ConfigurationLoader())->load(ConfigurationLoader::getDefaultsFile());
		}

		$file = sprintf('%s/%s.neon', sys_get_temp_dir(), uniqid('doctrine-attribute-checker-', true));
		FileSystem::write($file, $configuration);
		try {
			return (new ConfigurationLoader())->load($file);
		} finally {
			FileSystem::delete($file);
		}
	}

	protected static function charColumn(int $length, bool $nullable): DatabaseColumn
	{
		return new DatabaseColumn('char', sprintf('char(%d)', $length), $length, null, null, $nullable, 'utf8mb4_general_ci');
	}

	protected static function varcharColumn(int $length, bool $nullable): DatabaseColumn
	{
		return new DatabaseColumn(
			'varchar',
			sprintf('varchar(%d)', $length),
			$length,
			null,
			null,
			$nullable,
			'utf8mb4_general_ci',
		);
	}

	/**
	 * @param list<Violation> $violations
	 * @return list<string>
	 */
	protected function formatViolations(array $violations, ?string $rulePrefix = null): array
	{
		$result = [];
		foreach ($violations as $violation) {
			if ($rulePrefix !== null && !str_starts_with($violation->rule, $rulePrefix)) {
				continue;
			}

			$result[] = sprintf('%s: %s', $violation->rule, $violation->message);
		}

		return $result;
	}

}
