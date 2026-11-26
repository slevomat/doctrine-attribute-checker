<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Rule\Mapping;

use Override;
use Slevomat\DoctrineAttributeChecker\Checker\DoctrineAttributeContext;
use Slevomat\DoctrineAttributeChecker\Checker\ViolationReporter;
use Slevomat\DoctrineAttributeChecker\Configuration\Configuration;
use Slevomat\DoctrineAttributeChecker\Rule\AttributeRule;
use function array_key_exists;
use function array_map;
use function array_merge;
use function is_array;
use function sprintf;

final class ColumnArgumentsRule implements AttributeRule
{

	public function __construct(
		private Configuration $configuration,
	)
	{
	}

	#[Override]
	public static function getIdentifiers(Configuration $configuration): array
	{
		return array_merge(
			['column.nullable', 'column.unique'],
			array_map(
				static fn (string $optionName): string => 'column.options.' . $optionName,
				$configuration->forbiddenColumnOptions,
			),
			array_map(
				static fn (string $argumentName): string => 'column.' . $argumentName,
				$configuration->forbiddenColumnArguments,
			),
		);
	}

	#[Override]
	public function getAttributeNames(): array
	{
		return ['Column'];
	}

	#[Override]
	public function process(DoctrineAttributeContext $context, ViolationReporter $reporter): void
	{
		if (array_key_exists('nullable', $context->arguments)) {
			$reporter->fixViolation(
				$context,
				'column.nullable',
				'Column.nullable is inferred from the PHP property type.',
				static fn (): bool => $context->removeArgument('nullable'),
			);
		}

		if (($context->arguments['unique'] ?? null) === false) {
			$reporter->fixViolation(
				$context,
				'column.unique',
				'Column.unique=false is the default and should be omitted.',
				static fn (): bool => $context->removeArgument('unique'),
			);
		}

		$options = $context->arguments['options'] ?? [];
		if (is_array($options)) {
			foreach ($this->configuration->forbiddenColumnOptions as $optionName) {
				if (!array_key_exists($optionName, $options)) {
					continue;
				}

				$reporter->fixViolation(
					$context,
					'column.options.' . $optionName,
					sprintf('Column.options.%s is not maintained in Doctrine mapping.', $optionName),
					static fn (): bool => $context->removeOption($optionName),
				);
			}
		}

		foreach ($this->configuration->forbiddenColumnArguments as $argumentName) {
			if (!array_key_exists($argumentName, $context->arguments)) {
				continue;
			}

			$reporter->addViolation(
				$context,
				'column.' . $argumentName,
				sprintf('Column.%s is forbidden.', $argumentName),
			);
		}
	}

}
