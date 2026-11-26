<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Rule\Mapping;

use Doctrine\ORM\Mapping\ManyToManyOwningSideMapping;
use Override;
use Slevomat\DoctrineAttributeChecker\Checker\DoctrineAttributeContext;
use Slevomat\DoctrineAttributeChecker\Checker\Identifiers;
use Slevomat\DoctrineAttributeChecker\Checker\ViolationReporter;
use Slevomat\DoctrineAttributeChecker\Configuration\Configuration;
use Slevomat\DoctrineAttributeChecker\Rule\AttributeRule;
use function array_key_exists;
use function is_string;

final class JoinTableRule implements AttributeRule
{

	public function __construct(
		private Identifiers $identifiers,
	)
	{
	}

	#[Override]
	public static function getIdentifiers(Configuration $configuration): array
	{
		return ['identifier.quoting', 'join-table.name'];
	}

	#[Override]
	public function getAttributeNames(): array
	{
		return ['JoinTable'];
	}

	#[Override]
	public function process(DoctrineAttributeContext $context, ViolationReporter $reporter): void
	{
		if (!array_key_exists('name', $context->arguments) || !is_string($context->arguments['name'])) {
			$propertyName = $context->property?->name;
			$associationMapping = $propertyName === null
				? null
				: ($context->classMetadata?->associationMappings[$propertyName] ?? null);
			if (!$associationMapping instanceof ManyToManyOwningSideMapping) {
				$reporter->addViolation(
					$context,
					'join-table.name',
					'JoinTable.name must be specified explicitly.',
				);

				return;
			}

			$reporter->fixViolation(
				$context,
				'join-table.name',
				'JoinTable.name must be specified explicitly.',
				static fn (): bool => $context->setArgument('name', $associationMapping->joinTable->name),
			);
		}

		$this->identifiers->checkQuoting($context, $reporter, 'name');
	}

}
