<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Checker;

use PHPUnit\Framework\Attributes\DataProvider;
use Slevomat\DoctrineAttributeChecker\Checker\Data\Narrower\NarrowerEntity;
use Slevomat\DoctrineAttributeChecker\Database\DatabaseColumn;
use function sprintf;

final class DatabaseColumnNarrowerTest extends FactoryMetadataTestCase
{

	private const string FILE = __DIR__ . '/data/Narrower/NarrowerEntities.php';

	public function testMatchingColumnsAreAccepted(): void
	{
		self::assertSame([], $this->inspectRule([]));
	}

	/**
	 * @return iterable<string, array{array<string, DatabaseColumn>}>
	 */
	public static function provideWiderColumns(): iterable
	{
		yield 'smaller integer column' => [[
			'narrower.position' => self::integerColumn('tinyint', 'tinyint unsigned'),
		]];

		yield 'longer string column' => [[
			'narrower.title' => self::varcharColumn(50, false),
		]];

		yield 'wider decimal column' => [[
			'narrower.price' => self::decimalColumn(12, 3),
		]];
	}

	/**
	 * @param array<string, DatabaseColumn> $overrides
	 */
	#[DataProvider('provideWiderColumns')]
	public function testWiderColumnsAreAccepted(array $overrides): void
	{
		self::assertSame([], $this->inspectRule($overrides));
	}

	/**
	 * @return iterable<string, array{array<string, DatabaseColumn>, string}>
	 */
	public static function provideNarrowerColumns(): iterable
	{
		yield 'shorter string column' => [
			['narrower.title' => self::varcharColumn(10, false)],
			'Database column narrower.title length 10 is shorter than Column.length 20.',
		];

		yield 'smaller decimal scale' => [
			['narrower.price' => self::decimalColumn(10, 1)],
			'Database column narrower.price decimal(10,1) cannot hold all values of Column.precision 10 and scale 2.',
		];

		yield 'fewer decimal integer digits' => [
			['narrower.price' => self::decimalColumn(9, 2)],
			'Database column narrower.price decimal(9,2) cannot hold all values of Column.precision 10 and scale 2.',
		];
	}

	/**
	 * @param array<string, DatabaseColumn> $overrides
	 */
	#[DataProvider('provideNarrowerColumns')]
	public function testNarrowerColumnsAreReported(array $overrides, string $message): void
	{
		self::assertSame([$message], $this->inspectRule($overrides));
	}

	/**
	 * @param array<string, DatabaseColumn> $overrides
	 * @return list<string>
	 */
	private function inspectRule(array $overrides): array
	{
		$columns = [
			'narrower.id' => self::charColumn(36, false),
			'narrower.position' => self::integerColumn('int', 'int unsigned'),
			'narrower.title' => self::varcharColumn(20, false),
			'narrower.price' => self::decimalColumn(10, 2),
		];
		$result = $this->inspectFixture(
			__DIR__ . '/data/Narrower',
			self::FILE,
			NarrowerEntity::class,
			$this->createSchema($overrides + $columns),
			false,
		);

		$messages = [];
		foreach ($result['violations'] as $violation) {
			if ($violation->rule !== 'database.column-narrower') {
				continue;
			}

			$messages[] = $violation->message;
		}

		return $messages;
	}

	private static function integerColumn(string $dataType, string $columnType): DatabaseColumn
	{
		return new DatabaseColumn($dataType, $columnType, null, null, 0, false, null);
	}

	private static function decimalColumn(int $precision, int $scale): DatabaseColumn
	{
		return new DatabaseColumn('decimal', sprintf('decimal(%d,%d)', $precision, $scale), null, $precision, $scale, false, null);
	}

}
