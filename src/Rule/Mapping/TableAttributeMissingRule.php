<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Rule\Mapping;

use Override;
use Slevomat\DoctrineAttributeChecker\Checker\ClassContext;
use Slevomat\DoctrineAttributeChecker\Checker\DoctrineAttributeContext;
use Slevomat\DoctrineAttributeChecker\Checker\Identifiers;
use Slevomat\DoctrineAttributeChecker\Checker\ViolationReporter;
use Slevomat\DoctrineAttributeChecker\Configuration\Configuration;
use Slevomat\DoctrineAttributeChecker\Rule\ClassRule;
use Slevomat\DoctrineAttributeChecker\Source\AttributeSourceEditor;

final class TableAttributeMissingRule implements ClassRule
{

	public function __construct(
		private AttributeSourceEditor $sourceEditor,
		private Identifiers $identifiers,
	)
	{
	}

	#[Override]
	public static function getIdentifiers(Configuration $configuration): array
	{
		return ['table.attribute-missing'];
	}

	#[Override]
	public function process(ClassContext $context, ViolationReporter $reporter): void
	{
		$classMetadata = $context->classMetadata;
		if (
			$classMetadata->isMappedSuperclass
			|| $classMetadata->isEmbeddedClass
			|| $classMetadata->parentClasses !== []
			|| $context->reflectionClass->isAbstract()
			|| $this->hasTableAttribute($context)
		) {
			return;
		}

		$tableName = $this->identifiers->escape($classMetadata->getTableName());
		if ($reporter->canFix('table.attribute-missing')) {
			$this->sourceEditor->addDoctrineAttribute($context->classNode, 'Table', ['name' => $tableName]);
			$reporter->markChanged();

			return;
		}

		$reporter->addViolationAt(
			$context->file,
			$context->classNode->getStartLine(),
			$context->className,
			'table.attribute-missing',
			'Concrete entities must declare a Table attribute with an explicit name.',
			true,
		);
	}

	private function hasTableAttribute(ClassContext $context): bool
	{
		foreach ($this->sourceEditor->getAttributes($context->classNode->attrGroups) as $attribute) {
			if ($this->sourceEditor->getAttributeClassName($attribute) === DoctrineAttributeContext::MAPPING_NAMESPACE . 'Table') {
				return true;
			}
		}

		return false;
	}

}
