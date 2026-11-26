<?php declare(strict_types = 1);

namespace Slevomat\DoctrineAttributeChecker\Rule;

/**
 * A database rule that keeps the mapping exactly in sync with the database. Its differences do not break
 * the application (e.g. a longer column or a nullable column of a not nullable property, which is a usual
 * intermediate state of a backward compatible database change), so it is registered only with --strict.
 */
interface StrictRule extends Rule
{

}
