<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Checker;

use function is_bool;
use function sprintf;

/**
 * Value of an attribute argument or option that is expected according to one database column.
 */
final class DatabaseExpectation
{

	public function __construct(
		public readonly bool $option,
		public readonly string $name,
		public readonly string|int|float|bool|null $value,
		public readonly string $rule,
		public readonly string $message,
		public readonly string $column,
	)
	{
	}

	public function formatValue(): string
	{
		if ($this->value === null) {
			return 'none';
		}

		if (is_bool($this->value)) {
			return $this->value ? 'true' : 'false';
		}

		return sprintf('%s', $this->value);
	}

}
