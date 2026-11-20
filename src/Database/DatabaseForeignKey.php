<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Database;

final class DatabaseForeignKey
{

	public function __construct(
		public readonly string $constraintName,
		public readonly string $referencedTable,
		public readonly string $referencedColumn,
		public readonly string $deleteRule,
	)
	{
	}

}
