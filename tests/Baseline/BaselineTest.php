<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Baseline;

use Nette\Neon\Neon;
use Nette\Utils\FileSystem;
use Override;
use PHPUnit\Framework\TestCase;
use Slevomat\DoctrineAttributeChecker\Violation;
use Webmozart\Assert\Assert;
use function sprintf;
use function sys_get_temp_dir;
use function uniqid;

class BaselineTest extends TestCase
{

	private string $rootDirectory;

	#[Override]
	protected function setUp(): void
	{
		$this->rootDirectory = sprintf('%s/%s', sys_get_temp_dir(), uniqid('doctrine-attribute-baseline-', true));
		FileSystem::createDir($this->rootDirectory);
	}

	#[Override]
	protected function tearDown(): void
	{
		FileSystem::delete($this->rootDirectory);
	}

	public function testFiltersOnlyExactMatchesAndReportsUnusedEntries(): void
	{
		$baseline = new Baseline($this->rootDirectory, [
			[
				'rule' => 'database.column-nullability',
				'target' => 'App\\Entity::$name',
				'path' => 'app/Entity.php',
				'message' => 'PHP/Doctrine nullability is not nullable but the database column is nullable.',
			],
			[
				'rule' => 'database.join-column-nullability',
				'target' => 'App\\Entity::$related',
				'path' => 'app/Entity.php',
				'message' => 'Doctrine join-column nullability is nullable but the database column is not nullable.',
			],
		]);
		$matchingViolation = $this->createViolation(
			'app/Entity.php',
			'database.column-nullability',
			'PHP/Doctrine nullability is not nullable but the database column is nullable.',
		);
		$changedMessageViolation = $this->createViolation(
			'app/Entity.php',
			'database.column-nullability',
			'PHP/Doctrine nullability is nullable but the database column is not nullable.',
		);
		$changedRuleViolation = $this->createViolation(
			'app/Entity.php',
			'database.join-column-nullability',
			'PHP/Doctrine nullability is not nullable but the database column is nullable.',
		);
		$changedPathViolation = $this->createViolation(
			'app/OtherEntity.php',
			'database.column-nullability',
			'PHP/Doctrine nullability is not nullable but the database column is nullable.',
		);

		self::assertSame([$changedMessageViolation, $changedRuleViolation, $changedPathViolation], $baseline->filter([
			$matchingViolation,
			$changedMessageViolation,
			$changedRuleViolation,
			$changedPathViolation,
		]));
		self::assertSame([
			[
				'rule' => 'database.join-column-nullability',
				'target' => 'App\\Entity::$related',
				'path' => 'app/Entity.php',
				'message' => 'Doctrine join-column nullability is nullable but the database column is not nullable.',
			],
		], $baseline->getUnmatchedEntries());
	}

	public function testEmptyBaselineHasNoEntries(): void
	{
		$baseline = new Baseline($this->rootDirectory, []);

		self::assertSame([], $baseline->filter([]));
		self::assertSame([], $baseline->getUnmatchedEntries());
	}

	public function testWrittenBaselineMatchesAllViolations(): void
	{
		$violations = [
			$this->createViolation('app/B.php', 'identifier.quoting', 'Identifier order must be escaped with backticks.'),
			$this->createViolation('app/A.php', 'database.column-nullability', 'Nullability: \'yes\' # not a comment.'),
			$this->createViolation('app/A.php', 'database.column-nullability', 'Nullability: \'yes\' # not a comment.'),
		];
		$baselineFile = sprintf('%s/build/baseline.neon', $this->rootDirectory);

		$entryCount = (new BaselineWriter())->write($baselineFile, $this->rootDirectory, $violations);

		self::assertSame(2, $entryCount);
		$data = Neon::decodeFile($baselineFile);
		Assert::isArray($data);
		Assert::isList($data['ignoreErrors']);
		self::assertSame(
			[
				[
					'rule' => 'database.column-nullability',
					'target' => 'App\\Entity::$name',
					'path' => 'app/A.php',
					'message' => 'Nullability: \'yes\' # not a comment.',
				],
				[
					'rule' => 'identifier.quoting',
					'target' => 'App\\Entity::$name',
					'path' => 'app/B.php',
					'message' => 'Identifier order must be escaped with backticks.',
				],
			],
			$data['ignoreErrors'],
		);

		$baseline = new Baseline($this->rootDirectory, $data['ignoreErrors']);
		self::assertSame([], $baseline->filter($violations));
		self::assertSame([], $baseline->getUnmatchedEntries());
	}

	public function testEmptyBaselineIsWritten(): void
	{
		$baselineFile = sprintf('%s/baseline.neon', $this->rootDirectory);

		self::assertSame(0, (new BaselineWriter())->write($baselineFile, $this->rootDirectory, []));
		self::assertSame("ignoreErrors: []\n", FileSystem::read($baselineFile));
	}

	private function createViolation(string $path, string $rule, string $message): Violation
	{
		return new Violation(
			sprintf('%s/%s', $this->rootDirectory, $path),
			10,
			'App\\Entity::$name',
			$rule,
			$message,
			false,
		);
	}

}
