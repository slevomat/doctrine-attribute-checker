<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Configuration;

use Nette\Utils\FileSystem;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slevomat\DoctrineAttributeChecker\CheckerException;
use Slevomat\DoctrineAttributeChecker\Rules;
use function realpath;
use function sprintf;
use function sys_get_temp_dir;
use function uniqid;

class ConfigurationLoaderTest extends TestCase
{

	private string $directory;

	#[Override]
	protected function setUp(): void
	{
		$this->directory = sprintf('%s/%s', sys_get_temp_dir(), uniqid('doctrine-attribute-configuration-', true));
		FileSystem::createDir($this->directory);
		$canonicalDirectory = realpath($this->directory);
		self::assertNotFalse($canonicalDirectory);
		$this->directory = $canonicalDirectory;
	}

	#[Override]
	protected function tearDown(): void
	{
		FileSystem::delete($this->directory);
	}

	public function testDefaults(): void
	{
		$configuration = (new ConfigurationLoader())->load(ConfigurationLoader::getDefaultsFile());

		self::assertSame([], $configuration->disabledRules);
		self::assertNull($configuration->baselineFile);
		self::assertNull($configuration->entityManagerLoader);
		self::assertContains('Index', $configuration->forbiddenAttributes);
		self::assertSame([], $configuration->allowedLifecycleCallbacks);
		self::assertFalse($configuration->allowCustomRepositoryClass);
		self::assertSame(['unsigned', 'fixed', 'collation'], $configuration->columnOptionOrder);
		self::assertSame(['nullable', 'default', 'comment'], $configuration->forbiddenColumnOptions);
		self::assertSame(['persist', 'remove', 'refresh', 'detach', 'merge', 'all'], $configuration->cascadeOrder);
		self::assertSame([], $configuration->ignoreErrors);
		self::assertTrue($configuration->isRuleEnabled('database.column-type'));
	}

	public function testProjectConfigurationIsMergedWithDefaultsAndIncludes(): void
	{
		FileSystem::write(sprintf('%s/build/baseline.neon', $this->directory), <<<'NEON'
			ignoreErrors:
				-
					rule: database.column-nullability
					target: App\Entity::$name
					path: app/Entity.php
					message: Baseline message.
			NEON);
		FileSystem::write(sprintf('%s/doctrine-attributes.neon', $this->directory), <<<'NEON'
			includes:
				- build/baseline.neon

			rules:
				disabled:
					- database.*

			baseline: build/baseline.neon
			entityManagerLoader: build/entity-manager.php

			column:
				optionOrder: [collation, unsigned]

			attributes:
				allowedLifecycleCallbacks:
					App\Product:
						onPreRemove: [PreRemove]

			ignoreErrors:
				-
					rule: table.name
					target: App\Entity
					path: app/Entity.php
					message: Project message.
			NEON);

		$configuration = (new ConfigurationLoader())->load(sprintf('%s/doctrine-attributes.neon', $this->directory));

		self::assertSame($this->directory, $configuration->rootDirectory);
		self::assertSame(sprintf('%s/build/baseline.neon', $this->directory), $configuration->baselineFile);
		self::assertSame(sprintf('%s/build/entity-manager.php', $this->directory), $configuration->entityManagerLoader);
		self::assertSame(['database.*'], $configuration->disabledRules);
		self::assertFalse($configuration->isRuleEnabled('database.column-type'));
		self::assertTrue($configuration->isRuleEnabled('column.type'));
		self::assertSame(['collation', 'unsigned'], $configuration->columnOptionOrder);
		self::assertSame(['nullable', 'default', 'comment'], $configuration->forbiddenColumnOptions);
		self::assertTrue($configuration->isLifecycleCallbackAllowed('App\\Product', null, 'HasLifecycleCallbacks'));
		self::assertTrue($configuration->isLifecycleCallbackAllowed('App\\Product', 'onPreRemove', 'PreRemove'));
		self::assertFalse($configuration->isLifecycleCallbackAllowed('App\\Product', 'onPreRemove', 'PostLoad'));
		self::assertFalse($configuration->isLifecycleCallbackAllowed('App\\Other', null, 'HasLifecycleCallbacks'));
		self::assertSame(['Baseline message.', 'Project message.'], [
			$configuration->ignoreErrors[0]['message'],
			$configuration->ignoreErrors[1]['message'],
		]);
	}

	public function testDefaultConfigurationFileIsOptional(): void
	{
		$configuration = (new ConfigurationLoader())->load();

		self::assertSame([], $configuration->disabledRules);
	}

	#[DataProvider('dataInvalidConfiguration')]
	public function testInvalidConfiguration(string $content, string $expectedMessage): void
	{
		$file = sprintf('%s/doctrine-attributes.neon', $this->directory);
		FileSystem::write($file, $content);

		$this->expectException(CheckerException::class);
		$this->expectExceptionMessage($expectedMessage);

		(new ConfigurationLoader())->load($file);
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function dataInvalidConfiguration(): array
	{
		return [
			'unknown rule' => [
				"rules:\n\tdisabled: [column.typo]\n",
				'Disabled rule column.typo does not match any known rule.',
			],
			'unknown rule prefix' => [
				"rules:\n\tdisabled: [typo.*]\n",
				'Disabled rule typo.* does not match any known rule.',
			],
			'unknown key' => [
				"column:\n\tunknown: true\n",
				'Unexpected item',
			],
			'invalid type' => [
				"attributes:\n\tallowCustomRepositoryClass: yes please\n",
				"allowCustomRepositoryClass' expects to be bool",
			],
			'incomplete ignored error' => [
				"ignoreErrors:\n\t-\n\t\trule: table.name\n",
				"target' is missing",
			],
			'missing include' => [
				"includes:\n\t- missing.neon\n",
				'Configuration file missing.neon included from',
			],
			'circular include' => [
				"includes:\n\t- doctrine-attributes.neon\n",
				'Circular include of configuration file',
			],
		];
	}

	public function testMissingConfigurationFile(): void
	{
		$this->expectException(CheckerException::class);
		$this->expectExceptionMessage('does not exist');

		(new ConfigurationLoader())->load(sprintf('%s/missing.neon', $this->directory));
	}

	public function testConfiguredForbiddenArgumentsAreKnownRules(): void
	{
		$file = sprintf('%s/doctrine-attributes.neon', $this->directory);
		FileSystem::write($file, "table:\n\tforbiddenArguments: [schema]\nrules:\n\tdisabled: [table.schema]\n");

		$configuration = (new ConfigurationLoader())->load($file);

		self::assertContains('table.schema', Rules::getAll($configuration));
		self::assertFalse($configuration->isRuleEnabled('table.schema'));
	}

}
