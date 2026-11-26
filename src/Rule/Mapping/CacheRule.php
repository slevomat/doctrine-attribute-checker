<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Rule\Mapping;

use Override;
use Slevomat\DoctrineAttributeChecker\Checker\DoctrineAttributeContext;
use Slevomat\DoctrineAttributeChecker\Checker\ViolationReporter;
use Slevomat\DoctrineAttributeChecker\Configuration\Configuration;
use Slevomat\DoctrineAttributeChecker\Rule\AttributeRule;
use function array_key_exists;
use function array_map;
use function sprintf;

final class CacheRule implements AttributeRule
{

	public function __construct(
		private Configuration $configuration,
	)
	{
	}

	#[Override]
	public static function getIdentifiers(Configuration $configuration): array
	{
		return array_map(
			static fn (string $argumentName): string => 'cache.' . $argumentName,
			$configuration->forbiddenCacheArguments,
		);
	}

	#[Override]
	public function getAttributeNames(): array
	{
		return ['Cache'];
	}

	#[Override]
	public function process(DoctrineAttributeContext $context, ViolationReporter $reporter): void
	{
		foreach ($this->configuration->forbiddenCacheArguments as $argumentName) {
			if (!array_key_exists($argumentName, $context->arguments)) {
				continue;
			}

			$reporter->addViolation(
				$context,
				'cache.' . $argumentName,
				sprintf('Cache.%s is forbidden; use the configured cache defaults.', $argumentName),
			);
		}
	}

}
