<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Rule;

use Slevomat\DoctrineAttributeChecker\Checker\Database\DatabaseColumnComparator;
use Slevomat\DoctrineAttributeChecker\Checker\Database\DatabaseExpectationApplier;
use Slevomat\DoctrineAttributeChecker\Checker\Database\DatabaseTargetResolver;
use Slevomat\DoctrineAttributeChecker\Checker\DoctrineMapping;
use Slevomat\DoctrineAttributeChecker\Checker\Identifiers;
use Slevomat\DoctrineAttributeChecker\Configuration\Configuration;
use Slevomat\DoctrineAttributeChecker\Database\DatabaseSchemaSnapshot;
use Slevomat\DoctrineAttributeChecker\Rule\Database\DatabaseColumnNarrowerRule;
use Slevomat\DoctrineAttributeChecker\Rule\Database\DatabaseColumnNotInsertedRule;
use Slevomat\DoctrineAttributeChecker\Rule\Database\DatabaseColumnRule;
use Slevomat\DoctrineAttributeChecker\Rule\Database\DatabaseColumnStrictRule;
use Slevomat\DoctrineAttributeChecker\Rule\Database\DatabaseDiscriminatorColumnRule;
use Slevomat\DoctrineAttributeChecker\Rule\Database\DatabaseDiscriminatorColumnStrictRule;
use Slevomat\DoctrineAttributeChecker\Rule\Database\DatabaseIdentityColumnRule;
use Slevomat\DoctrineAttributeChecker\Rule\Database\DatabaseJoinColumnRule;
use Slevomat\DoctrineAttributeChecker\Rule\Database\DatabaseJoinColumnStrictRule;
use Slevomat\DoctrineAttributeChecker\Rule\Database\DatabaseTableRule;
use Slevomat\DoctrineAttributeChecker\Rule\Mapping\CacheRule;
use Slevomat\DoctrineAttributeChecker\Rule\Mapping\CascadeOrderRule;
use Slevomat\DoctrineAttributeChecker\Rule\Mapping\ColumnArgumentsRule;
use Slevomat\DoctrineAttributeChecker\Rule\Mapping\ColumnEmptyParenthesesRule;
use Slevomat\DoctrineAttributeChecker\Rule\Mapping\ColumnNameRule;
use Slevomat\DoctrineAttributeChecker\Rule\Mapping\ColumnOrderRule;
use Slevomat\DoctrineAttributeChecker\Rule\Mapping\ColumnTypeRule;
use Slevomat\DoctrineAttributeChecker\Rule\Mapping\EmbeddedRule;
use Slevomat\DoctrineAttributeChecker\Rule\Mapping\EntityRule;
use Slevomat\DoctrineAttributeChecker\Rule\Mapping\ForbiddenAttributeRule;
use Slevomat\DoctrineAttributeChecker\Rule\Mapping\GeneratedValueRule;
use Slevomat\DoctrineAttributeChecker\Rule\Mapping\JoinColumnEmptyRule;
use Slevomat\DoctrineAttributeChecker\Rule\Mapping\JoinColumnRule;
use Slevomat\DoctrineAttributeChecker\Rule\Mapping\JoinTableRule;
use Slevomat\DoctrineAttributeChecker\Rule\Mapping\LifecycleCallbackRule;
use Slevomat\DoctrineAttributeChecker\Rule\Mapping\TableAttributeMissingRule;
use Slevomat\DoctrineAttributeChecker\Rule\Mapping\TableRule;
use Slevomat\DoctrineAttributeChecker\Rule\Mapping\ToManyAssociationRule;
use Slevomat\DoctrineAttributeChecker\Rule\Mapping\ToOneAssociationRule;
use Slevomat\DoctrineAttributeChecker\Source\AttributeSourceEditor;

final class RuleSetFactory
{

