<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Checker\Database;

use Slevomat\DoctrineAttributeChecker\Checker\DoctrineAttributeContext;
use Slevomat\DoctrineAttributeChecker\Checker\ViolationReporter;
use function array_key_exists;
use function array_map;
use function count;
use function implode;
use function is_array;
use function serialize;
use function sprintf;

final class DatabaseExpectationApplier
{

	/**
	 * Synchronizes the attribute with the expected values. When a property maps several database columns
	 * (e.g. a mapped superclass property inherited by several entities) whose expected values differ,
	 * a non-fixable violation is reported instead.
	 *
	 * @param list<DatabaseExpectation> $expectations
	 */
	public function apply(DoctrineAttributeContext $context, ViolationReporter $reporter, array $expectations): void
	{
		/** @var array<string, non-empty-list<DatabaseExpectation>> $groups */
		$groups = [];
		foreach ($expectations as $expectation) {
			$groups[($expectation->option ? 'option:' : 'argument:') . $expectation->name][] = $expectation;
		}

		foreach ($groups as $group) {
			$first = $group[0];
			$distinctValues = [];
			foreach ($group as $expectation) {
				$distinctValues[serialize($expectation->value)] = true;
			}

			if (count($distinctValues) > 1) {
				$reporter->addViolation(
					$context,
					$first->rule,
					sprintf(
						'%s.%s%s cannot match the database because the mapped columns differ: %s.',
						$context->getShortAttributeName(),
						$first->option ? 'options.' : '',
						$first->name,
						implode(', ', array_map(
							static fn (DatabaseExpectation $expectation): string => sprintf(
								'%s (%s)',
								$expectation->column,
								$expectation->formatValue(),
							),
							$group,
						)),
					),
				);

				continue;
			}

			if ($first->option) {
				$this->syncOption($context, $reporter, $first->name, $first->value, $first->rule, $first->message);
			} else {
				$this->syncArgument($context, $reporter, $first->name, $first->value, $first->rule, $first->message);
			}
		}
	}

	private function syncArgument(
		DoctrineAttributeContext $context,
		ViolationReporter $reporter,
		string $argumentName,
		string|int|float|bool|null $expectedValue,
		string $rule,
		string $message,
	): void
	{
		if ($expectedValue === null) {
			if (!array_key_exists($argumentName, $context->arguments)) {
				return;
			}

			$reporter->fixViolation(
				$context,
				$rule,
				$message,
				static fn (): bool => $context->removeArgument($argumentName),
			);

			return;
		}

		if (
			array_key_exists($argumentName, $context->arguments)
			&& $context->arguments[$argumentName] === $expectedValue
		) {
			return;
		}

		$reporter->fixViolation(
			$context,
			$rule,
			$message,
			static fn (): bool => $context->setArgument($argumentName, $expectedValue),
		);
	}

	private function syncOption(
		DoctrineAttributeContext $context,
		ViolationReporter $reporter,
		string $optionName,
		string|int|float|bool|null $expectedValue,
		string $rule,
		string $message,
	): void
	{
		$options = $context->arguments['options'] ?? [];
		$hasOption = is_array($options) && array_key_exists($optionName, $options);
		if ($expectedValue === null) {
			if (!$hasOption) {
				return;
			}

			$reporter->fixViolation(
				$context,
				$rule,
				$message,
				static fn (): bool => $context->removeOption($optionName),
			);

			return;
		}

		if ($hasOption && $options[$optionName] === $expectedValue) {
			return;
		}

		$reporter->fixViolation(
			$context,
			$rule,
			$message,
			static fn (): bool => $context->setOption($optionName, $expectedValue),
		);
	}

}
