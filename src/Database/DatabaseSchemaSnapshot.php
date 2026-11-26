<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Database;

use Doctrine\DBAL\Connection;
use Webmozart\Assert\Assert;
use function array_diff_key;
use function array_intersect_key;
use function array_key_exists;
use function count;
use function is_numeric;
use function preg_match;
use function str_contains;
use function str_replace;
use function strtolower;
use function strtoupper;
use function trim;

final class DatabaseSchemaSnapshot
{

	/**
	 * @param array<string, ?string> $tableCollations
	 * @param array<string, array<string, DatabaseColumn>> $columns
	 * @param array<string, array<string, true>> $uniqueColumns
	 * @param array<string, array<string, true>> $primaryKeyColumns
	 * @param array<string, array<string, true>> $singleColumnPrimaryKeyColumns
	 * @param array<string, array<string, list<DatabaseForeignKey>>> $foreignKeys
	 * @param array<string, array<string, list<array{table: string, column: string}>>> $primaryKeyForeignKeySources
	 */
	private function __construct(
		private string $schema,
		private array $tableCollations,
		private array $columns,
		private array $uniqueColumns,
		private array $primaryKeyColumns,
		private array $singleColumnPrimaryKeyColumns,
		private array $foreignKeys,
		private array $primaryKeyForeignKeySources,
	)
	{
	}

