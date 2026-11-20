<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Database;

use Doctrine\DBAL\Connection;

/**
 * Additional database whose tables are merged into the schema snapshot when they are missing in the primary database.
 */
final class SchemaSource
{

	/**
	 * @param string $queryPrefix Prepended to every information_schema query, e.g. an SQL comment used for routing.
	 */
	public function __construct(
		public readonly Connection $connection,
		public readonly string $queryPrefix = '',
	)
	{
	}

}
