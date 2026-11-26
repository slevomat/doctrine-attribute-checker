<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Checker;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\JoinColumnMapping;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use ReflectionMethod;
use ReflectionProperty;
use Slevomat\DoctrineAttributeChecker\Source\AttributeSourceEditor;
use Slevomat\DoctrineAttributeChecker\Source\ParsedSourceFile;
use function array_key_exists;
use function is_array;
use function strlen;
use function substr;

/**
 * @phpstan-import-type DoctrineAttributeValue from AttributeSourceEditor
 * @phpstan-import-type DoctrineAttributeWritableValue from AttributeSourceEditor
 */
final class DoctrineAttributeContext
{

	public const string MAPPING_NAMESPACE = 'Doctrine\\ORM\\Mapping\\';

	/**
	 * @param class-string $className
	 * @param ?ClassMetadata<object> $classMetadata
	 * @param array<int|string, DoctrineAttributeValue> $arguments
	 */
	public function __construct(
		public readonly Attribute|Expr\New_ $attribute,
		public readonly ClassLike|ClassMethod|Property $owner,
		public readonly ?Attribute $container,
		public readonly string $attributeClassName,
		public readonly string $className,
		public readonly string $target,
		public readonly string $file,
		public readonly int $line,
		public readonly ?ReflectionProperty $property,
		public readonly ?ReflectionMethod $method,
		public readonly ?ClassMetadata $classMetadata,
		public array $arguments,
		private AttributeSourceEditor $editor,
		public readonly ?string $defaultName = null,
		public readonly ?string $databaseTableName = null,
		public readonly ?JoinColumnMapping $databaseJoinColumn = null,
		public readonly ?string $databaseReferencedTableName = null,
		public readonly bool $databaseOneToOne = false,
		public readonly bool $databaseJoinTable = false,
		public readonly bool $synthetic = false,
		public readonly ?ParsedSourceFile $sourceFile = null,
	)
	{
	}

	public function getShortAttributeName(): string
	{
		return substr($this->attributeClassName, strlen(self::MAPPING_NAMESPACE));
	}

	/** @param DoctrineAttributeWritableValue $value */
	public function setArgument(string $name, string|int|float|bool|array|null $value): bool
	{
		if (($this->arguments[$name] ?? null) === $value && array_key_exists($name, $this->arguments)) {
			return false;
		}

		if (!$this->editor->setArgument($this->attribute, $name, $value, $this->className)) {
			return false;
		}

		$this->arguments[$name] = $value;

		return true;
	}

	public function removeArgument(string $name): bool
	{
		if (!$this->editor->removeArgument($this->attribute, $name)) {
			return false;
		}

		unset($this->arguments[$name]);

		return true;
	}

	public function setOption(string $name, string|int|float|bool|null $value): bool
	{
		$options = $this->arguments['options'] ?? [];
		if (is_array($options) && ($options[$name] ?? null) === $value && array_key_exists($name, $options)) {
			return false;
		}

		if (!$this->editor->setArgumentInArray($this->attribute, 'options', $name, $value, $this->className)) {
			return false;
		}

		if (!is_array($options)) {
			$options = [];
		}
		$options[$name] = $value;
		$this->arguments['options'] = $options;

		return true;
	}

	public function removeOption(string $name): bool
	{
		if (!$this->editor->removeArgumentFromArray($this->attribute, 'options', $name, $this->className)) {
			return false;
		}

		$options = $this->arguments['options'] ?? [];
		if (is_array($options)) {
			unset($options[$name]);
			if ($options === []) {
				unset($this->arguments['options']);
			} else {
				$this->arguments['options'] = $options;
			}
		}

		return true;
	}

	/** @param list<string> $orderedNames */
	public function sortArguments(array $orderedNames): bool
	{
		return $this->editor->sortArguments($this->attribute, $orderedNames);
	}

	/** @param list<string> $orderedNames */
	public function argumentsAreSorted(array $orderedNames): bool
	{
		return $this->editor->argumentsAreSorted($this->attribute, $orderedNames);
	}

	/** @param list<string> $orderedNames */
	public function sortOptions(array $orderedNames): bool
	{
		return $this->editor->sortArrayArgument($this->attribute, 'options', $orderedNames, $this->className);
	}

	/** @param list<string> $orderedNames */
	public function optionsAreSorted(array $orderedNames): bool
	{
		return $this->editor->arrayArgumentIsSorted($this->attribute, 'options', $orderedNames, $this->className);
	}

	/**
	 * @param list<string> $orderedValues
	 */
	public function normalizeStringArrayArgument(string $name, array $orderedValues): bool
	{
		return $this->editor->normalizeStringArrayArgument($this->attribute, $name, $orderedValues, $this->className);
	}

	public function removeFromSource(): bool
	{
		if ($this->attribute instanceof Attribute) {
			return $this->editor->removeAttribute($this->owner, $this->attribute);
		}

		return $this->container !== null
			&& $this->editor->removeNestedArrayItem($this->container, $this->attribute);
	}

}
