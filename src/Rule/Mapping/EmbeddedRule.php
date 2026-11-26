<?php declare(strict_types = 1);

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

final class EmbeddedRule implements AttributeRule
{

	#[Override]
	public static function getIdentifiers(Configuration $configuration): array
	{
		return ['embedded.class'];
	}

	#[Override]
	public function getAttributeNames(): array
	{
		return ['Embedded'];
	}

	#[Override]
	public function process(DoctrineAttributeContext $context, ViolationReporter $reporter): void
	{
		if ($context->property === null || !array_key_exists('class', $context->arguments)) {
			return;
		}

		$propertyType = $context->property->getType();
		if (!$propertyType instanceof ReflectionNamedType || $propertyType->isBuiltin()) {
			return;
		}

		$embeddedClass = $context->arguments['class'];
		if (!is_string($embeddedClass)) {
			$reporter->addViolation(
				$context,
				'embedded.class',
				'Embedded.class must be a class name string.',
			);

			return;
		}

		$embeddedClass = ltrim($embeddedClass, '\\');
		if ($embeddedClass !== $propertyType->getName()) {
			return;
		}

		$reporter->fixViolation(
			$context,
			'embedded.class',
			'Embedded.class is inferred from the PHP property type.',
			static fn (): bool => $context->removeArgument('class'),
		);
	}

}
