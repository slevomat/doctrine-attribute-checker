<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Rule;

use Slevomat\DoctrineAttributeChecker\Checker\DoctrineMapping;
use Slevomat\DoctrineAttributeChecker\Checker\FactoryMetadataTestCase;
use Slevomat\DoctrineAttributeChecker\Checker\Identifiers;
use Slevomat\DoctrineAttributeChecker\Configuration\ConfigurationLoader;
use Slevomat\DoctrineAttributeChecker\Rule\Database\DatabaseColumnNarrowerRule;
use Slevomat\DoctrineAttributeChecker\Rule\Database\DatabaseColumnNotInsertedRule;
use Slevomat\DoctrineAttributeChecker\Rule\Database\DatabaseColumnRule;
use Slevomat\DoctrineAttributeChecker\Rule\Database\DatabaseDiscriminatorColumnRule;
use Slevomat\DoctrineAttributeChecker\Rule\Database\DatabaseIdentityColumnRule;
use Slevomat\DoctrineAttributeChecker\Rule\Database\DatabaseJoinColumnRule;
use Slevomat\DoctrineAttributeChecker\Rule\Database\DatabaseTableRule;
use Slevomat\DoctrineAttributeChecker\Source\AttributeSourceEditor;
use function array_diff;
use function array_map;
use function array_values;
use function sort;

final class RuleSetFactoryTest extends FactoryMetadataTestCase
{

	public function testDatabaseRuleSetContainsAllRuleClasses(): void
	{
		$ruleClasses = $this->getCreatedRuleClasses(true);
		$expectedRuleClasses = RuleSetFactory::RULE_CLASSES;
		sort($expectedRuleClasses);

		self::assertSame($expectedRuleClasses, $ruleClasses);
	}

	public function testRuleSetWithoutDatabaseContainsNoDatabaseRules(): void
	{
		$expectedRuleClasses = array_values(array_diff(RuleSetFactory::RULE_CLASSES, [
			DatabaseColumnNarrowerRule::class,
			DatabaseColumnNotInsertedRule::class,
			DatabaseColumnRule::class,
			DatabaseDiscriminatorColumnRule::class,
			DatabaseIdentityColumnRule::class,
			DatabaseJoinColumnRule::class,
			DatabaseTableRule::class,
		]));
		sort($expectedRuleClasses);

		self::assertSame($expectedRuleClasses, $this->getCreatedRuleClasses(false));
	}

	/**
	 * @return list<class-string<Rule>>
	 */
	private function getCreatedRuleClasses(bool $database): array
	{
		$configuration = (new ConfigurationLoader())->load(ConfigurationLoader::getDefaultsFile());
		$entityManager = $this->createEntityManager(__DIR__);
		$ruleSet = RuleSetFactory::create(
			$configuration,
			new DoctrineMapping($entityManager, []),
			new Identifiers($configuration),
			new AttributeSourceEditor(),
			$database ? $this->createSchema([]) : null,
		);

		$ruleClasses = array_map(static fn (Rule $rule): string => $rule::class, $ruleSet->getRules());
		sort($ruleClasses);

		return $ruleClasses;
	}

}
