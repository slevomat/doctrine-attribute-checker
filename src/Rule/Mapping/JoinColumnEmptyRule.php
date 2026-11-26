<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Rule\Mapping;

use Override;
use Slevomat\DoctrineAttributeChecker\Checker\DoctrineAttributeContext;
use Slevomat\DoctrineAttributeChecker\Checker\ViolationReporter;
use Slevomat\DoctrineAttributeChecker\Configuration\Configuration;
use Slevomat\DoctrineAttributeChecker\Rule\AttributeRule;

final class JoinColumnEmptyRule implements AttributeRule
{

	#[Override]
	public static function getIdentifiers(Configuration $configuration): array
	{
		return ['join-column.empty'];
	}

	#[Override]
	public function getAttributeNames(): array
	{
		return ['JoinColumn', 'InverseJoinColumn'];
	}

	#[Override]
	public function process(DoctrineAttributeContext $context, ViolationReporter $reporter): void
	{
		if ($context->arguments !== [] || $context->synthetic || $context->databaseJoinTable) {
			return;
		}

		$reporter->fixViolation(
			$context,
			'join-column.empty',
			'JoinColumn has no arguments and can be inferred.',
			static fn (): bool => $context->removeFromSource(),
		);
	}

}
