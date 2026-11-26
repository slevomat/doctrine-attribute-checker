<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Rule;

use Slevomat\DoctrineAttributeChecker\Configuration\Configuration;

interface Rule
{

	/**
	 * @return list<string>
	 */
	public static function getIdentifiers(Configuration $configuration): array;

}
