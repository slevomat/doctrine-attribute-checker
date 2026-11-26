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
use function sprintf;

final class ColumnTypeRule implements AttributeRule
{

	public function __construct(
		private DoctrineMapping $mapping,
	)
	{
	}

	#[Override]
	public static function getIdentifiers(Configuration $configuration): array
	{
		return ['column.enum-type', 'column.type', 'column.type-json'];
	}

	#[Override]
	public function getAttributeNames(): array
	{
		return ['Column'];
	}

	#[Override]
	public function process(DoctrineAttributeContext $context, ViolationReporter $reporter): void
	{
		if ($context->property === null) {
			return;
		}

		$typedFieldMapper = $this->mapping->getOrmConfiguration()->getTypedFieldMapper();
		if ($typedFieldMapper === null) {
			return;
		}

		$inferredMapping = $typedFieldMapper->validateAndComplete(
			['fieldName' => $context->property->name],
			$context->property,
		);
		$inferredType = $inferredMapping['type'] ?? null;
		if ($inferredType === 'json' && !array_key_exists('type', $context->arguments)) {
			$reporter->fixViolation(
				$context,
				'column.type-json',
				'Column.type must be explicitly specified for a JSON property.',
				static fn (): bool => $context->setArgument('type', 'json'),
			);
		} elseif (
			$inferredType !== 'json'
			&& is_string($inferredType)
			&& ($context->arguments['type'] ?? null) === $inferredType
		) {
			$reporter->fixViolation(
				$context,
				'column.type',
				sprintf('Column.type is inferred as %s from the PHP property type.', $inferredType),
				static fn (): bool => $context->removeArgument('type'),
			);
		}

		$inferredEnumType = $inferredMapping['enumType'] ?? null;
		if (is_string($inferredEnumType) && ($context->arguments['enumType'] ?? null) === $inferredEnumType) {
			$reporter->fixViolation(
				$context,
				'column.enum-type',
				sprintf('Column.enumType is inferred as %s from the PHP property type.', $inferredEnumType),
				static fn (): bool => $context->removeArgument('enumType'),
			);
		}
	}

}
