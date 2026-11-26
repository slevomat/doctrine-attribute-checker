<?php declare(strict_types = 1);

// spell-check-ignore: idx insertable manytomany manytoone onetomany onetoone updatable

namespace Slevomat\DoctrineAttributeChecker\Checker;

use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Types\TypeRegistry;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use LogicException;
use Nette\Utils\FileSystem;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Slevomat\DoctrineAttributeChecker\Checker\Data\FixtureAssociationEntity;
use Slevomat\DoctrineAttributeChecker\Checker\Data\FixtureBasicEntity;
use Slevomat\DoctrineAttributeChecker\Checker\Data\FixtureBooleanEntity;
use Slevomat\DoctrineAttributeChecker\Checker\Data\FixtureCompositeAssociationEntity;
use Slevomat\DoctrineAttributeChecker\Checker\Data\FixtureReservedEntity;
use Slevomat\DoctrineAttributeChecker\Checker\Data\FixtureTypedAssociationEntity;
use Slevomat\DoctrineAttributeChecker\Checker\Data\FixtureTypedOneToOneEntity;
use Slevomat\DoctrineAttributeChecker\Configuration\Configuration as CheckerConfiguration;
use Slevomat\DoctrineAttributeChecker\Configuration\ConfigurationLoader;
use Slevomat\DoctrineAttributeChecker\Database\DatabaseColumn;
use Slevomat\DoctrineAttributeChecker\Database\DatabaseForeignKey;
use Slevomat\DoctrineAttributeChecker\Database\DatabaseSchemaSnapshot;
use Slevomat\DoctrineAttributeChecker\Source\AttributeSourceEditor;
use Slevomat\DoctrineAttributeChecker\Violation;
use function array_map;
use function array_unique;
use function array_values;
use function explode;
use function in_array;
use function sort;
use function sprintf;
use function str_contains;
use function str_replace;
use function strlen;
use function strpos;
use function strrpos;
use function substr;
use function substr_count;
use function sys_get_temp_dir;
use function uniqid;

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols

/**
 * @phpstan-type DatabaseCaseSettings array{
 *     className: class-string,
 *     rule: string,
 *     fixable: bool,
 *     missingTables: list<string>,
 *     missingColumns: list<string>,
 *     overrides: array<string, DatabaseColumn>,
 *     uniqueColumns: list<string>,
 *     primaryKeyColumns: list<string>,
 *     foreignKeys: array<string, list<DatabaseForeignKey>>,
 *     sourceFragment: ?string,
 *     invalidFragment: ?string,
 *     fixedFragment: ?string
 * }
 */
class DoctrineAttributeCheckerTest extends TestCase
{

	/** @var array<class-string, ClassMetadata<object>> */
	private static array $metadataByClassName;

	private static Configuration $configuration;

	private Connection $connection;

	#[Override]
	protected function setUp(): void
	{
		$this->connection = $this->createConnectionStub();
	}

