<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Rule\Mapping;

use Override;
use Slevomat\DoctrineAttributeChecker\Checker\DoctrineAttributeContext;
use Slevomat\DoctrineAttributeChecker\Checker\ViolationReporter;
use Slevomat\DoctrineAttributeChecker\Configuration\Configuration;
use Slevomat\DoctrineAttributeChecker\Rule\AttributeRule;
use function array_flip;
use function array_unique;
use function array_values;
use function count;
use function is_array;
use function is_string;
use function sprintf;
use function strtolower;
use function usort;

final class CascadeOrderRule implements AttributeRule
{

	private const array ATTRIBUTES = ['ManyToMany', 'ManyToOne', 'OneToMany', 'OneToOne'];

	public function __construct(
		private Configuration $configuration,
	)
	{
	}

	#[Override]
	public static function getIdentifiers(Configuration $configuration): array
	{
		$identifiers = [];
		foreach (self::ATTRIBUTES as $attributeName) {
			$identifiers[] = strtolower($attributeName) . '.cascade-order';
			$identifiers[] = strtolower($attributeName) . '.cascade-values';
		}

		return $identifiers;
	}

	#[Override]
	public function getAttributeNames(): array
	{
		return self::ATTRIBUTES;
	}

	#[Override]
	public function process(DoctrineAttributeContext $context, ViolationReporter $reporter): void
	{
		$attributeName = $context->getShortAttributeName();
		$cascade = $context->arguments['cascade'] ?? null;
		if (!is_array($cascade)) {
			return;
		}

		$cascadeValues = [];
		foreach ($cascade as $action) {
			if (!is_string($action)) {
				$reporter->addViolation(
					$context,
					strtolower($attributeName) . '.cascade-values',
					sprintf('%s.cascade must contain only strings.', $attributeName),
				);

				return;
			}

			$cascadeValues[] = $action;
		}
		if ($cascadeValues === []) {
			return;
		}

		$sortedCascade = array_values(array_unique($cascadeValues));
		$order = array_flip($this->configuration->cascadeOrder);
		usort(
			$sortedCascade,
			static fn (string $a, string $b): int => ($order[$a] ?? count($order))
				<=> ($order[$b] ?? count($order))
				?: $a <=> $b,
		);
		if ($sortedCascade === $cascadeValues) {
			return;
		}

		$cascadeOrder = $this->configuration->cascadeOrder;
		$reporter->fixViolation(
			$context,
			strtolower($attributeName) . '.cascade-order',
			sprintf('%s.cascade must use the canonical order and contain no duplicates.', $attributeName),
			static fn (): bool => $context->normalizeStringArrayArgument('cascade', $cascadeOrder),
		);
	}

}
