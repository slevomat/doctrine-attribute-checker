<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Checker\Database;

use Doctrine\ORM\Mapping\JoinColumnMapping;
use Doctrine\ORM\Mapping\ToOneOwningSideMapping;

final class DatabaseJoinColumnTarget
{

	public function __construct(
		public readonly string $table,
		public readonly string $referencedTable,
		public readonly bool $oneToOne,
		public readonly JoinColumnMapping $joinColumn,
		public readonly ?ToOneOwningSideMapping $association,
		public readonly bool $singleTableSubclass,
	)
	{
	}

}
