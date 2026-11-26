<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Database;

final class DatabaseColumn
{

	public function __construct(
		public readonly string $dataType,
		public readonly string $columnType,
		public readonly ?int $characterMaximumLength,
		public readonly ?int $numericPrecision,
		public readonly ?int $numericScale,
		public readonly bool $nullable,
		public readonly ?string $collation,
		public readonly bool $hasDefault = false,
		public readonly bool $autoIncrement = false,
		public readonly bool $generated = false,
	)
	{
	}

}
