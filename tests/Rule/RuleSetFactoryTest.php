<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Rule;

use Slevomat\DoctrineAttributeChecker\Checker\DoctrineMapping;
use Slevomat\DoctrineAttributeChecker\Checker\FactoryMetadataTestCase;
use Slevomat\DoctrineAttributeChecker\Checker\Identifiers;
use Slevomat\DoctrineAttributeChecker\Configuration\ConfigurationLoader;
use Slevomat\DoctrineAttributeChecker\Rules;
use Slevomat\DoctrineAttributeChecker\Source\AttributeSourceEditor;
use function array_filter;
use function array_map;
use function array_values;
use function is_subclass_of;
use function sort;
use function str_starts_with;

final class RuleSetFactoryTest extends FactoryMetadataTestCase
{

	public function testStrictDatabaseRuleSetContainsAllRuleClasses(): void
	{
		self::assertSame($this->getExpectedRuleClasses(true, true), $this->getCreatedRuleClasses(true, true));
	}

	public function testDatabaseRuleSetContainsNoStrictRules(): void
	{
		self::assertSame($this->getExpectedRuleClasses(true, false), $this->getCreatedRuleClasses(true, false));
	}

	public function testRuleSetWithoutDatabaseContainsNoDatabaseRules(): void
	{
		self::assertSame($this->getExpectedRuleClasses(false, true), $this->getCreatedRuleClasses(false, true));
	}

	public function testStrictRulesReportOnlyDatabaseRules(): void
	{
		$configuration = (new ConfigurationLoader())->load(ConfigurationLoader::getDefaultsFile());
		$strictRules = Rules::getStrict($configuration);

		self::assertContains('database.column-allows-null', $strictRules);
		self::assertNotContains('database.column-rejects-null', $strictRules);
		self::assertSame([], array_values(array_filter(
			$strictRules,
			static fn (string $rule): bool => !Rules::isDatabaseRule($rule),
		)));
	}

	/**
	 * @return list<class-string<Rule>>
	 */
	private function getExpectedRuleClasses(bool $database, bool $strict): array
	{
		$ruleClasses = array_values(array_filter(
			RuleSetFactory::RULE_CLASSES,
			static fn (string $ruleClass): bool => ($database || !str_starts_with($ruleClass, __NAMESPACE__ . '\\Database\\'))
				&& (($database && $strict) || !is_subclass_of($ruleClass, StrictRule::class)),
		));
		sort($ruleClasses);

		return $ruleClasses;
	}

	/**
	 * @return list<class-string<Rule>>
	 */
	private function getCreatedRuleClasses(bool $database, bool $strict): array
	{
		$configuration = (new ConfigurationLoader())->load(ConfigurationLoader::getDefaultsFile());
		$entityManager = $this->createEntityManager(__DIR__);
		$ruleSet = RuleSetFactory::create(
			$configuration,
			new DoctrineMapping($entityManager, []),
			new Identifiers($configuration),
			new AttributeSourceEditor(),
			$database ? $this->createSchema([]) : null,
			$strict,
		);

		$ruleClasses = array_map(static fn (Rule $rule): string => $rule::class, $ruleSet->getRules());
		sort($ruleClasses);

		return $ruleClasses;
	}

}
