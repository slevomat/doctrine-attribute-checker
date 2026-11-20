<?php declare(strict_types = 1);

// spell-check-ignore: Traverser

namespace Slevomat\DoctrineAttributeChecker\Source;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use ReflectionClass;
use Slevomat\DoctrineAttributeChecker\CheckerException;
use function array_filter;
use function array_flip;
use function array_keys;
use function array_push;
use function array_splice;
use function array_values;
use function class_exists;
use function constant;
use function count;
use function defined;
use function enum_exists;
use function get_debug_type;
use function interface_exists;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_object;
use function is_scalar;
use function is_string;
use function preg_match;
use function sprintf;
use function str_starts_with;
use function strrpos;
use function strtolower;
use function substr;
use function trait_exists;
use function usort;

/**
 * @phpstan-type DoctrineAttributeValue scalar|object|array<array-key, scalar|object|array<array-key, scalar|object|null>|null>|null
 * @phpstan-type DoctrineAttributeWritableValue scalar|array<array-key, scalar|array<array-key, ?scalar>|null>|null
 */
final class AttributeSourceEditor
{

	private Parser $parser;

	private Standard $printer;

	public function __construct()
	{
		$this->parser = (new ParserFactory())->createForNewestSupportedVersion();
		$this->printer = new Standard(['indent' => "\t"]);
	}

	public function parse(string $source, string $file): ParsedSourceFile
	{
		try {
			$parsedNodes = $this->parser->parse($source);
			$tokens = $this->parser->getTokens();
		} catch (Error $e) {
			throw new CheckerException(sprintf('Cannot parse %s: %s', $file, $e->getMessage()), $e);
		}

		if ($parsedNodes === null) {
			throw new CheckerException(sprintf('Cannot parse %s: the file is empty.', $file));
		}

		$originalStatements = $this->getStatements($parsedNodes, $file);
		$cloneTraverser = new NodeTraverser(new CloningVisitor());
		$statements = $this->getStatements($cloneTraverser->traverse($originalStatements), $file);

		$nameResolverTraverser = new NodeTraverser(new NameResolver(null, ['replaceNodes' => false]));
		$statements = $this->getStatements($nameResolverTraverser->traverse($statements), $file);

		return new ParsedSourceFile($statements, $originalStatements, array_values($tokens), $source);
	}

	/**
	 * @param array<Node> $nodes
	 * @return list<Stmt>
	 */
	private function getStatements(array $nodes, string $file): array
	{
		$statements = [];
		foreach ($nodes as $node) {
			if (!$node instanceof Stmt) {
				throw new CheckerException(sprintf(
					'Cannot process %s: parser returned a top-level node of type %s.',
					$file,
					get_debug_type($node),
				));
			}

			$statements[] = $node;
		}

		return $statements;
	}

	public function print(ParsedSourceFile $sourceFile): string
	{
		return $this->printer->printFormatPreserving(
			$sourceFile->statements,
			$sourceFile->originalStatements,
			$sourceFile->tokens,
		);
	}

	/**
	 * @param array<array-key, AttributeGroup> $attributeGroups
	 * @return list<Attribute>
	 */
	public function getAttributes(array $attributeGroups): array
	{
		$attributes = [];
		foreach ($attributeGroups as $group) {
			array_push($attributes, ...$group->attrs);
		}

		return $attributes;
	}

	public function getAttributeClassName(Attribute|Expr\New_ $attribute): string
	{
		$nameNode = $attribute instanceof Attribute ? $attribute->name : $attribute->class;
		if (!$nameNode instanceof Name) {
			return '';
		}

		$resolvedName = $nameNode->getAttribute('resolvedName');
		if ($resolvedName instanceof Name) {
			return $resolvedName->toString();
		}

		return $nameNode->toString();
	}

	/**
	 * @param class-string $scopeClassName
	 * @return array<int|string, DoctrineAttributeValue>
	 */
	public function getArgumentValues(Attribute|Expr\New_ $attribute, string $scopeClassName): array
	{
		return $this->getArgumentValuesFromArguments($this->getArguments($attribute), $scopeClassName);
	}

