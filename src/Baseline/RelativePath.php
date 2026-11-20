<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Baseline;

use function realpath;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use const DIRECTORY_SEPARATOR;

final class RelativePath
{

	/**
	 * Returns the path of the file relative to the root directory with forward slashes,
	 * or the unchanged path when the file lies outside the root directory.
	 */
	public static function fromRoot(string $rootDirectory, string $file): string
	{
		$canonicalRootDirectory = realpath($rootDirectory);
		if ($canonicalRootDirectory !== false) {
			$rootDirectory = $canonicalRootDirectory;
		}

		$canonicalFile = realpath($file);
		if ($canonicalFile !== false) {
			$file = $canonicalFile;
		}

		$rootPrefix = $rootDirectory . DIRECTORY_SEPARATOR;
		if (!str_starts_with($file, $rootPrefix)) {
			return $file;
		}

		return str_replace(DIRECTORY_SEPARATOR, '/', substr($file, strlen($rootPrefix)));
	}

}
