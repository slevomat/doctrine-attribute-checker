<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Checker;

use Doctrine\ORM\Mapping\ClassMetadata;
use PhpParser\Node\Stmt\ClassLike;
use ReflectionClass;

final class ClassContext
{

	/**
	 * @param class-string $className
	 * @param ReflectionClass<object> $reflectionClass
	 * @param ClassMetadata<object> $classMetadata
	 */
	public function __construct(
		public readonly ClassLike $classNode,
		public readonly string $className,
		public readonly string $file,
		public readonly ReflectionClass $reflectionClass,
		public readonly ClassMetadata $classMetadata,
	)
	{
	}

}
