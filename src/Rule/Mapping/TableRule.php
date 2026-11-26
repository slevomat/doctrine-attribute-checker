<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Rule\Mapping;

use Override;
use Slevomat\DoctrineAttributeChecker\Checker\DoctrineAttributeContext;
use Slevomat\DoctrineAttributeChecker\Checker\Identifiers;
use Slevomat\DoctrineAttributeChecker\Checker\ViolationReporter;
use Slevomat\DoctrineAttributeChecker\Configuration\Configuration;
use Slevomat\DoctrineAttributeChecker\Rule\AttributeRule;
use function array_key_exists;
use function array_map;
use function array_merge;
use function is_string;
use function sprintf;

final class TableRule implements AttributeRule
{

	public function __construct(
		private Configuration $configuration,
		private Identifiers $identifiers,
	)
	{
	}

	#[Override]
	public static function getIdentifiers(Configuration $configuration): array
	{
		return array_merge(
			['identifier.quoting', 'table.name', 'table.unique-constraints'],
			array_map(
				static fn (string $argumentName): string => 'table.' . $argumentName,
				$configuration->forbiddenTableArguments,
			),
		);
	}

	#[Override]
	public function getAttributeNames(): array
	{
		return ['Table'];
	}

	#[Override]
	public function process(DoctrineAttributeContext $context, ViolationReporter $reporter): void
	{
		if (!array_key_exists('name', $context->arguments) || !is_string($context->arguments['name'])) {
			$tableName = $context->classMetadata?->getTableName();
			if ($tableName !== null) {
				$reporter->fixViolation(
					$context,
					'table.name',
					'Table.name must be specified explicitly.',
					static fn (): bool => $context->setArgument('name', $tableName),
				);
			} else {
				$reporter->addViolation(
					$context,
					'table.name',
					'Table.name must be specified explicitly.',
				);
			}
		}

		if (isset($context->arguments['name']) && is_string($context->arguments['name'])) {
			$this->identifiers->checkQuoting($context, $reporter, 'name');
		}

		if (array_key_exists('uniqueConstraints', $context->arguments)) {
			$reporter->fixViolation(
				$context,
				'table.unique-constraints',
				'Table.uniqueConstraints is not maintained in Doctrine mapping; unique indexes are synchronized from the database.',
				static fn (): bool => $context->removeArgument('uniqueConstraints'),
			);
		}

		foreach ($this->configuration->forbiddenTableArguments as $argumentName) {
			if (!array_key_exists($argumentName, $context->arguments)) {
				continue;
			}

			$reporter->addViolation(
				$context,
				'table.' . $argumentName,
				sprintf('Table.%s is not maintained in Doctrine mapping.', $argumentName),
			);
		}
	}

}
