<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Checker;

use Slevomat\DoctrineAttributeChecker\Configuration\Configuration;
use Slevomat\DoctrineAttributeChecker\Violation;

final class ViolationReporter
{

	private bool $fix = false;

	private bool $changed = false;

	/** @var list<Violation> */
	private array $violations = [];

	public function __construct(
		private Configuration $configuration,
	)
	{
	}

	public function start(bool $fix): void
	{
		$this->fix = $fix;
		$this->changed = false;
		$this->violations = [];
	}

	public function isFixing(): bool
	{
		return $this->fix;
	}

	public function canFix(string $rule): bool
	{
		return $this->fix && $this->configuration->isRuleEnabled($rule);
	}

	public function markChanged(): void
	{
		$this->changed = true;
	}

	public function hasChanges(): bool
	{
		return $this->changed;
	}

	/**
	 * @return list<Violation>
	 */
	public function getViolations(): array
	{
		return $this->violations;
	}

	public function addViolation(
		DoctrineAttributeContext $context,
		string $rule,
		string $message,
		bool $fixable = false,
	): void
	{
		$this->addViolationAt($context->file, $context->line, $context->target, $rule, $message, $fixable);
	}

	/**
	 * @param callable(): bool $fix
	 */
	public function fixViolation(
		DoctrineAttributeContext $context,
		string $rule,
		string $message,
		callable $fix,
	): void
	{
		if (!$this->configuration->isRuleEnabled($rule)) {
			return;
		}

		if ($this->fix && $fix()) {
			$this->changed = true;

			return;
		}

		$this->addViolation($context, $rule, $message, !$this->fix);
	}

	public function addViolationAt(
		string $file,
		int $line,
		string $target,
		string $rule,
		string $message,
		bool $fixable,
	): void
	{
		if (!$this->configuration->isRuleEnabled($rule)) {
			return;
		}

		$violation = new Violation($file, $line, $target, $rule, $message, $fixable);

		// A property mapped to several database columns may produce the same violation more than once.
		foreach ($this->violations as $existingViolation) {
			if (
				$existingViolation->file === $violation->file
				&& $existingViolation->line === $violation->line
				&& $existingViolation->target === $violation->target
				&& $existingViolation->rule === $violation->rule
				&& $existingViolation->message === $violation->message
			) {
				return;
			}
		}

		$this->violations[] = $violation;
	}

}