	public static function load(Connection $connection, string $queryPrefix = ''): self
	{
		$schema = $connection->getDatabase();
		Assert::stringNotEmpty($schema);

		$tableCollations = [];
		foreach ($connection->fetchAllAssociative(
			$queryPrefix . 'SELECT TABLE_NAME, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?',
			[$schema],
		) as $row) {
			Assert::string($row['TABLE_NAME'] ?? null);
			$tableName = self::normalizeIdentifier($row['TABLE_NAME']);
			$collation = $row['TABLE_COLLATION'] ?? null;
			Assert::nullOrString($collation);
			$tableCollations[$tableName] = $collation;
		}

		$columns = [];
		foreach ($connection->fetchAllAssociative(
			$queryPrefix . 'SELECT TABLE_NAME, COLUMN_NAME, IS_NULLABLE, DATA_TYPE, COLUMN_TYPE, CHARACTER_MAXIMUM_LENGTH, '
			. 'NUMERIC_PRECISION, NUMERIC_SCALE, COLLATION_NAME, COLUMN_DEFAULT, EXTRA '
			. 'FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ?',
			[$schema],
		) as $row) {
			Assert::string($row['TABLE_NAME'] ?? null);
			Assert::string($row['COLUMN_NAME'] ?? null);
			Assert::string($row['DATA_TYPE'] ?? null);
			Assert::string($row['COLUMN_TYPE'] ?? null);
			Assert::string($row['IS_NULLABLE'] ?? null);

			$tableName = self::normalizeIdentifier($row['TABLE_NAME']);
			$columnName = self::normalizeIdentifier($row['COLUMN_NAME']);
			$length = $row['CHARACTER_MAXIMUM_LENGTH'] ?? null;
			$precision = $row['NUMERIC_PRECISION'] ?? null;
			$scale = $row['NUMERIC_SCALE'] ?? null;
			$collation = $row['COLLATION_NAME'] ?? null;
			Assert::nullOrString($collation);
			$extra = $row['EXTRA'] ?? null;
			Assert::nullOrString($extra);
			$extra = strtolower($extra ?? '');

			$columns[$tableName][$columnName] = new DatabaseColumn(
				strtolower($row['DATA_TYPE']),
				strtolower($row['COLUMN_TYPE']),
				is_numeric($length) ? (int) $length : null,
				is_numeric($precision) ? (int) $precision : null,
				is_numeric($scale) ? (int) $scale : null,
				$row['IS_NULLABLE'] === 'YES',
				$collation,
				($row['COLUMN_DEFAULT'] ?? null) !== null,
				str_contains($extra, 'auto_increment'),
				preg_match('~\b(virtual|stored) generated\b|\bpersistent\b~', $extra) === 1,
			);
		}

		$uniqueColumns = [];
		foreach ($connection->fetchAllAssociative(
			$queryPrefix . 'SELECT TABLE_NAME, MIN(COLUMN_NAME) AS COLUMN_NAME FROM information_schema.STATISTICS '
			. 'WHERE TABLE_SCHEMA = ? AND NON_UNIQUE = 0 AND INDEX_NAME <> \'PRIMARY\' '
			. 'GROUP BY TABLE_NAME, INDEX_NAME HAVING COUNT(*) = 1',
			[$schema],
		) as $row) {
			Assert::string($row['TABLE_NAME'] ?? null);
			Assert::string($row['COLUMN_NAME'] ?? null);
			$uniqueColumns[self::normalizeIdentifier($row['TABLE_NAME'])][self::normalizeIdentifier($row['COLUMN_NAME'])] = true;
		}

		$primaryKeyColumns = [];
		/** @var array<string, list<string>> $primaryKeyColumnsByTable */
		$primaryKeyColumnsByTable = [];
		foreach ($connection->fetchAllAssociative(
			$queryPrefix . 'SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.STATISTICS '
			. 'WHERE TABLE_SCHEMA = ? AND INDEX_NAME = \'PRIMARY\'',
			[$schema],
		) as $row) {
			Assert::string($row['TABLE_NAME'] ?? null);
			Assert::string($row['COLUMN_NAME'] ?? null);

			$tableName = self::normalizeIdentifier($row['TABLE_NAME']);
			$columnName = self::normalizeIdentifier($row['COLUMN_NAME']);
			$primaryKeyColumns[$tableName][$columnName] = true;
			$primaryKeyColumnsByTable[$tableName][] = $columnName;
		}

		$singleColumnPrimaryKeyColumns = [];
		foreach ($primaryKeyColumnsByTable as $tableName => $columnNames) {
			if (count($columnNames) !== 1) {
				continue;
			}

			$singleColumnPrimaryKeyColumns[$tableName][$columnNames[0]] = true;
		}

		$foreignKeys = [];
		foreach ($connection->fetchAllAssociative(
			$queryPrefix . 'SELECT k.TABLE_NAME, k.COLUMN_NAME, k.CONSTRAINT_NAME, k.REFERENCED_TABLE_NAME, '
			. 'k.REFERENCED_COLUMN_NAME, r.DELETE_RULE '
			. 'FROM information_schema.KEY_COLUMN_USAGE k '
			. 'JOIN information_schema.REFERENTIAL_CONSTRAINTS r '
			. 'ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA '
			. 'AND r.TABLE_NAME = k.TABLE_NAME AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME '
			. 'WHERE k.CONSTRAINT_SCHEMA = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL',
			[$schema],
		) as $row) {
			Assert::string($row['TABLE_NAME'] ?? null);
			Assert::string($row['COLUMN_NAME'] ?? null);
			Assert::string($row['CONSTRAINT_NAME'] ?? null);
			Assert::string($row['REFERENCED_TABLE_NAME'] ?? null);
			Assert::string($row['REFERENCED_COLUMN_NAME'] ?? null);
			Assert::string($row['DELETE_RULE'] ?? null);

			$tableName = self::normalizeIdentifier($row['TABLE_NAME']);
			$columnName = self::normalizeIdentifier($row['COLUMN_NAME']);
			$foreignKeys[$tableName][$columnName][] = new DatabaseForeignKey(
				$row['CONSTRAINT_NAME'],
				self::normalizeIdentifier($row['REFERENCED_TABLE_NAME']),
				self::normalizeIdentifier($row['REFERENCED_COLUMN_NAME']),
				strtoupper($row['DELETE_RULE']),
			);
		}

		$primaryKeyForeignKeySources = self::buildPrimaryKeyForeignKeySources($foreignKeys, $primaryKeyColumns);

		return new self(
			$schema,
			$tableCollations,
			$columns,
			$uniqueColumns,
			$primaryKeyColumns,
			$singleColumnPrimaryKeyColumns,
			$foreignKeys,
			$primaryKeyForeignKeySources,
		);
	}

	public function withSecondary(self $secondary): self
	{
		$secondaryOnlyTables = array_diff_key($secondary->tableCollations, $this->tableCollations);
		$columns = $this->columns + array_intersect_key($secondary->columns, $secondaryOnlyTables);
		$uniqueColumns = $this->uniqueColumns + array_intersect_key($secondary->uniqueColumns, $secondaryOnlyTables);
		$primaryKeyColumns = $this->primaryKeyColumns
			+ array_intersect_key($secondary->primaryKeyColumns, $secondaryOnlyTables);
		$singleColumnPrimaryKeyColumns = $this->singleColumnPrimaryKeyColumns
			+ array_intersect_key($secondary->singleColumnPrimaryKeyColumns, $secondaryOnlyTables);
		$foreignKeys = $this->foreignKeys + array_intersect_key($secondary->foreignKeys, $secondaryOnlyTables);

		return new self(
			$this->schema,
			$this->tableCollations + $secondary->tableCollations,
			$columns,
			$uniqueColumns,
			$primaryKeyColumns,
			$singleColumnPrimaryKeyColumns,
			$foreignKeys,
			self::buildPrimaryKeyForeignKeySources($foreignKeys, $primaryKeyColumns),
		);
	}

