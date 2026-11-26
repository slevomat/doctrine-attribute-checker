<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Checker;

use Slevomat\DoctrineAttributeChecker\Checker\Data\Insert\InsertArticleEntity;
use Slevomat\DoctrineAttributeChecker\Checker\Data\Insert\InsertCommentEntity;
use Slevomat\DoctrineAttributeChecker\Database\DatabaseColumn;
use function sprintf;

final class DatabaseJoinColumnNullabilityTest extends FactoryMetadataTestCase
{

	private const string FILE = __DIR__ . '/data/Insert/InsertEntities.php';

	public function testMatchingNullabilityIsAccepted(): void
	{
		self::assertSame([], $this->inspectNullability(InsertArticleEntity::class, true));
		self::assertSame([], $this->inspectNullability(InsertCommentEntity::class, false));
	}

	public function testNotNullableColumnOfNullableJoinColumnIsReported(): void
	{
		self::assertSame(
			['database.join-column-rejects-null: Doctrine join-column nullability is nullable but the database column is not nullable.'],
			$this->inspectNullability(InsertArticleEntity::class, false),
		);
	}

	public function testNullableColumnOfNotNullableJoinColumnIsReported(): void
	{
		self::assertSame(
			['database.join-column-allows-null: Doctrine join-column nullability is not nullable but the database column is nullable.'],
			$this->inspectNullability(InsertCommentEntity::class, true),
		);
	}

	/**
	 * @param class-string $className
	 * @return list<string>
	 */
	private function inspectNullability(string $className, bool $nullable): array
	{
		$schema = $this->createSchema([
			'insert_article.id' => new DatabaseColumn('int', 'int', null, 10, 0, false, null, autoIncrement: true),
			'insert_article.title' => self::varcharColumn(20, false),
			'insert_article.main_tag_id' => self::charColumn(36, $nullable),
			'insert_tag.id' => self::charColumn(36, false),
			'insert_comment.id' => self::charColumn(36, false),
			'insert_comment.article_id' => new DatabaseColumn('int', 'int', null, 10, 0, $nullable, null),
		]);
		$result = $this->inspectFixture(__DIR__ . '/data/Insert', self::FILE, $className, $schema, false);

		$messages = [];
		foreach ($result['violations'] as $violation) {
			if ($violation->rule !== 'database.join-column-rejects-null' && $violation->rule !== 'database.join-column-allows-null') {
				continue;
			}

			$messages[] = sprintf('%s: %s', $violation->rule, $violation->message);
		}

		return $messages;
	}

}
