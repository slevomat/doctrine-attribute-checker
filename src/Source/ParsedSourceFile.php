<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Source;

use PhpParser\Node\Stmt;
use PhpParser\Token;

final class ParsedSourceFile
{

	/**
	 * @param list<Stmt> $statements
	 * @param list<Stmt> $originalStatements
	 * @param list<Token> $tokens
	 */
	public function __construct(
		public array $statements,
		public readonly array $originalStatements,
		public readonly array $tokens,
		public readonly string $source,
	)
	{
	}

}
