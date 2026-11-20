<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Checker;

use Doctrine\ORM\Mapping\NamingStrategy;
use Override;
use Webmozart\Assert\Assert;
use function preg_replace;
use function str_contains;
use function strrpos;
use function strtolower;
use function substr;

final class FixtureNamingStrategy implements NamingStrategy
{

	#[Override]
	public function classToTableName(string $className): string
	{
		if (str_contains($className, '\\')) {
			$position = strrpos($className, '\\');
			if ($position !== false) {
				$className = substr($className, $position + 1);
			}
		}

		return $this->underscore($className);
	}

	#[Override]
	public function propertyToColumnName(string $propertyName, string $className): string
	{
		return $this->underscore($propertyName);
	}

	#[Override]
	public function embeddedFieldToColumnName(
		string $propertyName,
		string $embeddedColumnName,
		string $className,
		string $embeddedClassName,
	): string
	{
		return $this->underscore($propertyName) . '_' . $embeddedColumnName;
	}

	#[Override]
	public function referenceColumnName(): string
	{
		return 'id';
	}

	#[Override]
	public function joinColumnName(string $propertyName, string $className): string
	{
		return $this->underscore($propertyName);
	}

	#[Override]
	public function joinTableName(string $sourceEntity, string $targetEntity, string $propertyName): string
	{
		return $this->classToTableName($sourceEntity) . '_' . $this->classToTableName($targetEntity);
	}

	#[Override]
	public function joinKeyColumnName(string $entityName, ?string $referencedColumnName): string
	{
		return $this->classToTableName($entityName);
	}

	private function underscore(string $string): string
	{
		$string = preg_replace([
			'/(?<=[a-z0-9])([A-Z])/',
			'/(?<=[^0-9])([0-9])/',
		], '_$1', $string);
		Assert::notNull($string);

		return strtolower($string);
	}

}
