<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Console;

use Doctrine\ORM\EntityManagerInterface;
use Slevomat\DoctrineAttributeChecker\CheckerException;
use function get_debug_type;
use function is_file;
use function sprintf;

/**
 * Loads the entity manager from the PHP file configured as entityManagerLoader.
 * The file must return an EntityManagerInterface or a CheckerEnvironment.
 */
final class EntityManagerLoader
{

	public function load(string $file): CheckerEnvironment
	{
		if (!is_file($file)) {
			throw new CheckerException(sprintf('Entity manager loader %s does not exist.', $file));
		}

		$result = self::requireFile($file);
		if ($result instanceof CheckerEnvironment) {
			return $result;
		}

		if ($result instanceof EntityManagerInterface) {
			return new CheckerEnvironment($result);
		}

		throw new CheckerException(sprintf(
			'Entity manager loader %s must return %s or %s, %s returned.',
			$file,
			EntityManagerInterface::class,
			CheckerEnvironment::class,
			get_debug_type($result),
		));
	}

	private static function requireFile(string $file): mixed
	{
		return require $file;
	}

}
