<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Rule\Database;

use Doctrine\ORM\Mapping\FieldMapping;
use Override;
use Slevomat\DoctrineAttributeChecker\Checker\Database\DatabaseColumnComparator;
use Slevomat\DoctrineAttributeChecker\Checker\Database\DatabaseFieldTarget;
use Slevomat\DoctrineAttributeChecker\Checker\Database\DatabaseTargetResolver;
use Slevomat\DoctrineAttributeChecker\Checker\DoctrineAttributeContext;
use Slevomat\DoctrineAttributeChecker\Checker\Identifiers;
use Slevomat\DoctrineAttributeChecker\Checker\ViolationReporter;
use Slevomat\DoctrineAttributeChecker\Configuration\Configuration;
use Slevomat\DoctrineAttributeChecker\Database\DatabaseColumn;
use Slevomat\DoctrineAttributeChecker\Database\DatabaseSchemaSnapshot;
use Slevomat\DoctrineAttributeChecker\Rule\AttributeRule;
use function in_array;
use function is_int;
use function sprintf;
use function str_starts_with;
use function strtolower;

final class DatabaseColumnNarrowerRule implements AttributeRule
{

	public function __construct(
		private DatabaseSchemaSnapshot $databaseSchema,
		private DatabaseTargetResolver $targetResolver,
	)
	{
	}

	#[Override]
	public static function getIdentifiers(Configuration $configuration): array
	{
		return ['database.column-narrower'];
	}

	#[Override]
	public function getAttributeNames(): array
	{
		return ['Column'];
	}

	#[Override]
	public function process(DoctrineAttributeContext $context, ViolationReporter $reporter): void
	{
		if ($context->property === null || $context->classMetadata === null) {
			return;
		}

		foreach ($this->targetResolver->getFieldTargets($context->classMetadata, $context->property->name) as $target) {
			$databaseColumn = $this->databaseSchema->getColumn($target->table, $target->fieldMapping->columnName);
			if ($databaseColumn === null) {
				continue;
			}

			$this->checkLength($context, $reporter, $target, $databaseColumn);
			$this->checkDecimal($context, $reporter, $target, $databaseColumn);
		}
	}

	private function checkLength(
		DoctrineAttributeContext $context,
		ViolationReporter $reporter,
		DatabaseFieldTarget $target,
		DatabaseColumn $databaseColumn,
	): void
	{
		$length = $context->arguments['length'] ?? null;
		if (
			!is_int($length)
			|| $databaseColumn->characterMaximumLength === null
			|| $databaseColumn->characterMaximumLength >= $length
			|| !in_array($databaseColumn->dataType, DatabaseColumnComparator::LENGTH_DATABASE_TYPES, true)
			|| $this->isUuidType($target->fieldMapping)
		) {
			return;
		}

		$reporter->addViolation(
			$context,
			'database.column-narrower',
			sprintf(
				'Database column %s length %d is shorter than %s.length %d.',
				$this->formatColumn($target),
				$databaseColumn->characterMaximumLength,
				$context->getShortAttributeName(),
				$length,
			),
		);
	}

	private function checkDecimal(
		DoctrineAttributeContext $context,
		ViolationReporter $reporter,
		DatabaseFieldTarget $target,
		DatabaseColumn $databaseColumn,
	): void
	{
		$precision = $context->arguments['precision'] ?? null;
		$scale = $context->arguments['scale'] ?? 0;
		if (
			!is_int($precision)
			|| !is_int($scale)
			|| !in_array($databaseColumn->dataType, ['decimal', 'numeric'], true)
			|| $databaseColumn->numericPrecision === null
			|| $databaseColumn->numericScale === null
		) {
			return;
		}

		$databaseIntegerDigits = $databaseColumn->numericPrecision - $databaseColumn->numericScale;
		if ($databaseColumn->numericScale >= $scale && $databaseIntegerDigits >= $precision - $scale) {
			return;
		}

		$reporter->addViolation(
			$context,
			'database.column-narrower',
			sprintf(
				'Database column %s %s cannot hold all values of %s.precision %d and scale %d.',
				$this->formatColumn($target),
				$databaseColumn->columnType,
				$context->getShortAttributeName(),
				$precision,
				$scale,
			),
		);
	}

	private function isUuidType(FieldMapping $fieldMapping): bool
	{
		return str_starts_with(strtolower($fieldMapping->type), 'uuid');
	}

	private function formatColumn(DatabaseFieldTarget $target): string
	{
		return sprintf('%s.%s', Identifiers::unquote($target->table), $target->fieldMapping->columnName);
	}

}
