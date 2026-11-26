<?php declare(strict_types = 1);

// spell-check-ignore: manytoone onetoone

namespace Slevomat\DoctrineAttributeChecker\Rule\Mapping;

use Override;
use ReflectionNamedType;
use Slevomat\DoctrineAttributeChecker\Checker\DoctrineAttributeContext;
use Slevomat\DoctrineAttributeChecker\Checker\ViolationReporter;
use Slevomat\DoctrineAttributeChecker\Configuration\Configuration;
use Slevomat\DoctrineAttributeChecker\Rule\AttributeRule;
use function array_key_exists;
use function is_string;
use function ltrim;
use function sprintf;
use function strtolower;

final class ToOneAssociationRule implements AttributeRule
{

	#[Override]
	public static function getIdentifiers(Configuration $configuration): array
	{
		return [
			'manytoone.fetch',
			'manytoone.target-entity',
			'onetoone.fetch',
			'onetoone.orphan-removal',
			'onetoone.target-entity',
		];
	}

	#[Override]
	public function getAttributeNames(): array
	{
		return ['ManyToOne', 'OneToOne'];
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

		if ($attributeName === 'OneToOne' && ($context->arguments['orphanRemoval'] ?? null) === false) {
			$reporter->fixViolation(
				$context,
				'onetoone.orphan-removal',
				'OneToOne.orphanRemoval is false by default.',
				static fn (): bool => $context->removeArgument('orphanRemoval'),
			);
		}

		if ($context->property === null || !array_key_exists('targetEntity', $context->arguments)) {
			return;
		}

		$propertyType = $context->property->getType();
		if (!$propertyType instanceof ReflectionNamedType || $propertyType->isBuiltin()) {
			return;
		}

		$targetEntity = $context->arguments['targetEntity'];
		if (!is_string($targetEntity)) {
			$reporter->addViolation(
				$context,
				strtolower($attributeName) . '.target-entity',
				sprintf('%s.targetEntity must be a class name string.', $attributeName),
			);

			return;
		}

		$targetEntity = ltrim($targetEntity, '\\');
		if ($targetEntity === $propertyType->getName()) {
			$reporter->fixViolation(
				$context,
				strtolower($attributeName) . '.target-entity',
				sprintf('%s.targetEntity is inferred from the PHP property type.', $attributeName),
				static fn (): bool => $context->removeArgument('targetEntity'),
			);

			return;
		}

		$reporter->addViolation(
			$context,
			strtolower($attributeName) . '.target-entity',
			sprintf(
				'%s.targetEntity %s does not match the property type %s.',
				$attributeName,
				$targetEntity,
				$propertyType->getName(),
			),
		);
	}

}
