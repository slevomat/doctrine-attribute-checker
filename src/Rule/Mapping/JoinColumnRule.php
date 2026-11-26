<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Rule\Mapping;

use Override;
use Slevomat\DoctrineAttributeChecker\Checker\DoctrineAttributeContext;
use Slevomat\DoctrineAttributeChecker\Checker\DoctrineMapping;
use Slevomat\DoctrineAttributeChecker\Checker\Identifiers;
use Slevomat\DoctrineAttributeChecker\Checker\ViolationReporter;
use Slevomat\DoctrineAttributeChecker\Configuration\Configuration;
use Slevomat\DoctrineAttributeChecker\Rule\AttributeRule;
use function array_key_exists;
use function is_string;
use function sprintf;

final class JoinColumnRule implements AttributeRule
{

	public function __construct(
		private DoctrineMapping $mapping,
		private Identifiers $identifiers,
	)
	{
	}

	#[Override]
	public static function getIdentifiers(Configuration $configuration): array
	{
		return [
			'identifier.default-name',
			'identifier.quoting',
			'identifier.reserved',
			'join-column.name',
			'join-column.nullable',
			'join-column.on-delete',
			'join-column.referenced-column-name',
			'join-column.unique',
		];
	}

	#[Override]
	public function getAttributeNames(): array
	{
		return ['JoinColumn', 'InverseJoinColumn'];
	}

	#[Override]
	public function process(DoctrineAttributeContext $context, ViolationReporter $reporter): void
	{
		if (array_key_exists('onDelete', $context->arguments)) {
			$reporter->fixViolation(
				$context,
				'join-column.on-delete',
				sprintf('%s.onDelete is not maintained in Doctrine mapping.', $context->getShortAttributeName()),
				static fn (): bool => $context->removeArgument('onDelete'),
			);
		}

		if ($context->databaseJoinTable) {
			$this->checkJoinTableColumnName($context, $reporter);
		} else {
			$this->identifiers->checkName(
				$context,
				$reporter,
				'name',
				$context->defaultName ?? $this->mapping->getDefaultJoinColumnName($context),
			);
		}

		if (($context->arguments['referencedColumnName'] ?? null) === 'id') {
			$reporter->fixViolation(
				$context,
				'join-column.referenced-column-name',
				'JoinColumn.referencedColumnName is inferred as id.',
				static fn (): bool => $context->removeArgument('referencedColumnName'),
			);
		} elseif (array_key_exists('referencedColumnName', $context->arguments)) {
			$this->identifiers->checkQuoting($context, $reporter, 'referencedColumnName');
		}

		if (array_key_exists('nullable', $context->arguments)) {
			$propertyType = $context->property?->getType();
			if (
				$context->arguments['nullable'] === false
				&& (
					$propertyType === null
					|| $propertyType->allowsNull()
				)
			) {
				$reporter->addViolation(
					$context,
					'join-column.nullable',
					'JoinColumn.nullable=false conflicts with the nullable PHP property type.',
				);
			} else {
				$reporter->fixViolation(
					$context,
					'join-column.nullable',
					'JoinColumn.nullable is inferred from the PHP property type.',
					static fn (): bool => $context->removeArgument('nullable'),
				);
			}
		}

		if (($context->arguments['unique'] ?? null) !== false) {
			return;
		}

		$reporter->fixViolation(
			$context,
			'join-column.unique',
			'JoinColumn.unique=false is the default and should be omitted.',
			static fn (): bool => $context->removeArgument('unique'),
		);
	}

	private function checkJoinTableColumnName(DoctrineAttributeContext $context, ViolationReporter $reporter): void
	{
		$name = $context->arguments['name'] ?? null;
		if (!is_string($name)) {
			$defaultName = $context->databaseJoinColumn->name
				?? $context->defaultName
				?? $this->mapping->getDefaultJoinColumnName($context);
			if ($defaultName === null) {
				$reporter->addViolation(
					$context,
					'join-column.name',
					'ManyToMany join columns must specify a name explicitly.',
				);
			} else {
				$reporter->fixViolation(
					$context,
					'join-column.name',
					'ManyToMany join columns must specify a name explicitly.',
					static fn (): bool => $context->setArgument('name', $defaultName),
				);
			}
		}

		$this->identifiers->checkQuoting($context, $reporter, 'name');
	}

}
