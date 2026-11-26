<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Rule\Mapping;

use Override;
use Slevomat\DoctrineAttributeChecker\Checker\DoctrineAttributeContext;
use Slevomat\DoctrineAttributeChecker\Checker\ViolationReporter;
use Slevomat\DoctrineAttributeChecker\Configuration\Configuration;
use Slevomat\DoctrineAttributeChecker\Rule\AttributeRule;
use function sprintf;

final class LifecycleCallbackRule implements AttributeRule
{

	private const array ATTRIBUTES = [
		'HasLifecycleCallbacks',
		'PostLoad',
		'PostPersist',
		'PostRemove',
		'PostUpdate',
		'PrePersist',
		'PreRemove',
		'PreUpdate',
	];

	public function __construct(
		private Configuration $configuration,
	)
	{
	}

	#[Override]
	public static function getIdentifiers(Configuration $configuration): array
	{
		return ['forbidden.lifecycle-callback'];
	}

	#[Override]
	public function getAttributeNames(): array
	{
		return self::ATTRIBUTES;
	}

	#[Override]
	public function process(DoctrineAttributeContext $context, ViolationReporter $reporter): void
	{
		$shortName = $context->getShortAttributeName();
		if (
			$context->property === null
			&& $this->configuration->isLifecycleCallbackAllowed($context->className, $context->method?->name, $shortName)
		) {
			return;
		}

		$reporter->addViolation(
			$context,
			'forbidden.lifecycle-callback',
			sprintf('Doctrine lifecycle callback attribute %s is not used.', $shortName),
		);
	}

}
