<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Checker;

use Slevomat\DoctrineAttributeChecker\Checker\Data\Insert\InsertArticleEntity;
use Slevomat\DoctrineAttributeChecker\Checker\Data\Insert\InsertBikeEntity;
use Slevomat\DoctrineAttributeChecker\Checker\Data\Insert\InsertCarEntity;
use Slevomat\DoctrineAttributeChecker\Database\DatabaseColumn;
use Slevomat\DoctrineAttributeChecker\Database\DatabaseSchemaSnapshot;

final class DatabaseColumnNotInsertedTest extends FactoryMetadataTestCase
{

	private const string FILE = __DIR__ . '/data/Insert/InsertEntities.php';

	public function testMappedColumnsAreInserted(): void
	{
		self::assertSame([], $this->inspectRule(InsertArticleEntity::class, $this->createInsertSchema()));
	}

	public function testUnmappedNotNullColumnWithoutDefaultIsReported(): void
	{
		$schema = $this->createInsertSchema([
			'insert_article.slug' => self::varcharColumn(20, false),
		]);

		self::assertSame(
			[
				'Column insert_article.slug is NOT NULL without a default value, but '
				. 'Slevomat\DoctrineAttributeChecker\Checker\Data\Insert\InsertArticleEntity does not insert it.',
			],
			$this->inspectRule(InsertArticleEntity::class, $schema),
		);
	}

	public function testUnmappedColumnsFilledByDatabaseAreAllowed(): void
	{
		$schema = $this->createInsertSchema([
			'insert_article.nullable_note' => self::varcharColumn(20, true),
			'insert_article.created' => new DatabaseColumn('datetime', 'datetime', null, null, null, false, null, hasDefault: true),
			'insert_article.sequence' => new DatabaseColumn('int', 'int', null, 10, 0, false, null, autoIncrement: true),
			'insert_article.title_length' => new DatabaseColumn('int', 'int', null, 10, 0, false, null, generated: true),
		]);

		self::assertSame([], $this->inspectRule(InsertArticleEntity::class, $schema));
	}

	public function testUnmappedNotNullColumnOfJoinTableIsReported(): void
	{
		$schema = $this->createInsertSchema([
			'insert_article_tag.position' => new DatabaseColumn('int', 'int', null, 10, 0, false, null),
		]);

		self::assertSame(
			[
				'Column insert_article_tag.position is NOT NULL without a default value, but '
				. 'Slevomat\DoctrineAttributeChecker\Checker\Data\Insert\InsertArticleEntity::$tags does not insert it.',
			],
			$this->inspectRule(InsertArticleEntity::class, $schema),
		);
	}

	public function testNotNullColumnOfOtherSingleTableSubclassIsReported(): void
	{
		$schema = $this->createInsertSchema([
			'insert_vehicle.plate' => self::varcharColumn(10, false),
		]);

		self::assertSame([], $this->inspectRule(InsertCarEntity::class, $schema));
		self::assertSame(
			[
				'Column insert_vehicle.plate is NOT NULL without a default value, but '
				. 'Slevomat\DoctrineAttributeChecker\Checker\Data\Insert\InsertBikeEntity does not insert it.',
			],
			$this->inspectRule(InsertBikeEntity::class, $schema),
		);
		self::assertSame([], $this->inspectRule(InsertBikeEntity::class, $this->createInsertSchema()));
	}

	/**
	 * @param class-string $className
	 * @return list<string>
	 */
	private function inspectRule(string $className, DatabaseSchemaSnapshot $schema): array
	{
		$result = $this->inspectFixture(__DIR__ . '/data/Insert', self::FILE, $className, $schema, false);

		$messages = [];
		foreach ($result['violations'] as $violation) {
			if ($violation->rule !== 'database.column-not-inserted') {
				continue;
			}

			$messages[] = $violation->message;
		}

		return $messages;
	}

	/**
	 * @param array<string, DatabaseColumn> $overrides
	 */
	private function createInsertSchema(array $overrides = []): DatabaseSchemaSnapshot
	{
		$columns = [
			'insert_article.id' => new DatabaseColumn('int', 'int', null, 10, 0, false, null, autoIncrement: true),
			'insert_article.title' => self::varcharColumn(20, false),
			'insert_article.main_tag_id' => self::charColumn(36, true),
			'insert_article_tag.insert_article_entity_id' => new DatabaseColumn('int', 'int', null, 10, 0, false, null),
			'insert_article_tag.insert_tag_entity_id' => self::charColumn(36, false),
			'insert_tag.id' => self::charColumn(36, false),
			'insert_vehicle.id' => self::charColumn(36, false),
			'insert_vehicle.type' => self::varcharColumn(20, false),
			'insert_vehicle.plate' => self::varcharColumn(10, true),
		];

		return $this->createSchema($overrides + $columns);
	}

}