	/**
	 * @param list<Arg> $arguments
	 * @param class-string $scopeClassName
	 * @return array<int|string, DoctrineAttributeValue>
	 */
	public function getArgumentValuesFromArguments(array $arguments, string $scopeClassName): array
	{
		$values = [];
		foreach ($arguments as $index => $argument) {
			$value = $this->evaluate($argument->value, $scopeClassName);
			if ($argument->name === null) {
				$values[$index] = $value;
			} else {
				$values[$argument->name->toString()] = $value;
			}
		}

		return $values;
	}

	/** @return list<Arg> */
	private function getArguments(Attribute|Expr\New_ $attribute): array
	{
		$arguments = [];
		foreach ($attribute->args as $argument) {
			if (!$argument instanceof Arg) {
				throw new CheckerException(sprintf(
					'Unsupported attribute argument node of type %s.',
					get_debug_type($argument),
				));
			}

			$arguments[] = $argument;
		}

		return $arguments;
	}

	public function hasEmptyParentheses(Attribute $attribute, ParsedSourceFile $sourceFile): bool
	{
		$start = $attribute->getStartFilePos();
		$end = $attribute->getEndFilePos();
		if ($start < 0 || $end < $start) {
			return false;
		}

		$text = substr($sourceFile->source, $start, $end - $start + 1);

		return preg_match('/\(\s*\)$/', $text) === 1;
	}

	public function removeEmptyParentheses(Attribute $attribute, ClassLike|ClassMethod|Property $owner): bool
	{
		foreach ($owner->attrGroups as $group) {
			foreach ($group->attrs as $index => $candidate) {
				if ($candidate !== $attribute) {
					continue;
				}

				$group->attrs[$index] = new Attribute($attribute->name);

				return true;
			}
		}

		return false;
	}

	/** @param array<string, DoctrineAttributeWritableValue> $arguments */
	public function createDoctrineAttribute(ClassLike|Property $owner, string $shortName, array $arguments): Attribute
	{
		$attributeName = new Name\FullyQualified('Doctrine\\ORM\\Mapping\\' . $shortName);
		foreach ($this->getAttributes($owner->attrGroups) as $existingAttribute) {
			if (!str_starts_with($this->getAttributeClassName($existingAttribute), 'Doctrine\\ORM\\Mapping\\')) {
				continue;
			}

			$existingName = $existingAttribute->name->toString();
			if ($existingAttribute->name instanceof Name\FullyQualified) {
				$attributeName = new Name\FullyQualified('Doctrine\\ORM\\Mapping\\' . $shortName);
				break;
			}

			$separatorPosition = strrpos($existingName, '\\');
			if ($separatorPosition !== false) {
				$attributeName = new Name(
					substr($existingName, 0, $separatorPosition + 1) . $shortName,
				);
			}

			break;
		}

		$args = [];
		foreach ($arguments as $name => $value) {
			$args[] = new Arg(
				$this->valueToExpression($value),
				false,
				false,
				[],
				new Identifier($name),
			);
		}

		return new Attribute($attributeName, $args);
	}

	/** @param array<string, DoctrineAttributeWritableValue> $arguments */
	public function createDoctrineNewAttribute(Property $owner, string $shortName, array $arguments): Expr\New_
	{
		$attribute = $this->createDoctrineAttribute($owner, $shortName, $arguments);

		return new Expr\New_($attribute->name, $attribute->args);
	}

	public function appendAttribute(ClassLike|Property $owner, Attribute $attribute): void
	{
		$owner->attrGroups[] = new AttributeGroup([$attribute]);
	}

	public function insertAttributeAfter(ClassLike|Property $owner, Attribute $after, Attribute $attribute): void
	{
		foreach ($owner->attrGroups as $groupIndex => $group) {
			foreach ($group->attrs as $candidate) {
				if ($candidate !== $after) {
					continue;
				}

				array_splice(
					$owner->attrGroups,
					$groupIndex + 1,
					0,
					[new AttributeGroup([$attribute])],
				);

				return;
			}
		}

		$this->appendAttribute($owner, $attribute);
	}