	#[Override]
	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		self::$configuration = FixtureMetadata::createOrmConfiguration();
		self::$metadataByClassName = FixtureMetadata::load(self::$configuration);
	}

	public function testValidEntityHasNoViolations(): void
	{
		$source = FileSystem::read(__DIR__ . '/data/DoctrineAttributeCheckerEntities.php');

		$result = $this->createChecker(FixtureBasicEntity::class)
			->inspect($source, __DIR__ . '/data/DoctrineAttributeCheckerEntities.php', false);

		self::assertSame([], $result['violations']);
		self::assertFalse($result['changed']);
		self::assertSame($source, $result['contents']);
	}

	/**
	 * @param class-string $className
	 * @param list<string> $expectedRules
	 */
	#[DataProvider('dataSourceRules')]
	public function testSourceRules(
		string $className,
		string $scope,
		string $sourceFragment,
		string $invalidFragment,
		?string $fixedFragment,
		array $expectedRules,
	): void
	{
		$path = __DIR__ . '/data/DoctrineAttributeCheckerEntities.php';
		$source = FileSystem::read($path);
		$invalidSource = $this->replaceInScope(
			$source,
			$className,
			$scope,
			$sourceFragment,
			$invalidFragment,
		);
		$result = $this->createChecker($className)
			->inspect($invalidSource, $path, false);

		$actualRules = array_values(array_unique(array_map(
			static fn (Violation $violation): string => $violation->rule,
			$result['violations'],
		)));
		sort($actualRules);
		sort($expectedRules);
		self::assertSame($expectedRules, $actualRules);
		self::assertFalse($result['changed']);
		self::assertSame($invalidSource, $result['contents']);

		$expectedFixable = $fixedFragment !== null;
		foreach ($result['violations'] as $violation) {
			self::assertSame($expectedFixable, $violation->fixable, $violation->rule);
		}

		$fixedResult = $this->createChecker($className)
			->inspect($invalidSource, $path, true);
		if ($fixedFragment === null) {
			self::assertFalse($fixedResult['changed']);
			self::assertSame($invalidSource, $fixedResult['contents']);

			return;
		}

		$expectedSource = $this->replaceInScope(
			$source,
			$className,
			$scope,
			$sourceFragment,
			$fixedFragment,
		);
		self::assertTrue($fixedResult['changed']);
		self::assertSame($expectedSource, $fixedResult['contents']);
		self::assertSame([], $fixedResult['violations']);
	}

	/**
	 * @param class-string $className
	 * @param list<string> $expectedRules
	 */
	#[DataProvider('dataConfiguredRules')]
	public function testConfiguredRules(
		string $configuration,
		string $className,
		string $scope,
		string $sourceFragment,
		string $invalidFragment,
		?string $fixedFragment,
		array $expectedRules,
	): void
	{
		$configurationFile = sprintf('%s/%s.neon', sys_get_temp_dir(), uniqid('doctrine-attribute-checker-', true));
		FileSystem::write($configurationFile, $configuration);
		try {
			$checkerConfiguration = self::loadCheckerConfiguration($configurationFile);
		} finally {
			FileSystem::delete($configurationFile);
		}

		$path = __DIR__ . '/data/DoctrineAttributeCheckerEntities.php';
		$source = FileSystem::read($path);
		$invalidSource = $this->replaceInScope($source, $className, $scope, $sourceFragment, $invalidFragment);

		$result = $this->createChecker($className, null, $checkerConfiguration)
			->inspect($invalidSource, $path, false);
		$actualRules = array_values(array_unique(array_map(
			static fn (Violation $violation): string => $violation->rule,
			$result['violations'],
		)));
		sort($actualRules);
		self::assertSame($expectedRules, $actualRules);

		$fixedResult = $this->createChecker($className, null, $checkerConfiguration)
			->inspect($invalidSource, $path, true);
		$expectedSource = $fixedFragment === null
			? $invalidSource
			: $this->replaceInScope($source, $className, $scope, $sourceFragment, $fixedFragment);
		self::assertSame($expectedSource, $fixedResult['contents']);
	}

	/**
	 * @return array<string, array{string, class-string, string, string, string, ?string, list<string>}>
	 */
	public static function dataConfiguredRules(): array
	{
		return [
			'disabled fixable rule is neither reported nor fixed' => [
				"rules:\n\tdisabled: [entity.repository-class]\n",
				FixtureBasicEntity::class,
				'class',
				'#[ORM\Entity]',
				'#[ORM\Entity(repositoryClass: \Doctrine\ORM\EntityRepository::class)]',
				null,
				[],
			],
			'disabled rule prefix' => [
				"rules:\n\tdisabled: [column.options.*]\n",
				FixtureBasicEntity::class,
				'body',
				'#[ORM\Column(length: 60)]',
				"#[ORM\Column(length: 60, options: ['comment' => 'unused'])]",
				null,
				[],
			],
			'other rules stay enabled' => [
				"rules:\n\tdisabled: [entity.repository-class]\n",
				FixtureBasicEntity::class,
				'body',
				'#[ORM\Column(length: 60)]',
				"#[ORM\Column(length: 60, options: ['comment' => 'unused'])]",
				'#[ORM\Column(length: 60)]',
				['column.options.comment'],
			],
			'forbidden column options are configurable' => [
				"column:\n\tforbiddenOptions: [default]\n",
				FixtureBasicEntity::class,
				'body',
				'#[ORM\Column(length: 60)]',
				"#[ORM\Column(length: 60, options: ['comment' => 'kept'])]",
				null,
				[],
			],
			'forbidden attributes are configurable' => [
				"attributes:\n\tforbidden: []\n",
				FixtureBasicEntity::class,
				'class',
				'#[ORM\Entity]',
				"#[ORM\Entity]\n#[ORM\Index(name: 'attribute_check_idx', fields: ['name'])]",
				null,
				[],
			],
			'allowed lifecycle callback' => [
				sprintf(
					"attributes:\n\tallowedLifecycleCallbacks:\n\t\t%s:\n\t\t\tcallback: [PrePersist]\n",
					FixtureBasicEntity::class,
				),
				FixtureBasicEntity::class,
				'body',
				"\tpublic function callback(): void",
				"\t#[ORM\\PrePersist]\n\tpublic function callback(): void",
				null,
				[],
			],
			'lifecycle callback event not allowed for method' => [
				sprintf(
					"attributes:\n\tallowedLifecycleCallbacks:\n\t\t%s:\n\t\t\tcallback: [PreRemove]\n",
					FixtureBasicEntity::class,
				),
				FixtureBasicEntity::class,
				'body',
				"\tpublic function callback(): void",
				"\t#[ORM\\PrePersist]\n\tpublic function callback(): void",
				null,
				['forbidden.lifecycle-callback'],
			],
			'custom repository class allowed' => [
				"attributes:\n\tallowCustomRepositoryClass: true\n",
				FixtureBasicEntity::class,
				'class',
				'#[ORM\Entity]',
				'#[ORM\Entity(repositoryClass: \stdClass::class)]',
				null,
				[],
			],
			'additional reserved word must be quoted' => [
				"identifiers:\n\tadditionalReservedWords: [attribute_check_basic]\n",
				FixtureBasicEntity::class,
				'class',
				"#[ORM\Table(name: 'attribute_check_basic')]",
				"#[ORM\Table(name: 'attribute_check_basic')]",
				"#[ORM\Table(name: '`attribute_check_basic`')]",
				['identifier.quoting'],
			],
		];
	}

	#[DataProvider('dataDatabaseRules')]
	public function testDatabaseRules(string $caseName): void
	{
		$case = $this->databaseCaseSettings($caseName);
		$schema = $this->createDatabaseSchema(
			$case['overrides'],
			$case['missingTables'],
			$case['missingColumns'],
			$case['uniqueColumns'],
			$case['primaryKeyColumns'],
			$case['foreignKeys'],
		);
		$path = __DIR__ . '/data/DoctrineAttributeCheckerEntities.php';
		$source = FileSystem::read($path);
		if ($case['sourceFragment'] !== null) {
			self::assertNotNull($case['invalidFragment']);
			$source = $this->replaceInScope(
				$source,
				$case['className'],
				'body',
				$case['sourceFragment'],
				$case['invalidFragment'],
			);
		}

		$result = $this->createChecker($case['className'], $schema)
			->inspect($source, $path, false);
		$matchingViolations = [];
		foreach ($result['violations'] as $violation) {
			if ($violation->rule === $case['rule']) {
				$matchingViolations[] = $violation;
			}
		}

		self::assertNotSame([], $matchingViolations, $case['rule']);
		foreach ($matchingViolations as $violation) {
			self::assertSame($case['fixable'], $violation->fixable, $case['rule']);
		}

		$fixedResult = $this->createChecker($case['className'], $schema)
			->inspect($source, $path, true);
		if (!$case['fixable']) {
			self::assertFalse($fixedResult['changed']);
			self::assertSame($source, $fixedResult['contents']);

			return;
		}

		self::assertNotNull($case['sourceFragment']);
		self::assertNotNull($case['fixedFragment']);
		$expectedSource = $this->replaceInScope(
			FileSystem::read($path),
			$case['className'],
			'body',
			$case['sourceFragment'],
			$case['fixedFragment'],
		);
		self::assertTrue($fixedResult['changed']);
		self::assertSame($expectedSource, $fixedResult['contents']);
		self::assertSame([], $fixedResult['violations']);
	}

	public function testBooleanColumnDoesNotRequireUnsignedOption(): void
	{
		$schema = $this->createDatabaseSchema(includedTables: ['attribute_check_boolean']);
		$path = __DIR__ . '/data/DoctrineAttributeCheckerEntities.php';
		$source = FileSystem::read($path);
		$result = $this->createChecker(FixtureBooleanEntity::class, $schema)
			->inspect($source, $path, false);
		$rules = array_map(
			static fn (Violation $violation): string => $violation->rule,
			$result['violations'],
		);
		self::assertNotContains('database.column-unsigned', $rules);

		$invalidSource = $this->replaceInScope(
			$source,
			FixtureBooleanEntity::class,
			'body',
			"\t#[ORM\Column]\n\tpublic bool \$enabled;",
			"\t#[ORM\Column(options: ['unsigned' => true])]\n\tpublic bool \$enabled;",
		);
		$invalidResult = $this->createChecker(FixtureBooleanEntity::class, $schema)
			->inspect($invalidSource, $path, false);
		$unsignedViolations = [];
		foreach ($invalidResult['violations'] as $violation) {
			if ($violation->rule === 'database.column-unsigned') {
				$unsignedViolations[] = $violation;
			}
		}
		self::assertCount(1, $unsignedViolations);
		self::assertTrue($unsignedViolations[0]->fixable);

		$fixedResult = $this->createChecker(FixtureBooleanEntity::class, $schema)
			->inspect($invalidSource, $path, true);
		self::assertTrue($fixedResult['changed']);
		self::assertSame($source, $fixedResult['contents']);
		self::assertSame([], $fixedResult['violations']);
	}

	public function testSecondaryDatabaseTablesAndColumnsAreMerged(): void
	{
		$primarySchema = $this->createDatabaseSchema(includedTables: ['attribute_check_association']);
		$secondarySchema = $this->createDatabaseSchema(
			overrides: [
				'attribute_check_basic.name' => new DatabaseColumn('int', 'int', null, 10, 0, false, null),
			],
			includedTables: ['attribute_check_basic'],
		);
		$schema = $primarySchema->withSecondary($secondarySchema);
		$path = __DIR__ . '/data/DoctrineAttributeCheckerEntities.php';
		$result = $this->createChecker(FixtureBasicEntity::class, $schema)
			->inspect(FileSystem::read($path), $path, false);

		$rules = array_map(
			static fn (Violation $violation): string => $violation->rule,
			$result['violations'],
		);

		self::assertNotContains('database.table-missing', $rules);
		self::assertContains('database.column-type', $rules);
	}

	public function testPrimaryDatabaseTableTakesPrecedenceOverSecondary(): void
	{
		$primarySchema = $this->createDatabaseSchema(includedTables: ['attribute_check_basic']);
		$secondarySchema = $this->createDatabaseSchema(
			overrides: [
				'attribute_check_basic.name' => new DatabaseColumn('int', 'int', null, 10, 0, false, null),
			],
			includedTables: ['attribute_check_basic'],
		);
		$schema = $primarySchema->withSecondary($secondarySchema);
		$path = __DIR__ . '/data/DoctrineAttributeCheckerEntities.php';
		$result = $this->createChecker(FixtureBasicEntity::class, $schema)
			->inspect(FileSystem::read($path), $path, false);

		$rules = array_map(
			static fn (Violation $violation): string => $violation->rule,
			$result['violations'],
		);

		self::assertNotContains('database.column-type', $rules);
	}

	public function testMergedSnapshotIncludesSecondaryPrimaryKeyForeignKeySources(): void
	{
		$primarySchema = $this->createDatabaseSchema(
			primaryKeyColumns: ['attribute_check_association.id'],
			foreignKeys: [
				'attribute_check_association.id' => [
					new DatabaseForeignKey(
						'attribute_check_association_x_id',
						'attribute_check_target',
						'id',
						'RESTRICT',
					),
				],
			],
			includedTables: ['attribute_check_association', 'attribute_check_target'],
		);
		$secondarySchema = $this->createDatabaseSchema(
			primaryKeyColumns: ['attribute_check_basic.id'],
			foreignKeys: [
				'attribute_check_basic.id' => [
					new DatabaseForeignKey(
						'attribute_check_basic_x_id',
						'attribute_check_target',
						'id',
						'RESTRICT',
					),
				],
			],
			includedTables: ['attribute_check_basic'],
		);
		$schema = $primarySchema->withSecondary($secondarySchema);

		self::assertTrue(
			$schema->areTargetsEquivalentViaPrimaryKeyForeignKeys(
				'attribute_check_target',
				'id',
				'attribute_check_basic',
				'id',
			),
		);
	}

	public function testDatabaseSchemaSnapshotPrefixesQueries(): void
	{
		/** @var list<string> $queries */
		$queries = [];
		$connection = self::createStub(Connection::class);
		$connection->method('getDatabase')
			->willReturn('attribute_check');
		$connection->method('fetchAllAssociative')
			->willReturnCallback(
				static function (string $query) use (&$queries): array {
					$queries[] = $query;

					return [];
				},
			);

		DatabaseSchemaSnapshot::load($connection, '/* port 3307 */ ');

		self::assertCount(5, $queries);
		foreach ($queries as $query) {
			self::assertStringStartsWith('/* port 3307 */ ', $query);
		}
	}

	public function testMissingDatabaseForeignKeysAreNotReported(): void
	{
		$schema = $this->createDatabaseSchema(uniqueColumns: ['attribute_check_association.single_id']);
		$source = FileSystem::read(__DIR__ . '/data/DoctrineAttributeCheckerEntities.php');
		$result = $this->createChecker(FixtureAssociationEntity::class, $schema)
			->inspect($source, __DIR__ . '/data/DoctrineAttributeCheckerEntities.php', false);

		self::assertSame([], $result['violations']);
	}

	public function testOneToOneJoinColumnPrimaryKeyIsUnique(): void
	{
		$schema = $this->createDatabaseSchema(primaryKeyColumns: ['attribute_check_association.single_id']);
		$source = FileSystem::read(__DIR__ . '/data/DoctrineAttributeCheckerEntities.php');
		$result = $this->createChecker(FixtureAssociationEntity::class, $schema)
			->inspect($source, __DIR__ . '/data/DoctrineAttributeCheckerEntities.php', false);

		self::assertSame([], $result['violations']);
	}

	public function testOneToOneJoinColumnInCompositePrimaryKeyIsNotUnique(): void
	{
		$schema = $this->createDatabaseSchema(
			primaryKeyColumns: [
				'attribute_check_association.id',
				'attribute_check_association.single_id',
			],
		);
		$source = FileSystem::read(__DIR__ . '/data/DoctrineAttributeCheckerEntities.php');
		$result = $this->createChecker(FixtureAssociationEntity::class, $schema)
			->inspect($source, __DIR__ . '/data/DoctrineAttributeCheckerEntities.php', false);

		$violationsByRule = array_map(
			static fn (Violation $violation): string => $violation->rule,
			$result['violations'],
		);

		self::assertContains('database.one-to-one-unique', $violationsByRule);
	}

	public function testDatabaseJoinColumnForeignKeyTargetMatchesPrimaryKeyForeignKeyChain(): void
	{
		$schema = $this->createDatabaseSchema(
			primaryKeyColumns: ['attribute_check_basic.id'],
			foreignKeys: [
				'attribute_check_association.target_id' => [
					new DatabaseForeignKey(
						'attribute_check_association_x_target_id',
						'attribute_check_basic',
						'id',
						'RESTRICT',
					),
				],
				'attribute_check_basic.id' => [
					new DatabaseForeignKey(
						'attribute_check_basic_x_id',
						'attribute_check_target',
						'id',
						'RESTRICT',
					),
				],
			],
		);

		$this->assertNoDatabaseJoinColumnForeignKeyTargetViolation(FixtureAssociationEntity::class, $schema);
	}

	public function testDatabaseJoinColumnForeignKeyTargetMatchesPrimaryKeyForeignKeyChainInReverse(): void
	{
		$schema = $this->createDatabaseSchema(
			primaryKeyColumns: ['attribute_check_target.id'],
			foreignKeys: [
				'attribute_check_association.target_id' => [
					new DatabaseForeignKey(
						'attribute_check_association_x_target_id',
						'attribute_check_basic',
						'id',
						'RESTRICT',
					),
				],
				'attribute_check_target.id' => [
					new DatabaseForeignKey(
						'attribute_check_target_x_id',
						'attribute_check_basic',
						'id',
						'RESTRICT',
					),
				],
			],
		);

		$this->assertNoDatabaseJoinColumnForeignKeyTargetViolation(FixtureAssociationEntity::class, $schema);
	}

	public function testDatabaseJoinColumnForeignKeyTargetMatchesCompositePrimaryKeyMemberChain(): void
	{
		$schema = $this->createDatabaseSchema(
			primaryKeyColumns: [
				'attribute_check_composite_target.id',
				'attribute_check_composite_target.sequence',
			],
			foreignKeys: [
				'attribute_check_association.target_id' => [
					new DatabaseForeignKey(
						'attribute_check_association_x_target_id',
						'attribute_check_composite_target',
						'id',
						'RESTRICT',
					),
				],
				'attribute_check_composite_target.id' => [
					new DatabaseForeignKey(
						'attribute_check_composite_target_x_id',
						'attribute_check_target',
						'id',
						'RESTRICT',
					),
				],
			],
		);

		$this->assertNoDatabaseJoinColumnForeignKeyTargetViolation(FixtureAssociationEntity::class, $schema);
	}

	public function testCompositeJoinColumnsUseTheirMatchingReferencedColumn(): void
	{
		$schema = $this->createDatabaseSchema(
			primaryKeyColumns: [
				'attribute_check_composite_target.id',
				'attribute_check_composite_target.sequence',
			],
			foreignKeys: [
				'attribute_check_composite_association.target_id' => [
					new DatabaseForeignKey(
						'attribute_check_composite_association_x_target_id',
						'attribute_check_composite_target',
						'id',
						'RESTRICT',
					),
				],
				'attribute_check_composite_association.target_sequence' => [
					new DatabaseForeignKey(
						'attribute_check_composite_association_x_target_sequence',
						'attribute_check_composite_target',
						'sequence',
						'RESTRICT',
					),
				],
			],
		);

		$this->assertNoDatabaseJoinColumnForeignKeyTargetViolation(FixtureCompositeAssociationEntity::class, $schema);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function dataDatabaseRules(): array
	{
		return [
			'database column missing' => ['database.column-missing'],
			'database column type' => ['database.column-type'],
			'database column allows null' => ['database.column-allows-null'],
			'database column unique' => ['database.column-unique'],
			'database column length' => ['database.column-length'],
			'database column precision' => ['database.column-precision'],
			'database column scale' => ['database.column-scale'],
			'database column unsigned' => ['database.column-unsigned'],
			'database column fixed' => ['database.column-fixed'],
			'database column collation' => ['database.column-collation'],
			'database column definition' => ['database.column-definition'],
			'database join column missing' => ['database.join-column-missing'],
			'database join column rejects null' => ['database.join-column-rejects-null'],
			'database one-to-one unique' => ['database.one-to-one-unique'],
			'database join column unique' => ['database.join-column-unique'],
			'database join column foreign key target' => ['database.join-column-foreign-key-target'],
			'database table missing' => ['database.table-missing'],
			'database join table missing' => ['database.join-table-missing'],
		];
	}

	/**
	 * @return DatabaseCaseSettings
	 */
	private function databaseCaseSettings(string $caseName): array
	{
		$case = [
			'className' => FixtureBasicEntity::class,
			'rule' => $caseName,
			'fixable' => false,
			'missingTables' => [],
			'missingColumns' => [],
			'overrides' => [],
			'uniqueColumns' => [],
			'primaryKeyColumns' => [],
			'foreignKeys' => [],
			'sourceFragment' => null,
			'invalidFragment' => null,
			'fixedFragment' => null,
		];

		switch ($caseName) {
			case 'database.column-missing':
				$case['missingColumns'] = ['attribute_check_basic.name'];

				break;
			case 'database.column-type':
				$case['overrides']['attribute_check_basic.name'] = new DatabaseColumn(
					'int',
					'int',
					null,
					10,
					0,
					false,
					null,
				);
				$case['sourceFragment'] = "\t#[ORM\Column(length: 60)]\n\tpublic string \$name;";
				$case['invalidFragment'] = "\t#[ORM\Column]\n\tpublic string \$name;";

				break;
			case 'database.column-allows-null':
				$case['overrides']['attribute_check_basic.name'] = new DatabaseColumn(
					'varchar',
					'varchar(60)',
					60,
					null,
					null,
					true,
					'utf8mb4_general_ci',
				);

				break;
			case 'database.column-unique':
				$case['fixable'] = true;
				$case['uniqueColumns'] = ['attribute_check_basic.name'];
				$case['sourceFragment'] = '#[ORM\Column(length: 60)]';
				$case['invalidFragment'] = '#[ORM\Column(length: 60)]';
				$case['fixedFragment'] = '#[ORM\Column(length: 60, unique: true)]';

				break;
			case 'database.column-length':
				$case['fixable'] = true;
				$case['overrides']['attribute_check_basic.name'] = new DatabaseColumn(
					'varchar',
					'varchar(80)',
					80,
					null,
					null,
					false,
					'utf8mb4_general_ci',
				);
				$case['sourceFragment'] = '#[ORM\Column(length: 60)]';
				$case['invalidFragment'] = '#[ORM\Column(length: 60)]';
				$case['fixedFragment'] = '#[ORM\Column(length: 80)]';

				break;
			case 'database.column-precision':
				$case['fixable'] = true;
				$case['overrides']['attribute_check_basic.amount'] = new DatabaseColumn(
					'decimal',
					'decimal(12,2)',
					null,
					12,
					2,
					false,
					null,
				);
				$case['sourceFragment'] = "#[ORM\Column(type: 'decimal', precision: 10, scale: 2)]";
				$case['invalidFragment'] = "#[ORM\Column(type: 'decimal', precision: 10, scale: 2)]";
				$case['fixedFragment'] = "#[ORM\Column(type: 'decimal', precision: 12, scale: 2)]";

				break;
			case 'database.column-scale':
				$case['fixable'] = true;
				$case['overrides']['attribute_check_basic.amount'] = new DatabaseColumn(
					'decimal',
					'decimal(10,4)',
					null,
					10,
					4,
					false,
					null,
				);
				$case['sourceFragment'] = "#[ORM\Column(type: 'decimal', precision: 10, scale: 2)]";
				$case['invalidFragment'] = "#[ORM\Column(type: 'decimal', precision: 10, scale: 2)]";
				$case['fixedFragment'] = "#[ORM\Column(type: 'decimal', precision: 10, scale: 4)]";

				break;
			case 'database.column-unsigned':
				$case['fixable'] = true;
				$case['overrides']['attribute_check_basic.id'] = new DatabaseColumn(
					'int',
					'int unsigned',
					null,
					10,
					0,
					false,
					null,
					autoIncrement: true,
				);
				$case['sourceFragment'] = "\t#[ORM\Column]\n\tpublic int \$id;";
				$case['invalidFragment'] = "\t#[ORM\Column]\n\tpublic int \$id;";
				$case['fixedFragment'] = "\t#[ORM\Column(options: ['unsigned' => true])]\n\tpublic int \$id;";

				break;
			case 'database.column-fixed':
				$case['fixable'] = true;
				$case['overrides']['attribute_check_basic.name'] = new DatabaseColumn(
					'char',
					'char(60)',
					60,
					null,
					null,
					false,
					'utf8mb4_general_ci',
				);
				$case['sourceFragment'] = '#[ORM\Column(length: 60)]';
				$case['invalidFragment'] = '#[ORM\Column(length: 60)]';
				$case['fixedFragment'] = "#[ORM\Column(length: 60, options: ['fixed' => true])]";

				break;
			case 'database.column-collation':
				$case['fixable'] = true;
				$case['overrides']['attribute_check_basic.name'] = new DatabaseColumn(
					'varchar',
					'varchar(60)',
					60,
					null,
					null,
					false,
					'utf8mb4_cs_0900_ai_ci',
				);
				$case['sourceFragment'] = '#[ORM\Column(length: 60)]';
				$case['invalidFragment'] = '#[ORM\Column(length: 60)]';
				$case['fixedFragment'] = "#[ORM\Column(length: 60, options: ['collation' => 'utf8mb4_cs_0900_ai_ci'])]";

				break;
			case 'database.column-definition':
				$case['fixable'] = true;
				$case['overrides']['attribute_check_basic.name'] = new DatabaseColumn(
					'year',
					'year',
					null,
					4,
					0,
					false,
					null,
				);
				$case['sourceFragment'] = "\t#[ORM\Column(length: 60)]\n\tpublic string \$name;";
				$case['invalidFragment'] = "\t#[ORM\Column]\n\tpublic string \$name;";
				$case['fixedFragment'] = "\t#[ORM\Column(columnDefinition: 'YEAR')]\n\tpublic string \$name;";

				break;
			case 'database.join-column-missing':
				$case['className'] = FixtureAssociationEntity::class;
				$case['missingColumns'] = ['attribute_check_association.target_id'];

				break;
			case 'database.join-column-rejects-null':
				$case['className'] = FixtureAssociationEntity::class;
				$case['overrides']['attribute_check_association.target_id'] = new DatabaseColumn(
					'int',
					'int',
					null,
					10,
					0,
					false,
					null,
				);

				break;
			case 'database.one-to-one-unique':
				$case['className'] = FixtureAssociationEntity::class;

				break;
			case 'database.join-column-unique':
				$case['className'] = FixtureAssociationEntity::class;
				$case['fixable'] = true;
				$case['uniqueColumns'] = [
					'attribute_check_association.single_id',
					'attribute_check_association.target_id',
				];
				$case['sourceFragment'] = '#[ORM\JoinColumn(name: \'target_id\')]';
				$case['invalidFragment'] = '#[ORM\JoinColumn(name: \'target_id\')]';
				$case['fixedFragment'] = '#[ORM\JoinColumn(name: \'target_id\', unique: true)]';

				break;
			case 'database.join-column-foreign-key-target':
				$case['className'] = FixtureAssociationEntity::class;
				$case['foreignKeys'] = [
					'attribute_check_association.target_id' => [
						new DatabaseForeignKey(
							'attribute_check_association_x_target_id',
							'attribute_check_basic',
							'id',
							'RESTRICT',
						),
					],
				];

				break;
			case 'database.table-missing':
				$case['missingTables'] = ['attribute_check_basic'];
				$case['missingColumns'] = [
					'attribute_check_basic.amount',
					'attribute_check_basic.banned_dates',
					'attribute_check_basic.id',
					'attribute_check_basic.name',
					'attribute_check_basic.status',
				];

				break;
			case 'database.join-table-missing':
				$case['className'] = FixtureAssociationEntity::class;
				$case['missingTables'] = ['attribute_check_association_target'];

				break;
		}

		return $case;
	}

	/**
	 * @param array<string, DatabaseColumn> $overrides
	 * @param list<string> $missingTables
	 * @param list<string> $missingColumns
	 * @param list<string> $uniqueColumns
	 * @param list<string> $primaryKeyColumns
	 * @param array<string, list<DatabaseForeignKey>> $foreignKeys
	 * @param ?list<string> $includedTables
	 */
	private function createDatabaseSchema(
		array $overrides = [],
		array $missingTables = [],
		array $missingColumns = [],
		array $uniqueColumns = [],
		array $primaryKeyColumns = [],
		array $foreignKeys = [],
		?array $includedTables = null,
	): DatabaseSchemaSnapshot
	{
		$tables = [
			'attribute_check_association',
			'attribute_check_association_target',
			'attribute_check_basic',
			'attribute_check_boolean',
			'attribute_check_composite_association',
			'attribute_check_composite_target',
			'attribute_check_reserved',
			'attribute_check_target',
			'attribute_check_typed_association',
			'attribute_check_typed_one_to_one',
		];
		$tableRows = [];
		foreach ($tables as $tableName) {
			if (
				in_array($tableName, $missingTables, true)
				|| (
					$includedTables !== null
					&& !in_array($tableName, $includedTables, true)
				)
			) {
				continue;
			}

			$tableRows[] = [
				'TABLE_NAME' => $tableName,
				'TABLE_COLLATION' => 'utf8mb4_general_ci',
			];
		}

		$columns = [
			'attribute_check_association.id' => new DatabaseColumn('int', 'int', null, 10, 0, false, null, autoIncrement: true),
			'attribute_check_association.target_id' => new DatabaseColumn('int', 'int', null, 10, 0, true, null),
			'attribute_check_association.single_id' => new DatabaseColumn('int', 'int', null, 10, 0, true, null),
			'attribute_check_composite_association.id' => new DatabaseColumn('int', 'int', null, 10, 0, false, null, autoIncrement: true),
			'attribute_check_composite_association.target_id' => new DatabaseColumn(
				'int',
				'int',
				null,
				10,
				0,
				true,
				null,
			),
			'attribute_check_composite_association.target_sequence' => new DatabaseColumn(
				'int',
				'int',
				null,
				10,
				0,
				true,
				null,
			),
			'attribute_check_composite_target.id' => new DatabaseColumn('int', 'int', null, 10, 0, false, null, autoIncrement: true),
			'attribute_check_composite_target.sequence' => new DatabaseColumn('int', 'int', null, 10, 0, false, null),
			'attribute_check_association_target.attribute_check_association_id' => new DatabaseColumn(
				'int',
				'int',
				null,
				10,
				0,
				false,
				null,
			),
			'attribute_check_association_target.attribute_check_target_id' => new DatabaseColumn(
				'int',
				'int',
				null,
				10,
				0,
				false,
				null,
			),
			'attribute_check_basic.id' => new DatabaseColumn('int', 'int', null, 10, 0, false, null, autoIncrement: true),
			'attribute_check_boolean.id' => new DatabaseColumn('int', 'int', null, 10, 0, false, null, autoIncrement: true),
			'attribute_check_boolean.enabled' => new DatabaseColumn(
				'tinyint',
				'tinyint(1) unsigned',
				null,
				3,
				0,
				false,
				null,
			),
			'attribute_check_basic.name' => new DatabaseColumn(
				'varchar',
				'varchar(60)',
				60,
				null,
				null,
				false,
				'utf8mb4_general_ci',
			),
			'attribute_check_basic.banned_dates' => new DatabaseColumn('json', 'json', null, null, null, false, null),
			'attribute_check_basic.status' => new DatabaseColumn(
				'enum',
				"enum('active')",
				null,
				null,
				null,
				false,
				'utf8mb4_general_ci',
			),
			'attribute_check_basic.amount' => new DatabaseColumn(
				'decimal',
				'decimal(10,2)',
				null,
				10,
				2,
				false,
				null,
			),
			'attribute_check_reserved.id' => new DatabaseColumn('int', 'int', null, 10, 0, false, null, autoIncrement: true),
			'attribute_check_reserved.order' => new DatabaseColumn(
				'varchar',
				'varchar(60)',
				60,
				null,
				null,
				false,
				'utf8mb4_general_ci',
			),
			'attribute_check_target.id' => new DatabaseColumn('int', 'int', null, 10, 0, false, null, autoIncrement: true),
			'attribute_check_target.association_id' => new DatabaseColumn('int', 'int', null, 10, 0, true, null),
			'attribute_check_typed_association.id' => new DatabaseColumn('int', 'int', null, 10, 0, false, null, autoIncrement: true),
			'attribute_check_typed_association.target_id' => new DatabaseColumn(
				'int',
				'int',
				null,
				10,
				0,
				true,
				null,
			),
			'attribute_check_typed_one_to_one.id' => new DatabaseColumn('int', 'int', null, 10, 0, false, null, autoIncrement: true),
			'attribute_check_typed_one_to_one.single_id' => new DatabaseColumn(
				'int',
				'int',
				null,
				10,
				0,
				true,
				null,
			),
		];
		foreach ($overrides as $key => $column) {
			$columns[$key] = $column;
		}

		$columnRows = [];
		foreach ($columns as $key => $column) {
			if (in_array($key, $missingColumns, true)) {
				continue;
			}

			[$tableName, $columnName] = explode('.', $key, 2);
			if ($includedTables !== null && !in_array($tableName, $includedTables, true)) {
				continue;
			}

			$columnRows[] = [
				'TABLE_NAME' => $tableName,
				'COLUMN_NAME' => $columnName,
				'IS_NULLABLE' => $column->nullable ? 'YES' : 'NO',
				'DATA_TYPE' => $column->dataType,
				'COLUMN_TYPE' => $column->columnType,
				'CHARACTER_MAXIMUM_LENGTH' => $column->characterMaximumLength === null
					? null
					: (string) $column->characterMaximumLength,
				'NUMERIC_PRECISION' => $column->numericPrecision === null ? null : (string) $column->numericPrecision,
				'NUMERIC_SCALE' => $column->numericScale === null ? null : (string) $column->numericScale,
				'COLLATION_NAME' => $column->collation,
				'COLUMN_DEFAULT' => $column->hasDefault ? '' : null,
				'EXTRA' => ($column->autoIncrement ? 'auto_increment' : '') . ($column->generated ? 'STORED GENERATED' : ''),
			];
		}

		$uniqueRows = [];
		foreach ($uniqueColumns as $key) {
			[$tableName, $columnName] = explode('.', $key, 2);
			if ($includedTables !== null && !in_array($tableName, $includedTables, true)) {
				continue;
			}

			$uniqueRows[] = ['TABLE_NAME' => $tableName, 'COLUMN_NAME' => $columnName];
		}

		$primaryKeyRows = [];
		foreach ($primaryKeyColumns as $key) {
			[$tableName, $columnName] = explode('.', $key, 2);
			if ($includedTables !== null && !in_array($tableName, $includedTables, true)) {
				continue;
			}

			$primaryKeyRows[] = ['TABLE_NAME' => $tableName, 'COLUMN_NAME' => $columnName];
		}

		$foreignKeyRows = [];
		foreach ($foreignKeys as $key => $tableForeignKeys) {
			[$tableName, $columnName] = explode('.', $key, 2);
			if ($includedTables !== null && !in_array($tableName, $includedTables, true)) {
				continue;
			}

			foreach ($tableForeignKeys as $foreignKey) {
				$foreignKeyRows[] = [
					'TABLE_NAME' => $tableName,
					'COLUMN_NAME' => $columnName,
					'CONSTRAINT_NAME' => $foreignKey->constraintName,
					'REFERENCED_TABLE_NAME' => $foreignKey->referencedTable,
					'REFERENCED_COLUMN_NAME' => $foreignKey->referencedColumn,
					'DELETE_RULE' => $foreignKey->deleteRule,
				];
			}
		}

		$connection = self::createStub(Connection::class);
		$connection->method('getDatabase')
			->willReturn('attribute_check');
		$connection->method('getDatabasePlatform')
			->willReturn(new MySQLPlatform());
		$connection->method('getConfiguration')
			->willReturn($this->connection->getConfiguration());
		$connection->method('fetchAllAssociative')
			->willReturnCallback(
				static function (string $query) use (
					$tableRows,
					$columnRows,
					$uniqueRows,
					$primaryKeyRows,
					$foreignKeyRows,
				): array {
					if (str_contains($query, 'information_schema.TABLES')) {
						return $tableRows;
					}

					if (str_contains($query, 'information_schema.COLUMNS')) {
						return $columnRows;
					}

					if (str_contains($query, 'information_schema.STATISTICS')) {
						return str_contains($query, 'INDEX_NAME <>')
							? $uniqueRows
							: $primaryKeyRows;
					}

					if (str_contains($query, 'KEY_COLUMN_USAGE')) {
						return $foreignKeyRows;
					}

					throw new LogicException(sprintf('Unexpected database query: %s', $query));
				},
			);
		$this->connection = $connection;

		return DatabaseSchemaSnapshot::load($connection);
	}

	private function createConnectionStub(): Connection
	{
		$connection = self::createStub(Connection::class);
		$connection->method('getDatabasePlatform')
			->willReturn(new MySQLPlatform());
		$dbalConfiguration = new DbalConfiguration();
		$dbalConfiguration->setTypeProvider(new TypeRegistry());
		$connection->method('getConfiguration')
			->willReturn($dbalConfiguration);

		return $connection;
	}

	/**
	 * @param class-string $className
	 */
	private function assertNoDatabaseJoinColumnForeignKeyTargetViolation(
		string $className,
		DatabaseSchemaSnapshot $schema,
	): void
	{
		$path = __DIR__ . '/data/DoctrineAttributeCheckerEntities.php';
		$result = $this->createChecker($className, $schema)
			->inspect(FileSystem::read($path), $path, false);

		$violations = [];
		foreach ($result['violations'] as $violation) {
			if ($violation->rule !== 'database.join-column-foreign-key-target') {
				continue;
			}

			$violations[] = $violation->rule;
		}

		self::assertSame([], $violations);
	}

	/**
	 * @return array<string, array{
	 *     0: class-string,
	 *     1: 'class'|'body',
	 *     2: string,
	 *     3: string,
	 *     4: ?string,
	 *     5: list<string>
	 * }>
	 */
	public static function dataSourceRules(): array
	{
		return [
			'column nullable' => [
				FixtureBasicEntity::class,
				'body',
				'#[ORM\Column(length: 60)]',
				'#[ORM\Column(length: 60, nullable: true)]',
				'#[ORM\Column(length: 60)]',
				['column.nullable'],
			],
			'column unique false' => [
				FixtureBasicEntity::class,
				'body',
				'#[ORM\Column(length: 60)]',
				'#[ORM\Column(length: 60, unique: false)]',
				'#[ORM\Column(length: 60)]',
				['column.unique'],
			],
			'column options nullable' => [
				FixtureBasicEntity::class,
				'body',
				'#[ORM\Column(length: 60)]',
				"#[ORM\Column(length: 60, options: ['nullable' => true])]",
				'#[ORM\Column(length: 60)]',
				['column.options.nullable'],
			],
			'column options default' => [
				FixtureBasicEntity::class,
				'body',
				'#[ORM\Column(length: 60)]',
				"#[ORM\Column(length: 60, options: ['default' => 'unknown'])]",
				'#[ORM\Column(length: 60)]',
				['column.options.default'],
			],
			'column options comment' => [
				FixtureBasicEntity::class,
				'body',
				'#[ORM\Column(length: 60)]',
				"#[ORM\Column(length: 60, options: ['comment' => 'unused'])]",
				'#[ORM\Column(length: 60)]',
				['column.options.comment'],
			],
			'column options order' => [
				FixtureBasicEntity::class,
				'body',
				"#[ORM\Column(type: 'json')]",
				"#[ORM\Column(type: 'json', options: ['collation' => 'utf8mb4_bin', 'unsigned' => true])]",
				"#[ORM\Column(type: 'json', options: ['unsigned' => true, 'collation' => 'utf8mb4_bin'])]",
				['column.options.order'],
			],
			'column argument order' => [
				FixtureBasicEntity::class,
				'body',
				"#[ORM\Column(type: 'json')]",
				"#[ORM\Column(length: 32, type: 'json')]",
				"#[ORM\Column(type: 'json', length: 32)]",
				['column.argument-order'],
			],
			'column type inferred' => [
				FixtureBasicEntity::class,
				'body',
				"\t#[ORM\Column]\n\tpublic int \$id;",
				"\t#[ORM\Column(type: 'integer')]\n\tpublic int \$id;",
				"\t#[ORM\Column]\n\tpublic int \$id;",
				['column.type'],
			],
			'column json type required' => [
				FixtureBasicEntity::class,
				'body',
				"#[ORM\Column(type: 'json')]",
				'#[ORM\Column]',
				"#[ORM\Column(type: 'json')]",
				['column.type-json'],
			],
			'column enum type inferred' => [
				FixtureBasicEntity::class,
				'body',
				"\t#[ORM\Column]\n\tpublic FixtureStatus \$status;",
				"\t#[ORM\Column(enumType: FixtureStatus::class)]\n\tpublic FixtureStatus \$status;",
				"\t#[ORM\Column]\n\tpublic FixtureStatus \$status;",
				['column.enum-type'],
			],
			'column empty parentheses' => [
				FixtureBasicEntity::class,
				'body',
				'#[ORM\Column(length: 60)]',
				'#[ORM\Column()]',
				'#[ORM\Column]',
				['column.empty-parentheses'],
			],
			'column insertable forbidden' => [
				FixtureBasicEntity::class,
				'body',
				'#[ORM\Column(length: 60)]',
				'#[ORM\Column(length: 60, insertable: false)]',
				null,
				['column.insertable'],
			],
			'column updatable forbidden' => [
				FixtureBasicEntity::class,
				'body',
				'#[ORM\Column(length: 60)]',
				'#[ORM\Column(length: 60, updatable: false)]',
				null,
				['column.updatable'],
			],
			'column generated forbidden' => [
				FixtureBasicEntity::class,
				'body',
				'#[ORM\Column(length: 60)]',
				"#[ORM\Column(length: 60, generated: 'ALWAYS')]",
				null,
				['column.generated'],
			],
			'entity table attribute missing' => [
				FixtureBasicEntity::class,
				'class',
				"#[ORM\Table(name: 'attribute_check_basic')]\n",
				'',
				"#[ORM\Table(name: 'attribute_check_basic')]\n",
				['table.attribute-missing'],
			],
			'table name required' => [
				FixtureBasicEntity::class,
				'class',
				"#[ORM\Table(name: 'attribute_check_basic')]",
				'#[ORM\Table]',
				"#[ORM\Table(name: 'attribute_check_basic')]",
				['table.name'],
			],
			'table unique constraints removed' => [
				FixtureBasicEntity::class,
				'class',
				"#[ORM\Table(name: 'attribute_check_basic')]",
				"#[ORM\Table(name: 'attribute_check_basic', uniqueConstraints: [])]",
				"#[ORM\Table(name: 'attribute_check_basic')]",
				['table.unique-constraints'],
			],
			'table indexes forbidden' => [
				FixtureBasicEntity::class,
				'class',
				"#[ORM\Table(name: 'attribute_check_basic')]",
				"#[ORM\Table(name: 'attribute_check_basic', indexes: [])]",
				null,
				['table.indexes'],
			],
			'table options forbidden' => [
				FixtureBasicEntity::class,
				'class',
				"#[ORM\Table(name: 'attribute_check_basic')]",
				"#[ORM\Table(name: 'attribute_check_basic', options: [])]",
				null,
				['table.options'],
			],
			'identifier default name' => [
				FixtureBasicEntity::class,
				'body',
				'#[ORM\Column(length: 60)]',
				"#[ORM\Column(name: 'name', length: 60)]",
				'#[ORM\Column(length: 60)]',
				['identifier.default-name'],
			],
			'identifier reserved' => [
				FixtureReservedEntity::class,
				'body',
				"\t#[ORM\Column]\n\tpublic string \$order;",
				"\t#[ORM\Column]\n\tpublic string \$order;",
				"\t#[ORM\Column(name: '`order`')]\n\tpublic string \$order;",
				['identifier.reserved'],
			],
			'identifier quoting' => [
				FixtureBasicEntity::class,
				'body',
				'#[ORM\Column(length: 60)]',
				"#[ORM\Column(name: '`name`', length: 60)]",
				'#[ORM\Column(length: 60)]',
				['identifier.default-name', 'identifier.quoting'],
			],
			'entity repository class redundant' => [
				FixtureBasicEntity::class,
				'class',
				'#[ORM\Entity]',
				'#[ORM\Entity(repositoryClass: \Doctrine\ORM\EntityRepository::class)]',
				'#[ORM\Entity]',
				['entity.repository-class'],
			],
			'entity repository class forbidden' => [
				FixtureBasicEntity::class,
				'class',
				'#[ORM\Entity]',
				'#[ORM\Entity(repositoryClass: \stdClass::class)]',
				null,
				['entity.repository-class'],
			],
			'cache region forbidden' => [
				FixtureBasicEntity::class,
				'class',
				'#[ORM\Entity]',
				"#[ORM\Entity]\n#[ORM\Cache(region: 'attribute_check')]",
				null,
				['cache.region'],
			],
			'cache usage forbidden' => [
				FixtureBasicEntity::class,
				'class',
				'#[ORM\Entity]',
				"#[ORM\Entity]\n#[ORM\Cache(usage: 'READ_ONLY')]",
				null,
				['cache.usage'],
			],
			'forbidden attribute' => [
				FixtureBasicEntity::class,
				'class',
				'#[ORM\Entity]',
				"#[ORM\Entity]\n#[ORM\Index(name: 'attribute_check_idx', fields: ['name'])]",
				null,
				['forbidden.attribute'],
			],
			'forbidden lifecycle callback' => [
				FixtureBasicEntity::class,
				'body',
				"\tpublic function callback(): void",
				"\t#[ORM\PrePersist]\n\tpublic function callback(): void",
				null,
				['forbidden.lifecycle-callback'],
			],
			'generated value auto redundant' => [
				FixtureBasicEntity::class,
				'body',
				'#[ORM\GeneratedValue]',
				"#[ORM\GeneratedValue(strategy: 'AUTO')]",
				'#[ORM\GeneratedValue]',
				['generated-value.strategy'],
			],
			'generated value strategy forbidden' => [
				FixtureBasicEntity::class,
				'body',
				'#[ORM\GeneratedValue]',
				"#[ORM\GeneratedValue(strategy: 'IDENTITY')]",
				null,
				['generated-value.strategy'],
			],
			'generated value none strategy is redundant' => [
				FixtureBasicEntity::class,
				'body',
				"\t#[ORM\\Id]\n\t#[ORM\\GeneratedValue]\n",
				"\t#[ORM\\Id]\n\t#[ORM\\GeneratedValue(strategy: 'NONE')]\n",
				"\t#[ORM\\Id]\n",
				['generated-value.strategy'],
			],
			'embedded class inferred' => [
				FixtureBasicEntity::class,
				'body',
				'#[ORM\Embedded]',
				'#[ORM\Embedded(class: FixtureAddress::class)]',
				'#[ORM\Embedded]',
				['embedded.class'],
			],
			'join column nullable conflict' => [
				FixtureAssociationEntity::class,
				'body',
				"\t#[ORM\JoinColumn(name: 'target_id')]\n\tpublic ?object \$target = null;",
				"\t#[ORM\JoinColumn(name: 'target_id', nullable: false)]\n\tpublic ?object \$target = null;",
				null,
				['join-column.nullable'],
			],
			'join column nullable inferred' => [
				FixtureAssociationEntity::class,
				'body',
				"\t#[ORM\JoinColumn(name: 'single_id')]\n\tpublic object \$single;",
				"\t#[ORM\JoinColumn(name: 'single_id', nullable: true)]\n\tpublic object \$single;",
				"\t#[ORM\JoinColumn(name: 'single_id')]\n\tpublic object \$single;",
				['join-column.nullable'],
			],
			'join column on delete forbidden' => [
				FixtureAssociationEntity::class,
				'body',
				"#[ORM\JoinColumn(name: 'target_id')]",
				"#[ORM\JoinColumn(name: 'target_id', onDelete: 'CASCADE')]",
				"#[ORM\JoinColumn(name: 'target_id')]",
				['join-column.on-delete'],
			],
			'inverse join column on delete forbidden' => [
				FixtureAssociationEntity::class,
				'body',
				"#[ORM\InverseJoinColumn(name: 'attribute_check_target_id')]",
				"#[ORM\InverseJoinColumn(name: 'attribute_check_target_id', onDelete: null)]",
				"#[ORM\InverseJoinColumn(name: 'attribute_check_target_id')]",
				['join-column.on-delete'],
			],
			'many to many join column name required' => [
				FixtureAssociationEntity::class,
				'body',
				"#[ORM\JoinColumn(name: 'attribute_check_association_id')]",
				'#[ORM\JoinColumn]',
				"#[ORM\JoinColumn(name: 'attribute_check_association_id')]",
				['join-column.name'],
			],
			'join column referenced id inferred' => [
				FixtureAssociationEntity::class,
				'body',
				"#[ORM\JoinColumn(name: 'target_id')]",
				"#[ORM\JoinColumn(name: 'target_id', referencedColumnName: 'id')]",
				"#[ORM\JoinColumn(name: 'target_id')]",
				['join-column.referenced-column-name'],
			],
			'join column unique false' => [
				FixtureAssociationEntity::class,
				'body',
				"#[ORM\JoinColumn(name: 'target_id')]",
				"#[ORM\JoinColumn(name: 'target_id', unique: false)]",
				"#[ORM\JoinColumn(name: 'target_id')]",
				['join-column.unique'],
			],
			'empty join column removed' => [
				FixtureAssociationEntity::class,
				'body',
				"\t#[ORM\JoinColumn(name: 'target_id')]\n\tpublic ?object \$target = null;",
				"\t#[ORM\JoinColumn]\n\tpublic ?object \$target = null;",
				"\tpublic ?object \$target = null;",
				['join-column.empty'],
			],
			'join table name required' => [
				FixtureAssociationEntity::class,
				'body',
				"#[ORM\JoinTable(name: 'attribute_check_association_target')]",
				'#[ORM\JoinTable]',
				"#[ORM\JoinTable(name: 'attribute_check_association_target')]",
				['join-table.name'],
			],
			'join table attribute required' => [
				FixtureAssociationEntity::class,
				'body',
				"\t#[ORM\JoinTable(name: 'attribute_check_association_target')]\n",
				'',
				"\t#[ORM\JoinTable(name: 'attribute_check_association_target')]\n",
				['join-table.attribute-missing'],
			],
			'many to one fetch lazy' => [
				FixtureAssociationEntity::class,
				'body',
				'#[ORM\ManyToOne(targetEntity: FixtureTargetEntity::class)]',
				"#[ORM\ManyToOne(targetEntity: FixtureTargetEntity::class, fetch: 'LAZY')]",
				'#[ORM\ManyToOne(targetEntity: FixtureTargetEntity::class)]',
				['manytoone.fetch'],
			],
			'one to one fetch lazy' => [
				FixtureAssociationEntity::class,
				'body',
				'#[ORM\OneToOne(targetEntity: FixtureTargetEntity::class)]',
				"#[ORM\OneToOne(targetEntity: FixtureTargetEntity::class, fetch: 'LAZY')]",
				'#[ORM\OneToOne(targetEntity: FixtureTargetEntity::class)]',
				['onetoone.fetch'],
			],
			'one to many fetch lazy' => [
				FixtureAssociationEntity::class,
				'body',
				"#[ORM\OneToMany(targetEntity: FixtureTargetEntity::class, mappedBy: 'parent')]",
				"#[ORM\OneToMany(targetEntity: FixtureTargetEntity::class, mappedBy: 'parent', fetch: 'LAZY')]",
				"#[ORM\OneToMany(targetEntity: FixtureTargetEntity::class, mappedBy: 'parent')]",
				['onetomany.fetch'],
			],
			'many to many fetch lazy' => [
				FixtureAssociationEntity::class,
				'body',
				'#[ORM\ManyToMany(targetEntity: FixtureTargetEntity::class)]',
				"#[ORM\ManyToMany(targetEntity: FixtureTargetEntity::class, fetch: 'LAZY')]",
				'#[ORM\ManyToMany(targetEntity: FixtureTargetEntity::class)]',
				['manytomany.fetch'],
			],
			'one to one orphan removal false' => [
				FixtureAssociationEntity::class,
				'body',
				'#[ORM\OneToOne(targetEntity: FixtureTargetEntity::class)]',
				'#[ORM\OneToOne(targetEntity: FixtureTargetEntity::class, orphanRemoval: false)]',
				'#[ORM\OneToOne(targetEntity: FixtureTargetEntity::class)]',
				['onetoone.orphan-removal'],
			],
			'one to many orphan removal false' => [
				FixtureAssociationEntity::class,
				'body',
				"#[ORM\OneToMany(targetEntity: FixtureTargetEntity::class, mappedBy: 'parent')]",
				"#[ORM\OneToMany(targetEntity: FixtureTargetEntity::class, mappedBy: 'parent', orphanRemoval: false)]",
				"#[ORM\OneToMany(targetEntity: FixtureTargetEntity::class, mappedBy: 'parent')]",
				['onetomany.orphan-removal'],
			],
			'many to many orphan removal false' => [
				FixtureAssociationEntity::class,
				'body',
				'#[ORM\ManyToMany(targetEntity: FixtureTargetEntity::class)]',
				'#[ORM\ManyToMany(targetEntity: FixtureTargetEntity::class, orphanRemoval: false)]',
				'#[ORM\ManyToMany(targetEntity: FixtureTargetEntity::class)]',
				['manytomany.orphan-removal'],
			],
			'many to one cascade order' => [
				FixtureAssociationEntity::class,
				'body',
				'#[ORM\ManyToOne(targetEntity: FixtureTargetEntity::class)]',
				"#[ORM\ManyToOne(targetEntity: FixtureTargetEntity::class, cascade: ['remove', 'persist'])]",
				"#[ORM\ManyToOne(targetEntity: FixtureTargetEntity::class, cascade: ['persist', 'remove'])]",
				['manytoone.cascade-order'],
			],
			'one to one cascade order' => [
				FixtureAssociationEntity::class,
				'body',
				'#[ORM\OneToOne(targetEntity: FixtureTargetEntity::class)]',
				"#[ORM\OneToOne(targetEntity: FixtureTargetEntity::class, cascade: ['remove', 'persist'])]",
				"#[ORM\OneToOne(targetEntity: FixtureTargetEntity::class, cascade: ['persist', 'remove'])]",
				['onetoone.cascade-order'],
			],
			'one to many cascade order' => [
				FixtureAssociationEntity::class,
				'body',
				"#[ORM\OneToMany(targetEntity: FixtureTargetEntity::class, mappedBy: 'parent')]",
				"#[ORM\OneToMany(targetEntity: FixtureTargetEntity::class, mappedBy: 'parent', cascade: ['remove', 'persist'])]",
				"#[ORM\OneToMany(targetEntity: FixtureTargetEntity::class, mappedBy: 'parent', cascade: ['persist', 'remove'])]",
				['onetomany.cascade-order'],
			],
			'many to many cascade order' => [
				FixtureAssociationEntity::class,
				'body',
				'#[ORM\ManyToMany(targetEntity: FixtureTargetEntity::class)]',
				"#[ORM\ManyToMany(targetEntity: FixtureTargetEntity::class, cascade: ['remove', 'persist'])]",
				"#[ORM\ManyToMany(targetEntity: FixtureTargetEntity::class, cascade: ['persist', 'remove'])]",
				['manytomany.cascade-order'],
			],
			'many to one cascade values' => [
				FixtureAssociationEntity::class,
				'body',
				'#[ORM\ManyToOne(targetEntity: FixtureTargetEntity::class)]',
				"#[ORM\ManyToOne(targetEntity: FixtureTargetEntity::class, cascade: ['persist', 1])]",
				null,
				['manytoone.cascade-values'],
			],
			'one to one cascade values' => [
				FixtureAssociationEntity::class,
				'body',
				'#[ORM\OneToOne(targetEntity: FixtureTargetEntity::class)]',
				"#[ORM\OneToOne(targetEntity: FixtureTargetEntity::class, cascade: ['persist', 1])]",
				null,
				['onetoone.cascade-values'],
			],
			'one to many cascade values' => [
				FixtureAssociationEntity::class,
				'body',
				"#[ORM\OneToMany(targetEntity: FixtureTargetEntity::class, mappedBy: 'parent')]",
				"#[ORM\OneToMany(targetEntity: FixtureTargetEntity::class, mappedBy: 'parent', cascade: ['persist', 1])]",
				null,
				['onetomany.cascade-values'],
			],
			'many to many cascade values' => [
				FixtureAssociationEntity::class,
				'body',
				'#[ORM\ManyToMany(targetEntity: FixtureTargetEntity::class)]',
				"#[ORM\ManyToMany(targetEntity: FixtureTargetEntity::class, cascade: ['persist', 1])]",
				null,
				['manytomany.cascade-values'],
			],
			'many to one target entity inferred' => [
				FixtureTypedAssociationEntity::class,
				'body',
				'#[ORM\ManyToOne(targetEntity: FixtureTargetEntity::class)]',
				'#[ORM\ManyToOne(targetEntity: FixtureTargetEntity::class)]',
				'#[ORM\ManyToOne]',
				['manytoone.target-entity'],
			],
			'one to one target entity inferred' => [
				FixtureTypedOneToOneEntity::class,
				'body',
				'#[ORM\OneToOne(targetEntity: FixtureTargetEntity::class)]',
				'#[ORM\OneToOne(targetEntity: FixtureTargetEntity::class)]',
				'#[ORM\OneToOne]',
				['onetoone.target-entity'],
			],
			'many to one target entity mismatch' => [
				FixtureTypedAssociationEntity::class,
				'body',
				'#[ORM\ManyToOne(targetEntity: FixtureTargetEntity::class)]',
				'#[ORM\ManyToOne(targetEntity: FixtureAssociationEntity::class)]',
				null,
				['manytoone.target-entity'],
			],
			'one to one target entity mismatch' => [
				FixtureTypedOneToOneEntity::class,
				'body',
				'#[ORM\OneToOne(targetEntity: FixtureTargetEntity::class)]',
				'#[ORM\OneToOne(targetEntity: FixtureAssociationEntity::class)]',
				null,
				['onetoone.target-entity'],
			],
			'many to many nested columns converted' => [
				FixtureAssociationEntity::class,
				'body',
				"\t#[ORM\JoinColumn(name: 'attribute_check_association_id')]\n"
				. "\t#[ORM\InverseJoinColumn(name: 'attribute_check_target_id')]\n"
				. "\t#[ORM\JoinTable(name: 'attribute_check_association_target')]\n"
				. "\tpublic Collection \$targets;",
				"\t#[ORM\JoinTable(name: 'attribute_check_association_target', "
				. "joinColumns: [new ORM\\JoinColumn(name: 'attribute_check_association_id')], "
				. "inverseJoinColumns: [new ORM\\InverseJoinColumn(name: 'attribute_check_target_id')])]\n"
				. "\tpublic Collection \$targets;",
				"\t#[ORM\JoinTable(name: 'attribute_check_association_target')]\n"
				. "\t#[ORM\JoinColumn(name: 'attribute_check_association_id')]\n"
				. "\t#[ORM\InverseJoinColumn(name: 'attribute_check_target_id')]\n"
				. "\tpublic Collection \$targets;",
				['join-table.column-class', 'join-table.nested-column'],
			],
			'many to many nested column classes corrected' => [
				FixtureAssociationEntity::class,
				'body',
				"\t#[ORM\JoinColumn(name: 'attribute_check_association_id')]\n"
				. "\t#[ORM\InverseJoinColumn(name: 'attribute_check_target_id')]\n"
				. "\t#[ORM\JoinTable(name: 'attribute_check_association_target')]\n"
				. "\tpublic Collection \$targets;",
				"\t#[ORM\JoinTable(name: 'attribute_check_association_target', "
				. "joinColumns: [new ORM\\InverseJoinColumn(name: 'attribute_check_association_id')], "
				. "inverseJoinColumns: [new ORM\\JoinColumn(name: 'attribute_check_target_id')])]\n"
				. "\tpublic Collection \$targets;",
				"\t#[ORM\JoinTable(name: 'attribute_check_association_target')]\n"
				. "\t#[ORM\JoinColumn(name: 'attribute_check_association_id')]\n"
				. "\t#[ORM\InverseJoinColumn(name: 'attribute_check_target_id')]\n"
				. "\tpublic Collection \$targets;",
				['join-table.column-class', 'join-table.nested-column'],
			],
			'many to many duplicate nested columns removed' => [
				FixtureAssociationEntity::class,
				'body',
				"\t#[ORM\JoinColumn(name: 'attribute_check_association_id')]\n"
				. "\t#[ORM\InverseJoinColumn(name: 'attribute_check_target_id')]\n"
				. "\t#[ORM\JoinTable(name: 'attribute_check_association_target')]\n"
				. "\tpublic Collection \$targets;",
				"\t#[ORM\JoinTable(name: 'attribute_check_association_target', "
				. "joinColumns: [new ORM\\JoinColumn(name: 'attribute_check_association_id'), "
				. "new ORM\\JoinColumn(name: 'attribute_check_association_id')], "
				. "inverseJoinColumns: [new ORM\\InverseJoinColumn(name: 'attribute_check_target_id')])]\n"
				. "\tpublic Collection \$targets;",
				"\t#[ORM\JoinTable(name: 'attribute_check_association_target')]\n"
				. "\t#[ORM\JoinColumn(name: 'attribute_check_association_id')]\n"
				. "\t#[ORM\InverseJoinColumn(name: 'attribute_check_target_id')]\n"
				. "\tpublic Collection \$targets;",
				['join-table.column-class', 'join-table.duplicate-column', 'join-table.nested-column'],
			],
		];
	}

	/**
	 * @param class-string $className
	 */
	private function replaceInScope(
		string $source,
		string $className,
		string $scope,
		string $search,
		string $replacement,
	): string
	{
		$classPosition = strpos($source, sprintf('class %s', (new ReflectionClass($className))->getShortName()));
		self::assertNotFalse($classPosition);

		if ($scope === 'class') {
			$scopeStart = strrpos(substr($source, 0, $classPosition), '#[ORM\Entity]');
			self::assertNotFalse($scopeStart);
			$scopeEnd = $classPosition;
		} else {
			$scopeStart = $classPosition;
			$scopeEnd = strlen($source);
			foreach (["\n#[ORM\Entity]", "\n#[ORM\Embeddable]", "\nenum "] as $marker) {
				$nextPosition = strpos($source, $marker, $classPosition + 1);
				if ($nextPosition !== false && $nextPosition < $scopeEnd) {
					$scopeEnd = $nextPosition;
				}
			}
		}

		$scopeSource = substr($source, $scopeStart, $scopeEnd - $scopeStart);
		self::assertSame(1, substr_count($scopeSource, $search), sprintf('Expected one occurrence of "%s".', $search));

		return substr($source, 0, $scopeStart)
			. str_replace($search, $replacement, $scopeSource)
			. substr($source, $scopeEnd);
	}

	/**
	 * @param class-string $className
	 */
	private function createChecker(
		string $className,
		?DatabaseSchemaSnapshot $databaseSchema = null,
		?CheckerConfiguration $checkerConfiguration = null,
	): DoctrineAttributeChecker
	{
		$entityManager = self::createStub(EntityManagerInterface::class);
		$entityManager->method('getConfiguration')
			->willReturn(self::$configuration);
		$entityManager->method('getConnection')
			->willReturn($this->connection);
		$entityManager->method('getClassMetadata')
			->willReturnCallback(static fn (string $targetClass): ClassMetadata => self::$metadataByClassName[$targetClass]);

		return new DoctrineAttributeChecker(
			$entityManager,
			$checkerConfiguration ?? self::loadCheckerConfiguration(),
			new AttributeSourceEditor(),
			self::$metadataByClassName,
			[$className => true],
			$databaseSchema,
		);
	}

	private static function loadCheckerConfiguration(?string $file = null): CheckerConfiguration
	{
		return (new ConfigurationLoader())->load($file ?? ConfigurationLoader::getDefaultsFile());
	}

}
