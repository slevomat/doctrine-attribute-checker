<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Baseline;

use Nette\Neon\Neon;
use Nette\Utils\FileSystem;
use Slevomat\DoctrineAttributeChecker\Violation;
use function array_values;
use function count;
use function implode;
use function serialize;
use function sprintf;
use function usort;

/**
 * @phpstan-import-type BaselineEntry from Baseline
 */
final class BaselineWriter
{

	/**
	 * @param list<Violation> $violations
	 * @return int Number of written entries.
	 */
	public function write(string $file, string $rootDirectory, array $violations): int
	{
		$entries = [];
		foreach ($violations as $violation) {
			$entry = Baseline::createEntry($violation, $rootDirectory);
			$entries[serialize($entry)] = $entry;
		}

		$entries = array_values($entries);
		usort(
			$entries,
			static fn (array $a, array $b): int => [$a['path'], $a['target'], $a['rule'], $a['message']]
				<=> [$b['path'], $b['target'], $b['rule'], $b['message']],
		);

		FileSystem::write($file, $this->encode($entries));

		return count($entries);
	}

	/**
	 * @param list<BaselineEntry> $entries
	 */
	private function encode(array $entries): string
	{
		if ($entries === []) {
			return "ignoreErrors: []\n";
		}

		$blocks = [];
		foreach ($entries as $entry) {
			$block = "\t-\n";
			foreach (['rule', 'target', 'path', 'message'] as $key) {
				$block .= sprintf("\t\t%s: %s\n", $key, Neon::encode($entry[$key]));
			}

			$blocks[] = $block;
		}

		return sprintf("ignoreErrors:\n%s", implode("\n", $blocks));
	}

}
