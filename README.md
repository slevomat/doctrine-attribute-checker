# Doctrine attribute checker

Checks Doctrine ORM 3 mapping attributes of your entities against a set of coding rules and,
optionally, against the actual MySQL database schema. Most findings can be fixed automatically.

- Removes redundant arguments (inferred names, types, defaults), enforces canonical argument order.
- Requires explicit `Table`/`JoinTable` names and escapes MySQL reserved identifiers.
- Forbids configured attributes, arguments and options (e.g. `Index`, `Column.options.default`).
- With `--db`, compares columns, types, lengths, precision, nullability, unsigned/fixed/collation options,
  join columns, foreign keys and unique indexes with the database.
- Supports a baseline of known violations, similar to PHPStan.

### Inheritance and embeddables

- Properties of a `MappedSuperclass` are compared with the columns of all entities that inherit them. When those columns
  differ (e.g. different lengths), a non-fixable violation lists the differing columns.
- Columns of properties declared in a subclass of a single table inheritance hierarchy may be nullable in the database
  even when the property is not, because rows of the other subclasses do not fill them.
- `Embeddable` classes are inspected too, although Doctrine does not list them among all metadata. Their properties are
  compared with the columns of all entities that embed them (with any column prefix). A nullable property may map
  a `NOT NULL` column when the column of another embedding entity is nullable, because the embeddable is shared and
  the stricter column only adds a database constraint.
- Columns of an embeddable embedded by a nullable property (directly or through a nullable outer embeddable) may be
  nullable in the database even when the embeddable property is not, because they are `NULL` when the property is null.
- `InheritanceType`, `DiscriminatorColumn` and `DiscriminatorMap` are forbidden by default; remove them from
  `attributes.forbidden` to use inheritance. `DiscriminatorColumn` is then compared with the database column like
  `Column` (existence, type, nullability, length, fixed and collation).

## Installation

```bash
composer require --dev slevomat/doctrine-attribute-checker
```

Create a PHP file returning your `Doctrine\ORM\EntityManagerInterface` and reference it from
`doctrine-attributes.neon` in the project root (the same idea as `objectManagerLoader` of phpstan-doctrine):

```php
<?php // build/entity-manager.php

return $container->get(EntityManagerInterface::class);
```

```neon
entityManagerLoader: build/entity-manager.php
```

To compare with tables that live in other databases, return a `CheckerEnvironment` instead. Tables missing in the
primary database are then looked up in the additional sources; the optional `queryPrefix` is prepended to every
`information_schema` query (useful e.g. for routing hints):

```php
use Slevomat\DoctrineAttributeChecker\Console\CheckerEnvironment;
use Slevomat\DoctrineAttributeChecker\Database\SchemaSource;

return new CheckerEnvironment($entityManager, [new SchemaSource($otherConnection, '/* port 3307 */ ')]);
```

Alternatively, register `Slevomat\DoctrineAttributeChecker\Console\CheckAttributesCommand` in your own Symfony
Console application and pass the entity manager (and schema sources) to its constructor; `entityManagerLoader`
is then not needed.

## Usage

```bash
vendor/bin/doctrine-attribute-checker                     # check all mapped entities
vendor/bin/doctrine-attribute-checker src/Entity/User.php # check selected files
vendor/bin/doctrine-attribute-checker --db                # include database schema rules
vendor/bin/doctrine-attribute-checker --fix               # apply automatic fixes (never modifies the database)
vendor/bin/doctrine-attribute-checker --db --generate-baseline            # write the configured baseline file
vendor/bin/doctrine-attribute-checker --db --generate-baseline=other.neon # write a baseline to another file
vendor/bin/doctrine-attribute-checker --configuration=path/to/config.neon
```

`--generate-baseline` runs a full check and writes all current violations to the baseline file.
Run it with `--db` if the baseline should contain database rules. It cannot be combined with `--fix`
or file arguments.

Baseline entries that no longer match any violation are reported as errors. With file arguments, only entries
whose `path` belongs to one of the inspected files are checked. Entries of `database.*` rules are checked only
with `--db`.

## Configuration

The command reads `doctrine-attributes.neon` from the current working directory (or the file given by
`--configuration`). The file is merged on top of [`config/default.neon`](config/default.neon), which documents
all options. Nested mappings are merged, lists are replaced and `ignoreErrors` entries from all files are
concatenated. Relative paths in the configuration (`includes`, `rootDirectory`, `entityManagerLoader`, `baseline`)
are resolved against the directory of the configuration file. Paths in `ignoreErrors` and in a generated baseline
are relative to `rootDirectory`, which defaults to the directory of the main configuration file; set it when
the configuration does not live in the project root (e.g. `rootDirectory: ../..`).

```neon
includes:
	- build/doctrine-attributes-baseline.neon

entityManagerLoader: build/entity-manager.php
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