	public function appendNestedAttribute(Attribute $container, string $argumentName, Expr\New_ $attribute): bool
	{
		foreach ($container->args as $argument) {
			if ($argument->name?->toString() !== $argumentName) {
				continue;
			}
			if (!$argument->value instanceof Expr\Array_) {
				return false;
			}

			$argument->value->items[] = new Expr\ArrayItem($attribute);

			return true;
		}

		$container->args[] = new Arg(
			new Expr\Array_([new Expr\ArrayItem($attribute)]),
			false,
			false,
			[],
			new Identifier($argumentName),
		);

		return true;
	}

	/** @param array<string, DoctrineAttributeWritableValue> $arguments */
	public function addDoctrineAttribute(ClassLike|Property $owner, string $shortName, array $arguments): Attribute
	{
		$attribute = $this->createDoctrineAttribute($owner, $shortName, $arguments);
		$this->appendAttribute($owner, $attribute);

		return $attribute;
	}

	/**
	 * @param DoctrineAttributeWritableValue $value
	 * @param class-string $scopeClassName
	 */
	public function setArgument(
		Attribute|Expr\New_ $attribute,
		string $name,
		string|int|float|bool|array|null $value,
		string $scopeClassName,
	): bool
	{
		$expression = $this->valueToExpression($value);
		foreach ($this->getArguments($attribute) as $argument) {
			if ($argument->name?->toString() !== $name) {
				continue;
			}
			if ($this->evaluate($argument->value, $scopeClassName) === $value) {
				return false;
			}

			$argument->value = $expression;

			return true;
		}

		$attribute->args[] = new Arg($expression, false, false, [], new Identifier($name));

		return true;
	}

	public function removeArgument(Attribute|Expr\New_ $attribute, string $name): bool
	{
		$originalArguments = $this->getArguments($attribute);
		$arguments = array_values(array_filter(
			$originalArguments,
			static fn (Arg $argument): bool => $argument->name?->toString() !== $name,
		));
		if (count($arguments) === count($originalArguments)) {
			return false;
		}

		$attribute->args = $arguments;

		return true;
	}

	/** @param list<string> $orderedNames */
	public function sortArguments(Attribute|Expr\New_ $attribute, array $orderedNames): bool
	{
		$arguments = $this->getArguments($attribute);
		foreach ($arguments as $argument) {
			if ($argument->name === null) {
				return false;
			}
		}

		$originalArguments = $arguments;
		$argumentOrder = array_flip($orderedNames);
		usort(
			$arguments,
			static function (Arg $a, Arg $b) use ($argumentOrder): int {
				$aName = $a->name?->toString();
				$bName = $b->name?->toString();
				if ($aName === null || $bName === null) {
					return 0;
				}

				return ($argumentOrder[$aName] ?? count($argumentOrder)) <=> ($argumentOrder[$bName] ?? count($argumentOrder));
			},
		);
		$attribute->args = $arguments;

		return $originalArguments !== $arguments;
	}

	/** @param list<string> $orderedNames */
	public function argumentsAreSorted(Attribute|Expr\New_ $attribute, array $orderedNames): bool
	{
		$order = array_flip($orderedNames);
		$previousOrder = -1;
		foreach ($this->getArguments($attribute) as $argument) {
			if ($argument->name === null) {
				return true;
			}

			$argumentOrder = $order[$argument->name->toString()] ?? count($order);
			if ($argumentOrder < $previousOrder) {
				return false;
			}

			$previousOrder = $argumentOrder;
		}

		return true;
	}

	/**
	 * @param list<string> $orderedKeys
	 * @param class-string $scopeClassName
	 */
	public function sortArrayArgument(
		Attribute|Expr\New_ $attribute,
		string $argumentName,
		array $orderedKeys,
		string $scopeClassName,
	): bool
	{
		foreach ($this->getArguments($attribute) as $argument) {
			if ($argument->name?->toString() !== $argumentName || !$argument->value instanceof Expr\Array_) {
				continue;
			}

			$originalItems = $argument->value->items;
			$keyOrder = array_flip($orderedKeys);
			usort(
				$argument->value->items,
				function (Node\ArrayItem $a, Node\ArrayItem $b) use ($keyOrder, $scopeClassName): int {
					$aKey = $a->key === null ? null : $this->evaluate($a->key, $scopeClassName);
					$bKey = $b->key === null ? null : $this->evaluate($b->key, $scopeClassName);
					if (!is_string($aKey) || !is_string($bKey)) {
						return 0;
					}

					return ($keyOrder[$aKey] ?? count($keyOrder)) <=> ($keyOrder[$bKey] ?? count($keyOrder));
				},
			);

			return $originalItems !== $argument->value->items;
		}

		return false;
	}

