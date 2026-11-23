<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Console;

use Nette\Utils\FileSystem;
use Slevomat\DoctrineAttributeChecker\Checker\FactoryMetadataTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use function sprintf;
use function sys_get_temp_dir;
use function uniqid;

final class CheckAttributesCommandEmbeddableTest extends FactoryMetadataTestCase
{

	public function testEmbeddableClassesAreInspected(): void
	{
		$configurationFile = sprintf('%s/%s.neon', sys_get_temp_dir(), uniqid('doctrine-attribute-command-', true));
		FileSystem::write($configurationFile, "rules:\n\tdisabled: []\n");
		try {
			$entityManager = $this->createEntityManager(__DIR__ . '/../Checker/data/Embeddable');
			$tester = new CommandTester(new CheckAttributesCommand($entityManager));

			$status = $tester->execute(['--configuration' => $configurationFile]);
		} finally {
			FileSystem::delete($configurationFile);
		}

		self::assertSame(Command::FAILURE, $status);
		self::assertStringContainsString('EmbeddableAddress', $tester->getDisplay());
		self::assertStringContainsString('$zip: Column.nullable is inferred from the PHP property type.', $tester->getDisplay());
	}

}
