<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Rule\Mapping;

use Override;
use Slevomat\DoctrineAttributeChecker\Checker\DoctrineAttributeContext;
use Slevomat\DoctrineAttributeChecker\Checker\ViolationReporter;
use Slevomat\DoctrineAttributeChecker\Configuration\Configuration;
use Slevomat\DoctrineAttributeChecker\Rule\AttributeRule;
use function sprintf;
use function strtolower;

final class ToManyAssociationRule implements AttributeRule
{

	#[Override]
	public static function getIdentifiers(Configuration $configuration): array
	{
		return [
			'manytomany.fetch',
			'manytomany.orphan-removal',
			'onetomany.fetch',
			'onetomany.orphan-removal',
		];
	}

	#[Override]
	public function getAttributeNames(): array
	{
		return ['ManyToMany', 'OneToMany'];
	}

	#[Override]
	public function process(DoctrineAttributeContext $context, ViolationReporter $reporter): void
	{
		$attributeName = $context->getShortAttributeName();
		if (($context->arguments['fetch'] ?? null) === 'LAZY') {
			$reporter->fixViolation(
				$context,
				strtolower($attributeName) . '.fetch',
				sprintf('%s.fetch is LAZY by default.', $attributeName),
				static fn (): bool => $context->removeArgument('fetch'),
			);
		}

		if (($context->arguments['orphanRemoval'] ?? null) !== false) {
			return;
		}

		$reporter->fixViolation(
			$context,
			strtolower($attributeName) . '.orphan-removal',
			sprintf('%s.orphanRemoval is false by default.', $attributeName),
			static fn (): bool => $context->removeArgument('orphanRemoval'),
		);
	}

}