	/**
	 * @param list<string> $orderedKeys
	 * @param class-string $scopeClassName
	 */
	public function arrayArgumentIsSorted(
		Attribute|Expr\New_ $attribute,
		string $argumentName,
		array $orderedKeys,
		string $scopeClassName,
	): bool
	{
		$order = array_flip($orderedKeys);
		foreach ($this->getArguments($attribute) as $argument) {
			if ($argument->name?->toString() !== $argumentName || !$argument->value instanceof Expr\Array_) {
				continue;
			}

			$previousOrder = -1;
			foreach ($argument->value->items as $item) {
				if ($item->key === null) {
					continue;
				}

				$key = $this->evaluate($item->key, $scopeClassName);
				if (!is_string($key)) {
					continue;
				}

				$itemOrder = $order[$key] ?? count($order);
				if ($itemOrder < $previousOrder) {
					return false;
				}

				$previousOrder = $itemOrder;
			}

			return true;
		}

		return true;
	}

	/**
	 * @param list<string> $orderedValues
	 * @param class-string $scopeClassName
	 */
	public function normalizeStringArrayArgument(
		Attribute|Expr\New_ $attribute,
		string $argumentName,
		array $orderedValues,
		string $scopeClassName,
	): bool
	{
		foreach ($this->getArguments($attribute) as $argument) {
			if ($argument->name?->toString() !== $argumentName || !$argument->value instanceof Expr\Array_) {
				continue;
			}

			$itemsByValue = [];
			foreach ($argument->value->items as $item) {
				$value = $this->evaluate($item->value, $scopeClassName);
				if (!is_string($value)) {
					return false;
				}

				$itemsByValue[$value] ??= $item;
			}

			$values = array_keys($itemsByValue);
			$valueOrder = array_flip($orderedValues);
			usort(
				$values,
				static fn (string $a, string $b): int => ($valueOrder[$a] ?? count($valueOrder))
					<=> ($valueOrder[$b] ?? count($valueOrder))
					?: $a <=> $b,
			);
			$normalizedItems = [];
			foreach ($values as $value) {
				$normalizedItems[] = $itemsByValue[$value];
			}
			if ($normalizedItems === $argument->value->items) {
				return false;
			}

			$argument->value->items = $normalizedItems;

			return true;
		}

		return false;
	}

	public function removeAttribute(ClassLike|ClassMethod|Property $owner, Attribute $attribute): bool
	{
		$groups = [];
		$removed = false;
		foreach ($owner->attrGroups as $group) {
			$attributes = array_values(array_filter(
				$group->attrs,
				static function (Attribute $candidate) use ($attribute, &$removed): bool {
					if ($candidate !== $attribute) {
						return true;
					}

					$removed = true;

					return false;
				},
			));
			if ($attributes === []) {
				continue;
			}

			$group->attrs = $attributes;
			$groups[] = $group;
		}

		if ($removed) {
			$owner->attrGroups = $groups;
		}

		return $removed;
	}

	public function removeNestedArrayItem(Attribute|Expr\New_ $owner, Expr\New_ $target): bool
	{
		foreach ($this->getArguments($owner) as $argument) {
			if (!$argument->value instanceof Expr\Array_) {
				continue;
			}

			if (!$this->removeNestedArrayItemFromArray($argument->value, $target)) {
				continue;
			}

			if ($argument->value->items === []) {
				$this->removeArgument($owner, $argument->name?->toString() ?? '');
			}

			return true;
		}

		return false;
	}

