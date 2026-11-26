<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Rule;

use Slevomat\DoctrineAttributeChecker\Checker\ClassContext;
use Slevomat\DoctrineAttributeChecker\Checker\DoctrineAttributeContext;
use Slevomat\DoctrineAttributeChecker\Checker\ViolationReporter;
use Slevomat\DoctrineAttributeChecker\Rule\Mapping\ForbiddenAttributeRule;
use function in_array;

/**
 * The order of rules matters, fixes of one rule are visible to the following rules.
 */
final class RuleSet
{

	/** @var array<string, list<AttributeRule>> */
	private array $attributeRulesByName = [];

	/** @var list<string> */
	private array $forbiddenAttributeNames;

	/**
	 * @param list<AttributeRule> $attributeRules
	 * @param list<ClassRule> $classRules
	 */
	public function __construct(
		private ForbiddenAttributeRule $forbiddenAttributeRule,
		private array $attributeRules,
		private array $classRules,
	)
	{
		$this->forbiddenAttributeNames = $forbiddenAttributeRule->getAttributeNames();
		foreach ($attributeRules as $rule) {
			foreach ($rule->getAttributeNames() as $attributeName) {
				$this->attributeRulesByName[$attributeName][] = $rule;
			}
		}
	}

	/**
	 * @return list<Rule>
	 */
	public function getRules(): array
	{
		return [$this->forbiddenAttributeRule, ...$this->attributeRules, ...$this->classRules];
	}

	public function processAttribute(DoctrineAttributeContext $context, ViolationReporter $reporter): void
	{
		if (in_array($context->getShortAttributeName(), $this->forbiddenAttributeNames, true)) {
			$this->forbiddenAttributeRule->process($context, $reporter);

			return;
		}

		$this->processAttributeRules($context, $reporter);
	}

	public function processJoinColumn(DoctrineAttributeContext $context, ViolationReporter $reporter): void
	{
		$this->processAttributeRules($context, $reporter);
	}

	public function processClass(ClassContext $context, ViolationReporter $reporter): void
	{
		foreach ($this->classRules as $rule) {
			$rule->process($context, $reporter);
		}
	}

	private function processAttributeRules(DoctrineAttributeContext $context, ViolationReporter $reporter): void
	{
		foreach ($this->attributeRulesByName[$context->getShortAttributeName()] ?? [] as $rule) {
			$rule->process($context, $reporter);
		}
	}

}
