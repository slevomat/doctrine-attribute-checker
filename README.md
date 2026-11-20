# Doctrine attribute checker

Checks Doctrine ORM 3 mapping attributes of your entities against a set of coding rules and,
optionally, against the actual MySQL database schema. Most findings can be fixed automatically.

- Removes redundant arguments (inferred names, types, defaults), enforces canonical argument order.
- Requires explicit `Table`/`JoinTable` names and escapes MySQL reserved identifiers.
- Forbids configured attributes, arguments and options (e.g. `Index`, `Column.options.default`).
- With `--db`, compares columns, types, lengths, precision, nullability, unsigned/fixed/collation options,
  join columns, foreign keys and unique indexes with the database.
- Supports a baseline of known violations, similar to PHPStan.

## Installation

```bash
composer require --dev slevomat/doctrine-attribute-checker
```

Register the Symfony Console command in your application. It needs the `EntityManagerInterface`;
additional databases whose tables should be found when they are missing in the primary one can be passed
as `SchemaSource` instances:

```php
use Slevomat\DoctrineAttributeChecker\Console\CheckAttributesCommand;
use Slevomat\DoctrineAttributeChecker\Database\SchemaSource;

$application->addCommand(new CheckAttributesCommand(
	$entityManager,
	[new SchemaSource($otherConnection, '/* port 3307 */ ')], // optional
));
```

The optional `queryPrefix` argument of `SchemaSource` is prepended to every `information_schema` query
(useful e.g. for routing hints).

## Usage

```bash
bin/console orm:check-attributes                     # check all mapped entities
bin/console orm:check-attributes src/Entity/User.php # check selected files
bin/console orm:check-attributes --db                # include database schema rules
bin/console orm:check-attributes --fix               # apply automatic fixes (never modifies the database)
bin/console orm:check-attributes --db --generate-baseline            # write the configured baseline file
bin/console orm:check-attributes --db --generate-baseline=other.neon # write a baseline to another file
bin/console orm:check-attributes --configuration=path/to/config.neon
```

`--generate-baseline` runs a full check and writes all current violations to the baseline file.
Run it with `--db` if the baseline should contain database rules. It cannot be combined with `--fix`
or file arguments.

Baseline entries that no longer match any violation are reported on full runs; entries of `database.*` rules
are checked only with `--db`.

## Configuration

The command reads `doctrine-attributes.neon` from the current working directory (or the file given by
`--configuration`). The file is merged on top of [`config/default.neon`](config/default.neon), which documents
all options. Nested mappings are merged, lists are replaced and `ignoreErrors` entries from all files are
concatenated. Relative paths are resolved against the directory of the main configuration file.

```neon
includes:
	- build/doctrine-attributes-baseline.neon

baseline: build/doctrine-attributes-baseline.neon

rules:
	disabled:
		- database.column-collation
		- join-table.*                # trailing * matches a prefix

attributes:
	forbidden: [Index, UniqueConstraint, Version]
	allowedLifecycleCallbacks:
		App\Entity\Product:
			onPreRemove: [PreRemove]  # the entity may also declare HasLifecycleCallbacks
	allowCustomRepositoryClass: false

column:
	argumentOrder: [name, type, enumType, length, precision, scale, unique, columnDefinition, options]
	optionOrder: [unsigned, fixed, collation]
	forbiddenOptions: [nullable, default, comment]
	forbiddenArguments: [insertable, updatable, generated]

table:
	forbiddenArguments: [indexes, options]

association:
	cascadeOrder: [persist, remove, refresh, detach, merge, all]

cache:
	forbiddenArguments: [region, usage]

identifiers:
	additionalReservedWords: []
```

A disabled rule is neither reported nor fixed. Unknown rule identifiers in `rules.disabled` are reported as
a configuration error. The list of rules is in [`src/Rules.php`](src/Rules.php); configured forbidden
arguments and options add the rules `column.<argument>`, `column.options.<option>`, `table.<argument>`
and `cache.<argument>`.

The baseline file has the same format as the `ignoreErrors` section:

```neon
ignoreErrors:
	-
		rule: database.column-nullability
		target: App\Entity\User::$name
		path: src/Entity/User.php
		message: PHP/Doctrine nullability is not nullable but the database column is nullable.
```

## Development

```bash
composer install
composer check   # phpcs, phpstan, phpunit
```
