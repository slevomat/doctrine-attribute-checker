<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Rule\Database;

use Doctrine\ORM\Mapping\ManyToManyOwningSideMapping;
use Override;
use Slevomat\DoctrineAttributeChecker\Checker\ClassContext;
use Slevomat\DoctrineAttributeChecker\Checker\ViolationReporter;
use Slevomat\DoctrineAttributeChecker\Configuration\Configuration;
use Slevomat\DoctrineAttributeChecker\Database\DatabaseSchemaSnapshot;
use Slevomat\DoctrineAttributeChecker\Rule\ClassRule;
use function is_string;
use function sprintf;

final class DatabaseTableRule implements ClassRule
{

	public function __construct(
		private DatabaseSchemaSnapshot $databaseSchema,
	)
	{
	}

	#[Override]
	public static function getIdentifiers(Configuration $configuration): array
	{
		return ['database.join-table-missing', 'database.table-missing'];
	}

	#[Override]
	public function process(ClassContext $context, ViolationReporter $reporter): void
	{
		$databaseSchema = $this->databaseSchema;
		$classMetadata = $context->classMetadata;
		if (
			$classMetadata->isMappedSuperclass
			|| $classMetadata->isEmbeddedClass
			|| $context->reflectionClass->isAbstract()
		) {
			return;
		}

		$tableSchema = $classMetadata->table['schema'] ?? null;
		if (is_string($tableSchema) && $tableSchema !== $databaseSchema->getSchema()) {
			return;
		}

		$tableName = $classMetadata->getTableName();
		if (!$databaseSchema->hasTable($tableName)) {
			$reporter->addViolationAt(
				$context->file,
				$context->classNode->getStartLine(),
				$context->className,
				'database.table-missing',
				sprintf('Table %s does not exist in database %s.', $tableName, $databaseSchema->getSchema()),
				false,
			);
		}

		foreach ($classMetadata->associationMappings as $associationMapping) {
			if (!$associationMapping instanceof ManyToManyOwningSideMapping) {
				continue;
			}

			$joinTableName = $associationMapping->joinTable->name;
			if ($databaseSchema->hasTable($joinTableName)) {
				continue;
			}

			$reporter->addViolationAt(
				$context->file,
				$context->classNode->getStartLine(),
				sprintf('%s::$%s', $context->className, $associationMapping->fieldName),
				'database.join-table-missing',
				sprintf('Join table %s does not exist in database %s.', $joinTableName, $databaseSchema->getSchema()),
				false,
			);
		}
	}

}