	public function getSchema(): string
	{
		return $this->schema;
	}

	public function hasTable(string $table): bool
	{
		return array_key_exists(self::normalizeIdentifier($table), $this->tableCollations);
	}

	public function getColumn(string $table, string $column): ?DatabaseColumn
	{
		return $this->columns[self::normalizeIdentifier($table)][self::normalizeIdentifier($column)] ?? null;
	}

	/**
	 * @return array<string, DatabaseColumn>
	 */
	public function getColumns(string $table): array
	{
		return $this->columns[self::normalizeIdentifier($table)] ?? [];
	}

	public function isSingleColumnUnique(string $table, string $column): bool
	{
		return isset($this->uniqueColumns[self::normalizeIdentifier($table)][self::normalizeIdentifier($column)]);
	}

	public function isSingleColumnPrimaryKey(string $table, string $column): bool
	{
		return isset(
			$this->singleColumnPrimaryKeyColumns[self::normalizeIdentifier($table)][self::normalizeIdentifier($column)],
		);
	}

	public function isPrimaryKeyColumn(string $table, string $column): bool
	{
		return isset($this->primaryKeyColumns[self::normalizeIdentifier($table)][self::normalizeIdentifier($column)]);
	}

	public function getTableCollation(string $table): ?string
	{
		return $this->tableCollations[self::normalizeIdentifier($table)] ?? null;
	}

	/**
	 * @return list<DatabaseForeignKey>
	 */
	public function getForeignKeys(string $table, string $column): array
	{
		return $this->foreignKeys[self::normalizeIdentifier($table)][self::normalizeIdentifier($column)] ?? [];
	}

	public function areTargetsEquivalentViaPrimaryKeyForeignKeys(
		string $firstTable,
		string $firstColumn,
		string $secondTable,
		string $secondColumn,
	): bool
	{
		$firstTable = self::normalizeIdentifier($firstTable);
		$firstColumn = self::normalizeIdentifier($firstColumn);
		$secondTable = self::normalizeIdentifier($secondTable);
		$secondColumn = self::normalizeIdentifier($secondColumn);

		/** @var list<array{table: string, column: string}> $queue */
		$queue = [['table' => $firstTable, 'column' => $firstColumn]];
		/** @var array<string, array<string, true>> $visited */
		$visited = [$firstTable => [$firstColumn => true]];
		for ($queueIndex = 0; isset($queue[$queueIndex]); $queueIndex++) {
			$current = $queue[$queueIndex];
			if ($current['table'] === $secondTable && $current['column'] === $secondColumn) {
				return true;
			}

			if ($this->isPrimaryKeyColumn($current['table'], $current['column'])) {
				foreach ($this->getForeignKeys($current['table'], $current['column']) as $foreignKey) {
					$tableName = $foreignKey->referencedTable;
					$columnName = $foreignKey->referencedColumn;
					if (isset($visited[$tableName][$columnName])) {
						continue;
					}

					$visited[$tableName][$columnName] = true;
					$queue[] = ['table' => $tableName, 'column' => $columnName];
				}
			}

			foreach ($this->primaryKeyForeignKeySources[$current['table']][$current['column']] ?? [] as $source) {
				$tableName = $source['table'];
				$columnName = $source['column'];
				if (isset($visited[$tableName][$columnName])) {
					continue;
				}

				$visited[$tableName][$columnName] = true;
				$queue[] = ['table' => $tableName, 'column' => $columnName];
			}
		}

		return false;
	}

	/**
	 * @param array<string, array<string, list<DatabaseForeignKey>>> $foreignKeys
	 * @param array<string, array<string, true>> $primaryKeyColumns
	 * @return array<string, array<string, list<array{table: string, column: string}>>>
	 */
	private static function buildPrimaryKeyForeignKeySources(array $foreignKeys, array $primaryKeyColumns): array
	{
		/** @var array<string, array<string, list<array{table: string, column: string}>>> $sources */
		$sources = [];
		foreach ($foreignKeys as $tableName => $foreignKeysByColumn) {
			foreach ($foreignKeysByColumn as $columnName => $columnForeignKeys) {
				if (!isset($primaryKeyColumns[$tableName][$columnName])) {
					continue;
				}

				foreach ($columnForeignKeys as $foreignKey) {
					$sources[$foreignKey->referencedTable][$foreignKey->referencedColumn][] = [
						'table' => $tableName,
						'column' => $columnName,
					];
				}
			}
		}

		return $sources;
	}

	private static function normalizeIdentifier(string $identifier): string
	{
		return trim(str_replace('``', '`', $identifier), '`');
	}

}
