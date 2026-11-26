<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Rule\Mapping;

use Override;
use Slevomat\DoctrineAttributeChecker\Checker\DoctrineAttributeContext;
use Slevomat\DoctrineAttributeChecker\Checker\ViolationReporter;
use Slevomat\DoctrineAttributeChecker\Configuration\Configuration;
use Slevomat\DoctrineAttributeChecker\Rule\AttributeRule;
use function array_key_exists;
use function is_string;
use function strtoupper;

final class GeneratedValueRule implements AttributeRule
{

	#[Override]
	public static function getIdentifiers(Configuration $configuration): array
	{
		return ['generated-value.strategy'];
	}

	#[Override]
	public function getAttributeNames(): array
	{
		return ['GeneratedValue'];
	}

	#[Override]
	public function process(DoctrineAttributeContext $context, ViolationReporter $reporter): void
	{
		if (!array_key_exists('strategy', $context->arguments)) {
			return;
		}

		$strategy = $context->arguments['strategy'];
		if (!is_string($strategy)) {
			$reporter->addViolation(
				$context,
				'generated-value.strategy',
				'GeneratedValue.strategy must be a string.',
			);

			return;
		}

		if (strtoupper($strategy) === 'NONE') {
			$reporter->fixViolation(
				$context,
				'generated-value.strategy',
				'GeneratedValue with the NONE strategy is redundant; identifiers without GeneratedValue are not generated.',
				static fn (): bool => $context->removeFromSource(),
			);

			return;
		}

		if (strtoupper($strategy) === 'AUTO') {
			$reporter->fixViolation(
				$context,
				'generated-value.strategy',
				'GeneratedValue.strategy is redundant when it uses the default AUTO strategy.',
				static fn (): bool => $context->removeArgument('strategy'),
			);

			return;
		}

		$reporter->addViolation(
			$context,
			'generated-value.strategy',
			'GeneratedValue.strategy is forbidden; use the default AUTO strategy.',
		);
	}

}
