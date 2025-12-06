<?php

namespace mini;

use mini\Converter\ConverterRegistryInterface;
use mini\Database\DatabaseInterface;
use mini\Database\PDOService;
use mini\Database\SqlValueInterface;
use PDO;

// Register services
Mini::$mini->addService(PDO::class, Lifetime::Scoped, function() {
    $pdo = Mini::$mini->loadServiceConfig(PDO::class);
    PDOService::configure($pdo);
    return $pdo;
});
Mini::$mini->addService(DatabaseInterface::class, Lifetime::Scoped, fn() => Mini::$mini->loadServiceConfig(DatabaseInterface::class));

// Register sql-value converters for common types
Mini::$mini->onPhase(Phase::Ready, function() {
    $registry = Mini::$mini->get(ConverterRegistryInterface::class);

    // DateTime -> string
    $registry->register(fn(\DateTimeInterface $dt): string => $dt->format('Y-m-d H:i:s'), 'sql-value');

    // BackedEnum -> string|int
    $registry->register(fn(\BackedEnum $enum): string|int => $enum->value, 'sql-value');

    // UnitEnum -> string (name)
    $registry->register(fn(\UnitEnum $enum): string => $enum->name, 'sql-value');

    // Stringable -> string
    $registry->register(fn(\Stringable $obj): string => (string) $obj, 'sql-value');

    // SqlValueInterface -> scalar
    $registry->register(fn(SqlValueInterface $obj): string|int|float|bool|null => $obj->toSqlValue(), 'sql-value');
});

/**
 * Get the database service instance
 *
 * Returns a lazy-loaded DatabaseInterface for executing queries.
 * Configuration is loaded from _config/database.php on first use.
 *
 * @return DatabaseInterface The database service
 */
function db(): DatabaseInterface {
    return Mini::$mini->get(DatabaseInterface::class);
}

/**
 * Convert a value to SQL-bindable scalar
 *
 * Uses the 'sql-value' converter target type. Returns the value unchanged
 * if it's already a scalar or null.
 *
 * @param mixed $value Value to convert
 * @return string|int|float|bool|null SQL-bindable value
 * @throws \InvalidArgumentException If value cannot be converted
 */
function sqlval(mixed $value): string|int|float|bool|null
{
    if ($value === null || is_scalar($value)) {
        return $value;
    }

    $converted = convert($value, 'sql-value');
    if ($converted !== null) {
        return $converted;
    }

    throw new \InvalidArgumentException(
        'Cannot convert ' . get_debug_type($value) . ' to SQL parameter. ' .
        'Implement SqlValueInterface or register an sql-value converter.'
    );
}
