<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Checker\Database;

use Doctrine\ORM\Mapping\FieldMapping;

final class DatabaseFieldTarget
{

	public function __construct(
		public readonly string $table,
		public readonly FieldMapping $fieldMapping,
		public readonly bool $nullableColumnAllowed,
	)
	{
	}

}
