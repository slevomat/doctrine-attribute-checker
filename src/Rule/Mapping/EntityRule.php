<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Rule\Mapping;

use Override;
use Slevomat\DoctrineAttributeChecker\Checker\DoctrineAttributeContext;
use Slevomat\DoctrineAttributeChecker\Checker\DoctrineMapping;
use Slevomat\DoctrineAttributeChecker\Checker\ViolationReporter;
use Slevomat\DoctrineAttributeChecker\Configuration\Configuration;
use Slevomat\DoctrineAttributeChecker\Rule\AttributeRule;
use function array_key_exists;
use function is_string;
use function ltrim;

final class EntityRule implements AttributeRule
{

	public function __construct(
		private Configuration $configuration,
		private DoctrineMapping $mapping,
	)
	{
	}

	#[Override]
	public static function getIdentifiers(Configuration $configuration): array
	{
		return ['entity.repository-class'];
	}

	#[Override]
	public function getAttributeNames(): array
	{
		return ['Entity'];
	}

	#[Override]
	public function process(DoctrineAttributeContext $context, ViolationReporter $reporter): void
	{
		if (!array_key_exists('repositoryClass', $context->arguments)) {
			return;
		}

		$repositoryClass = $context->arguments['repositoryClass'];
		if (is_string($repositoryClass)) {
			$repositoryClass = ltrim($repositoryClass, '\\');
		}

		if ($repositoryClass === $this->mapping->getOrmConfiguration()->getDefaultRepositoryClassName()) {
			$reporter->fixViolation(
				$context,
				'entity.repository-class',
				'Entity.repositoryClass is redundant because the repository class is configured globally.',
				static fn (): bool => $context->removeArgument('repositoryClass'),
			);

			return;
		}

		if ($this->configuration->allowCustomRepositoryClass) {
			return;
		}

		$reporter->addViolation(
			$context,
			'entity.repository-class',
			'Entity.repositoryClass is forbidden; repositories are standalone classes.',
		);
	}

}
