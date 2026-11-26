<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Rule;

use Slevomat\DoctrineAttributeChecker\Checker\DoctrineAttributeContext;
use Slevomat\DoctrineAttributeChecker\Checker\ViolationReporter;

interface AttributeRule extends Rule
{

	/**
	 * @return list<string>
	 */
	public function getAttributeNames(): array;

	public function process(DoctrineAttributeContext $context, ViolationReporter $reporter): void;

}
