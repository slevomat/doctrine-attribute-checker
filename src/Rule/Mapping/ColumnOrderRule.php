<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Rule\Mapping;

use Override;
use Slevomat\DoctrineAttributeChecker\Checker\DoctrineAttributeContext;
use Slevomat\DoctrineAttributeChecker\Checker\ViolationReporter;
use Slevomat\DoctrineAttributeChecker\Configuration\Configuration;
use Slevomat\DoctrineAttributeChecker\Rule\AttributeRule;
use function is_array;

final class ColumnOrderRule implements AttributeRule
{

	public function __construct(
		private Configuration $configuration,
	)
	{
	}

	#[Override]
	public static function getIdentifiers(Configuration $configuration): array
	{
		return ['column.argument-order', 'column.options.order'];
	}

	#[Override]
	public function getAttributeNames(): array
	{
		return ['Column'];
	}

	#[Override]
	public function process(DoctrineAttributeContext $context, ViolationReporter $reporter): void
	{
		$optionOrder = $this->configuration->columnOptionOrder;
		$options = $context->arguments['options'] ?? [];
		if (is_array($options) && !$context->optionsAreSorted($optionOrder)) {
			$reporter->fixViolation(
				$context,
				'column.options.order',
				'Column.options must use the canonical key order.',
				static fn (): bool => $context->sortOptions($optionOrder),
			);
		}

		$argumentOrder = $this->configuration->columnArgumentOrder;
		if ($context->argumentsAreSorted($argumentOrder)) {
			return;
		}

		$reporter->fixViolation(
			$context,
			'column.argument-order',
			'Column arguments must use the canonical order.',
			static fn (): bool => $context->sortArguments($argumentOrder),
		);
	}

}
