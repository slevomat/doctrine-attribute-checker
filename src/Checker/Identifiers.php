<?php declare(strict_types = 1);

// spell-check-ignore: backticks

namespace Slevomat\DoctrineAttributeChecker\Checker;

use Slevomat\DoctrineAttributeChecker\Configuration\Configuration;
use function is_string;
use function sprintf;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function substr;

final class Identifiers
{

	public function __construct(
		private Configuration $configuration,
	)
	{
	}

	public static function unquote(string $identifier): string
	{
		if (self::isQuoted($identifier)) {
			return substr($identifier, 1, -1);
		}

		return $identifier;
	}

	public function isReserved(string $identifier): bool
	{
		return MySqlReservedWords::isReserved($identifier)
			|| $this->configuration->isAdditionalReservedWord($identifier);
	}

	public function escape(string $identifier): string
	{
		$plainName = self::unquote($identifier);

		return $this->isReserved($plainName) ? sprintf('`%s`', $plainName) : $plainName;
	}

	public function checkName(
		DoctrineAttributeContext $context,
		ViolationReporter $reporter,
		string $argumentName,
		?string $defaultName,
	): void
	{
		$value = $context->arguments[$argumentName] ?? null;
		if ($value === null && $defaultName !== null && $this->isReserved($defaultName)) {
			$reporter->fixViolation(
				$context,
				'identifier.reserved',
				sprintf('The reserved MySQL identifier %s must be escaped with backticks.', $defaultName),
				static fn (): bool => $context->setArgument($argumentName, sprintf('`%s`', $defaultName)),
			);

			return;
		}

		if (!is_string($value)) {
			return;
		}

		$this->checkQuoting($context, $reporter, $argumentName);
		$plainName = self::unquote($value);
		if (
			$defaultName !== null
			&& $plainName === $defaultName
			&& !$this->isReserved($plainName)
		) {
			$reporter->fixViolation(
				$context,
				'identifier.default-name',
				sprintf('%s.%s matches its inferred name and is redundant.', $context->getShortAttributeName(), $argumentName),
				static fn (): bool => $context->removeArgument($argumentName),
			);
		}
	}

	public function checkQuoting(
		DoctrineAttributeContext $context,
		ViolationReporter $reporter,
		string $argumentName,
	): void
	{
		$value = $context->arguments[$argumentName] ?? null;
		if (!is_string($value)) {
			return;
		}

		$quoted = self::isQuoted($value);
		$plainName = self::unquote($value);
		$mustBeQuoted = $this->isReserved($plainName);
		if ($quoted === $mustBeQuoted) {
			return;
		}

		$expected = $mustBeQuoted ? sprintf('`%s`', $plainName) : $plainName;
		$reporter->fixViolation(
			$context,
			'identifier.quoting',
			sprintf('Identifier %s must be %s.', $plainName, $mustBeQuoted ? 'escaped with backticks' : 'unquoted'),
			static fn (): bool => $context->setArgument($argumentName, $expected),
		);
	}

	private static function isQuoted(string $identifier): bool
	{
		return str_starts_with($identifier, '`') && str_ends_with($identifier, '`') && strlen($identifier) > 1;
	}

}
