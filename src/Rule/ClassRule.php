<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Rule;

use Slevomat\DoctrineAttributeChecker\Checker\ClassContext;
use Slevomat\DoctrineAttributeChecker\Checker\ViolationReporter;

interface ClassRule extends Rule
{

	public function process(ClassContext $context, ViolationReporter $reporter): void;

}
