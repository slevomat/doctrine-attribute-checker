<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker;

final class Violation
{

	public function __construct(
		public readonly string $file,
		public readonly int $line,
		public readonly string $target,
		public readonly string $rule,
		public readonly string $message,
		public readonly bool $fixable,
	)
	{
	}

}
