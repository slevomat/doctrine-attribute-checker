<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Console;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Nette\Utils\FileSystem;
use Override;
use PHPUnit\Framework\TestCase;
use Slevomat\DoctrineAttributeChecker\CheckerException;
use Slevomat\DoctrineAttributeChecker\Database\SchemaSource;
use function sprintf;
use function sys_get_temp_dir;
use function uniqid;

class EntityManagerLoaderTest extends TestCase
{

	public static ?CheckerEnvironment $environment = null;

	public static ?EntityManagerInterface $entityManager = null;

	private string $directory;

	#[Override]
	protected function setUp(): void
	{
		$this->directory = sprintf('%s/%s', sys_get_temp_dir(), uniqid('doctrine-attribute-loader-', true));
		FileSystem::createDir($this->directory);
	}

	#[Override]
	protected function tearDown(): void
	{
		FileSystem::delete($this->directory);
		self::$environment = null;
		self::$entityManager = null;
	}

	public function testEntityManagerIsWrappedInEnvironment(): void
	{
		self::$entityManager = self::createStub(EntityManagerInterface::class);

		$environment = (new EntityManagerLoader())->load($this->writeLoader('entityManager'));

		self::assertSame(self::$entityManager, $environment->entityManager);
		self::assertSame([], $environment->additionalSchemaSources);
	}

	public function testEnvironmentIsReturnedAsIs(): void
	{
		self::$environment = new CheckerEnvironment(
			self::createStub(EntityManagerInterface::class),
			[new SchemaSource(self::createStub(Connection::class), '/* port 3307 */ ')],
		);

		self::assertSame(self::$environment, (new EntityManagerLoader())->load($this->writeLoader('environment')));
	}

	public function testInvalidReturnValue(): void
	{
		$this->expectException(CheckerException::class);
		$this->expectExceptionMessage('null returned');

		(new EntityManagerLoader())->load($this->writeLoader('entityManager'));
	}

	public function testMissingFile(): void
	{
		$this->expectException(CheckerException::class);
		$this->expectExceptionMessage('does not exist');

		(new EntityManagerLoader())->load(sprintf('%s/missing.php', $this->directory));
	}

	private function writeLoader(string $property): string
	{
		$file = sprintf('%s/loader.php', $this->directory);
		FileSystem::write($file, sprintf("<?php\n\nreturn \\%s::$%s;\n", self::class, $property));

		return $file;
	}

}
