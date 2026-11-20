<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Baseline;

use Slevomat\DoctrineAttributeChecker\Violation;
use Webmozart\Assert\Assert;
use function array_key_exists;
use function serialize;

/**
 * @phpstan-type BaselineEntry array{rule: string, target: string, path: string, message: string}
 */
final class Baseline
{

	/** @var array<string, BaselineEntry> */
	private array $entriesByKey = [];

	/** @var array<string, true> */
	private array $matchedEntries = [];

	/**
	 * @param list<BaselineEntry> $entries Entry paths are relative to the root directory.
	 */
	public function __construct(
		private readonly string $rootDirectory,
		array $entries,
	)
	{
		foreach ($entries as $entry) {
			$key = $this->createKey($entry);
			Assert::false(
				array_key_exists($key, $this->entriesByKey),
				'Duplicate Doctrine attribute baseline entry.',
			);
			$this->entriesByKey[$key] = $entry;
		}
	}

	/**
	 * @param list<Violation> $violations
	 * @return list<Violation>
	 */
	public function filter(array $violations): array
	{
		$unmatchedViolations = [];

		foreach ($violations as $violation) {
			$key = $this->createKey(self::createEntry($violation, $this->rootDirectory));
			if (!array_key_exists($key, $this->entriesByKey)) {
				$unmatchedViolations[] = $violation;

				continue;
			}

			$this->matchedEntries[$key] = true;
		}

		return $unmatchedViolations;
	}

	/**
	 * @return list<BaselineEntry>
	 */
	public function getUnmatchedEntries(): array
	{
		$unmatchedEntries = [];
		foreach ($this->entriesByKey as $key => $entry) {
			if (array_key_exists($key, $this->matchedEntries)) {
				continue;
			}

			$unmatchedEntries[] = $entry;
		}

		return $unmatchedEntries;
	}

	/**
	 * @return BaselineEntry
	 */
	public static function createEntry(Violation $violation, string $rootDirectory): array
	{
		return [
			'rule' => $violation->rule,
			'target' => $violation->target,
			'path' => RelativePath::fromRoot($rootDirectory, $violation->file),
			'message' => $violation->message,
		];
	}

	/**
	 * @param BaselineEntry $entry
	 */
	private function createKey(array $entry): string
	{
		return serialize([$entry['rule'], $entry['target'], $entry['path'], $entry['message']]);
	}

}
