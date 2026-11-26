<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Rule\Mapping;

use Override;
use Slevomat\DoctrineAttributeChecker\Checker\DoctrineAttributeContext;
use Slevomat\DoctrineAttributeChecker\Checker\ViolationReporter;
use Slevomat\DoctrineAttributeChecker\Configuration\Configuration;
use Slevomat\DoctrineAttributeChecker\Rule\AttributeRule;
use function sprintf;

final class ForbiddenAttributeRule implements AttributeRule
{

	public function __construct(
		private Configuration $configuration,
	)
	{
	}

	#[Override]
	public static function getIdentifiers(Configuration $configuration): array
	{
		return ['forbidden.attribute'];
	}

	#[Override]
	public function getAttributeNames(): array
	{
		return $this->configuration->forbiddenAttributes;
	}

	#[Override]
	public function process(DoctrineAttributeContext $context, ViolationReporter $reporter): void
	{
		$reporter->addViolation(
			$context,
			'forbidden.attribute',
			sprintf('Doctrine attribute %s is forbidden.', $context->getShortAttributeName()),
		);
	}

}
