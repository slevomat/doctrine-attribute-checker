<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker;

use RuntimeException;
use Throwable;

class CheckerException extends RuntimeException
{

	public function __construct(string $message, ?Throwable $previous = null)
	{
		parent::__construct($message, 0, $previous);
	}

}