	public const array RULE_CLASSES = [
		ForbiddenAttributeRule::class,
		LifecycleCallbackRule::class,
		EntityRule::class,
		CacheRule::class,
		GeneratedValueRule::class,
		ColumnNameRule::class,
		ColumnTypeRule::class,
		ColumnArgumentsRule::class,
		DatabaseColumnRule::class,
		DatabaseColumnStrictRule::class,
		DatabaseColumnNarrowerRule::class,
		ColumnOrderRule::class,
		ColumnEmptyParenthesesRule::class,
		JoinColumnRule::class,
		DatabaseJoinColumnRule::class,
		DatabaseJoinColumnStrictRule::class,
		JoinColumnEmptyRule::class,
		TableRule::class,
		JoinTableRule::class,
		CascadeOrderRule::class,
		ToOneAssociationRule::class,
		ToManyAssociationRule::class,
		EmbeddedRule::class,
		DatabaseDiscriminatorColumnRule::class,
		DatabaseDiscriminatorColumnStrictRule::class,
		DatabaseTableRule::class,
		DatabaseColumnNotInsertedRule::class,
		DatabaseIdentityColumnRule::class,
		TableAttributeMissingRule::class,
	];

	public static function create(
		Configuration $configuration,
		DoctrineMapping $mapping,
		Identifiers $identifiers,
		AttributeSourceEditor $sourceEditor,
		?DatabaseSchemaSnapshot $databaseSchema,
		bool $strict = false,
	): RuleSet
	{
		$columnDatabaseRules = [];
		$joinColumnDatabaseRules = [];
		$discriminatorColumnDatabaseRules = [];
		$classDatabaseRules = [];
		if ($databaseSchema !== null) {
			$targetResolver = new DatabaseTargetResolver($mapping);
			$comparator = new DatabaseColumnComparator($mapping, $databaseSchema);
			$expectationApplier = new DatabaseExpectationApplier();

			$columnDatabaseRules[] = new DatabaseColumnRule($databaseSchema, $targetResolver, $comparator);
			$joinColumnDatabaseRules[] = new DatabaseJoinColumnRule($databaseSchema, $targetResolver);
			$discriminatorColumnDatabaseRules[] = new DatabaseDiscriminatorColumnRule($comparator);
			if ($strict) {
				$columnDatabaseRules[] = new DatabaseColumnStrictRule($targetResolver, $comparator, $expectationApplier);
				$joinColumnDatabaseRules[] = new DatabaseJoinColumnStrictRule(
					$databaseSchema,
					$targetResolver,
					$expectationApplier,
				);
				$discriminatorColumnDatabaseRules[] = new DatabaseDiscriminatorColumnStrictRule(
					$comparator,
					$expectationApplier,
				);
			}

			// Registered after the strict rule, so that it sees the length synchronized by a fix.
			$columnDatabaseRules[] = new DatabaseColumnNarrowerRule($databaseSchema, $targetResolver);
			$classDatabaseRules = [
				new DatabaseTableRule($databaseSchema),
				new DatabaseColumnNotInsertedRule($databaseSchema),
				new DatabaseIdentityColumnRule($databaseSchema),
			];
		}

		return new RuleSet(
			new ForbiddenAttributeRule($configuration),
			[
				new LifecycleCallbackRule($configuration),
				new EntityRule($configuration, $mapping),
				new CacheRule($configuration),
				new GeneratedValueRule(),
				new ColumnNameRule($mapping, $identifiers),
				new ColumnTypeRule($mapping),
				new ColumnArgumentsRule($configuration),
				...$columnDatabaseRules,
				new ColumnOrderRule($configuration),
				new ColumnEmptyParenthesesRule($sourceEditor),
				new JoinColumnRule($mapping, $identifiers),
				...$joinColumnDatabaseRules,
				new JoinColumnEmptyRule(),
				new TableRule($configuration, $identifiers),
				new JoinTableRule($identifiers),
				new CascadeOrderRule($configuration),
				new ToOneAssociationRule(),
				new ToManyAssociationRule(),
				new EmbeddedRule(),
				...$discriminatorColumnDatabaseRules,
			],
			[
				...$classDatabaseRules,
				new TableAttributeMissingRule($sourceEditor, $identifiers),
			],
		);
	}

}
