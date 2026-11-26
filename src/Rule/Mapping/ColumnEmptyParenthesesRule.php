<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Rule\Mapping;

use Override;
use PhpParser\Node\Attribute;
use Slevomat\DoctrineAttributeChecker\Checker\DoctrineAttributeContext;
use Slevomat\DoctrineAttributeChecker\Checker\ViolationReporter;
use Slevomat\DoctrineAttributeChecker\Configuration\Configuration;
use Slevomat\DoctrineAttributeChecker\Rule\AttributeRule;
use Slevomat\DoctrineAttributeChecker\Source\AttributeSourceEditor;

final class ColumnEmptyParenthesesRule implements AttributeRule
{

	public function __construct(
		private AttributeSourceEditor $sourceEditor,
	)
	{
	}

	#[Override]
	public static function getIdentifiers(Configuration $configuration): array
	{
		return ['column.empty-parentheses'];
	}

	#[Override]
	public function getAttributeNames(): array
	{
		return ['Column'];
	}

	#[Override]
	public function process(DoctrineAttributeContext $context, ViolationReporter $reporter): void
	{
		$attribute = $context->attribute;
		if (
			!$attribute instanceof Attribute
			|| $attribute->args !== []
			|| $context->sourceFile === null
			|| !$this->sourceEditor->hasEmptyParentheses($attribute, $context->sourceFile)
		) {
			return;
		}

		$owner = $context->owner;
		$reporter->fixViolation(
			$context,
			'column.empty-parentheses',
			'Column attributes without arguments should not use empty parentheses.',
			fn (): bool => $this->sourceEditor->removeEmptyParentheses($attribute, $owner),
		);
	}

}