	/**
	 * @param class-string $scopeClassName
	 */
	public function removeArgumentFromArray(
		Attribute|Expr\New_ $attribute,
		string $argumentName,
		string|int $arrayKey,
		string $scopeClassName,
	): bool
	{
		foreach ($this->getArguments($attribute) as $argument) {
			if ($argument->name?->toString() !== $argumentName || !$argument->value instanceof Expr\Array_) {
				continue;
			}

			$items = [];
			$removed = false;
			foreach ($argument->value->items as $item) {
				$key = $item->key === null ? count($items) : $this->evaluateArrayKey($item->key, $scopeClassName);
				if ($key === $arrayKey) {
					$removed = true;
					continue;
				}

				$items[] = $item;
			}
			if (!$removed) {
				return false;
			}

			$argument->value->items = $items;
			if ($items === []) {
				$this->removeArgument($attribute, $argumentName);

				return true;
			}

			return true;
		}

		return false;
	}

	/**
	 * @param class-string $scopeClassName
	 */
	public function setArgumentInArray(
		Attribute|Expr\New_ $attribute,
		string $argumentName,
		string|int $arrayKey,
		string|int|float|bool|null $value,
		string $scopeClassName,
	): bool
	{
		foreach ($this->getArguments($attribute) as $argument) {
			if ($argument->name?->toString() !== $argumentName || !$argument->value instanceof Expr\Array_) {
				continue;
			}

			$items = $argument->value->items;
			foreach ($items as $item) {
				$key = $item->key === null ? null : $this->evaluateArrayKey($item->key, $scopeClassName);
				if ($key !== $arrayKey) {
					continue;
				}

				if ($this->evaluate($item->value, $scopeClassName) === $value) {
					return false;
				}

				$item->value = $this->valueToExpression($value);

				return true;
			}

			$keyExpression = is_int($arrayKey) ? new Node\Scalar\Int_($arrayKey) : new Node\Scalar\String_($arrayKey);
			$items[] = new Expr\ArrayItem($this->valueToExpression($value), $keyExpression);
			$argument->value->items = $items;

			return true;
		}

		$this->setArgument($attribute, $argumentName, [$arrayKey => $value], $scopeClassName);

		return true;
	}

	/**
	 * @param class-string $scopeClassName
	 * @return DoctrineAttributeValue
	 */
	private function evaluate(Expr $expression, string $scopeClassName): string|int|float|bool|array|object|null
	{
		if ($expression instanceof Node\Scalar\String_) {
			return $expression->value;
		}
		if ($expression instanceof Node\Scalar\Int_) {
			return $expression->value;
		}
		if ($expression instanceof Node\Scalar\Float_) {
			return $expression->value;
		}
		if ($expression instanceof Expr\ConstFetch) {
			$value = match (strtolower($expression->name->toString())) {
				'true' => true,
				'false' => false,
				'null' => null,
				default => false,
			};
			if ($value !== false || strtolower($expression->name->toString()) === 'false') {
				return $value;
			}

			$resolvedName = $expression->name->getAttribute('resolvedName');
			$constantName = $resolvedName instanceof Name
				? $resolvedName->toString()
				: $expression->name->toString();

			$value = defined($constantName) ? constant($constantName) : null;
			if (!$this->isDoctrineAttributeValue($value)) {
				throw new CheckerException(sprintf(
					'Constant %s has an unsupported value type %s.',
					$constantName,
					get_debug_type($value),
				));
			}

			return $value;
		}
		if ($expression instanceof Expr\Array_) {
			$values = [];
			foreach ($expression->items as $item) {
				if ($item->key === null) {
					$values[] = $this->evaluate($item->value, $scopeClassName);
				} else {
					$key = $this->evaluateArrayKey($item->key, $scopeClassName);
					$values[$key] = $this->evaluate($item->value, $scopeClassName);
				}
			}
			if (!$this->isDoctrineAttributeValue($values)) {
				throw new CheckerException('Doctrine attribute arrays can be nested at most two levels deep.');
			}

			return $values;
		}
		if ($expression instanceof Expr\ClassConstFetch && $expression->class instanceof Name) {
			$className = $expression->class->getAttribute('resolvedName');
			$className = $className instanceof Name ? $className->toString() : $expression->class->toString();
			if (strtolower($className) === 'self' || strtolower($className) === 'static') {
				$className = $scopeClassName;
			} elseif (strtolower($className) === 'parent') {
				$parentClass = (new ReflectionClass($scopeClassName))->getParentClass();
				if ($parentClass === false) {
					return null;
				}

				$className = $parentClass->name;
			}

			if ($expression->name instanceof Identifier && strtolower($expression->name->toString()) === 'class') {
				return $className;
			}

			if (
				$expression->name instanceof Identifier
				&& (
					class_exists($className)
					|| interface_exists($className)
					|| trait_exists($className)
					|| enum_exists($className)
				)
			) {
				/** @var class-string $className */
				$reflection = new ReflectionClass($className);
				if ($reflection->hasConstant($expression->name->toString())) {
					$value = $reflection->getConstant($expression->name->toString());
					if (!$this->isDoctrineAttributeValue($value)) {
						throw new CheckerException(sprintf(
							'Constant %s::%s has an unsupported value type %s.',
							$className,
							$expression->name->toString(),
							get_debug_type($value),
						));
					}

					return $value;
				}
			}
		}
		if ($expression instanceof Expr\New_) {
			$name = $this->getAttributeClassName($expression);
			if ($name !== '' && class_exists($name)) {
				$args = $this->getArgumentValuesFromArguments($this->getArguments($expression), $scopeClassName);

				/** @var class-string $name */
				return (new ReflectionClass($name))->newInstanceArgs($args);
			}
		}

		return null;
	}

