<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Console;

use Doctrine\ORM\EntityManagerInterface;
use Slevomat\DoctrineAttributeChecker\Database\SchemaSource;

/**
 * Value returned by the entity manager loader when the checker needs more than the entity manager.
 */
final class CheckerEnvironment
{

	/**
	 * @param list<SchemaSource> $additionalSchemaSources Databases whose tables are used when missing in the primary one.
	 */
	public function __construct(
		public readonly EntityManagerInterface $entityManager,
		public readonly array $additionalSchemaSources = [],
	)
	{
	}

}
