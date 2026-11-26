<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Checker;

use Slevomat\DoctrineAttributeChecker\Checker\Data\Insert\InsertArticleEntity;
use Slevomat\DoctrineAttributeChecker\Checker\Data\Insert\InsertCommentEntity;
use Slevomat\DoctrineAttributeChecker\Database\DatabaseColumn;
use Slevomat\DoctrineAttributeChecker\Database\DatabaseSchemaSnapshot;

final class DatabaseCompatibilityModeTest extends FactoryMetadataTestCase
{

	private const string FILE = __DIR__ . '/data/Insert/InsertEntities.php';

	public function testIntermediateStatesOfBackwardCompatibleChangesAreAccepted(): void
	{
		$schema = $this->createCompatibilitySchema([
			'insert_article.title' => self::varcharColumn(50, true),
			'insert_comment.article_id' => new DatabaseColumn('int', 'int', null, 10, 0, true, null),
			'insert_article.note' => self::varcharColumn(20, true),
		]);

		self::assertSame([], $this->inspectDatabase(InsertArticleEntity::class, $schema, false));
		self::assertSame([], $this->inspectDatabase(InsertCommentEntity::class, $schema, false));
		self::assertSame(
			[
				'database.column-allows-null: PHP/Doctrine nullability is not nullable but the database column is nullable.',
				'database.column-length: Column.length must match the database column length.',
			],
			$this->inspectDatabase(InsertArticleEntity::class, $schema, true),
		);
		self::assertSame(
			['database.join-column-allows-null: Doctrine join-column nullability is not nullable but the database column is nullable.'],
			$this->inspectDatabase(InsertCommentEntity::class, $schema, true),
		);
	}

	public function testDifferencesBreakingTheApplicationAreReported(): void
	{
		$schema = $this->createCompatibilitySchema([
			'insert_article.title' => self::varcharColumn(10, false),
			'insert_article.main_tag_id' => self::charColumn(36, false),
			'insert_article.slug' => self::varcharColumn(20, false),
		]);

		self::assertSame(
			[
				'database.column-not-inserted: Column insert_article.slug is NOT NULL without a default value, but '
				. 'Slevomat\DoctrineAttributeChecker\Checker\Data\Insert\InsertArticleEntity does not insert it.',
				'database.column-narrower: Database column insert_article.title length 10 is shorter than Column.length 20.',
				'database.join-column-rejects-null: Doctrine join-column nullability is nullable but the database column is not nullable.',
			],
			$this->inspectDatabase(InsertArticleEntity::class, $schema, false),
		);
	}

	/**
	 * @param class-string $className
	 * @return list<string>
	 */
	private function inspectDatabase(string $className, DatabaseSchemaSnapshot $schema, bool $strict): array
	{
		$result = $this->inspectFixture(__DIR__ . '/data/Insert', self::FILE, $className, $schema, false, strict: $strict);

		return $this->formatViolations($result['violations'], 'database.');
	}

	/**
	 * @param array<string, DatabaseColumn> $overrides
	 */
	private function createCompatibilitySchema(array $overrides): DatabaseSchemaSnapshot
	{
		return $this->createSchema($overrides + [
			'insert_article.id' => new DatabaseColumn('int', 'int', null, 10, 0, false, null, autoIncrement: true),
			'insert_article.title' => self::varcharColumn(20, false),
			'insert_article.main_tag_id' => self::charColumn(36, true),
			'insert_article_tag.insert_article_entity_id' => new DatabaseColumn('int', 'int', null, 10, 0, false, null),
			'insert_article_tag.insert_tag_entity_id' => self::charColumn(36, false),
			'insert_tag.id' => self::charColumn(36, false),
			'insert_comment.id' => self::charColumn(36, false),
			'insert_comment.article_id' => new DatabaseColumn('int', 'int', null, 10, 0, false, null),
		]);
	}

}