	/**
	 * @phpstan-assert-if-true DoctrineAttributeValue $value
	 */
	private function isDoctrineAttributeValue(mixed $value): bool
	{
		if ($this->isScalarOrObject($value)) {
			return true;
		}
		if (!is_array($value)) {
			return false;
		}

		foreach ($value as $item) {
			if ($this->isScalarOrObject($item)) {
				continue;
			}
			if (!is_array($item)) {
				return false;
			}

			foreach ($item as $nestedItem) {
				if (!$this->isScalarOrObject($nestedItem)) {
					return false;
				}
			}
		}

		return true;
	}

	private function isScalarOrObject(mixed $value): bool
	{
		return $value === null || is_scalar($value) || is_object($value);
	}

	/**
	 * @param class-string $scopeClassName
	 */
	private function evaluateArrayKey(Expr $expression, string $scopeClassName): int|string
	{
		$value = $this->evaluate($expression, $scopeClassName);
		if (is_int($value) || is_string($value)) {
			return $value;
		}
		if (is_float($value) || is_bool($value)) {
			return (int) $value;
		}
		if ($value === null) {
			return '';
		}

		throw new CheckerException(sprintf(
			'Cannot use a value of type %s as an attribute array key.',
			get_debug_type($value),
		));
	}

	private function removeNestedArrayItemFromArray(Expr\Array_ $array, Expr\New_ $target): bool
	{
		foreach ($array->items as $index => $item) {
			if ($item->value === $target) {
				unset($array->items[$index]);
				$array->items = array_values($array->items);

				return true;
			}

			if ($item->value instanceof Expr\Array_ && $this->removeNestedArrayItemFromArray($item->value, $target)) {
				return true;
			}
		}

		return false;
	}

	/** @param DoctrineAttributeWritableValue $value */
	private function valueToExpression(string|int|float|bool|array|null $value): Expr
	{
		if (is_string($value)) {
			return new Node\Scalar\String_($value);
		}
		if (is_int($value)) {
			return new Node\Scalar\Int_($value);
		}
		if (is_float($value)) {
			return new Node\Scalar\Float_($value);
		}
		if (is_bool($value)) {
			return new Expr\ConstFetch(new Name($value ? 'true' : 'false'));
		}
		if ($value === null) {
			return new Expr\ConstFetch(new Name('null'));
		}
		$items = [];
		foreach ($value as $key => $itemValue) {
			$keyExpression = is_int($key) ? null : new Node\Scalar\String_($key);
			$items[] = new Expr\ArrayItem($this->valueToExpression($itemValue), $keyExpression);
		}

		return new Expr\Array_($items);
	}

}
