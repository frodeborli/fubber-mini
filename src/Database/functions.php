<?php

namespace mini;

use mini\Converter\ConverterRegistryInterface;
use mini\Database\DatabaseInterface;
use mini\Database\PDOService;
use mini\Database\SqlValueHydrator;
use mini\Database\SqlValue;
use PDO;

// Register services
Mini::$mini->addService(PDO::class, Lifetime::Scoped, function() {
    $pdo = Mini::$mini->loadServiceConfig(PDO::class);
    PDOService::configure($pdo);
    return $pdo;
});
Mini::$mini->addService(DatabaseInterface::class, Lifetime::Scoped, fn() => Mini::$mini->loadServiceConfig(DatabaseInterface::class));

// Register sql-value converters for common types
Mini::$mini->phase->onEnteredState(Phase::Ready, function() {
    $registry = Mini::$mini->get(ConverterRegistryInterface::class);

    // =========================================================================
    // PHP → SQL (for query parameters)
    // Target type: 'sql-value'
    // =========================================================================

    // DateTime -> string
    $registry->register(fn(\DateTimeInterface $dt): string => $dt->format('Y-m-d H:i:s'), 'sql-value');

    // BackedEnum -> its backing value (string or int)
    $registry->register(fn(\BackedEnum $enum) => $enum->value, 'sql-value');

    // UnitEnum -> string (name)
    $registry->register(fn(\UnitEnum $enum) => $enum->name, 'sql-value');

    // Stringable -> string
    $registry->register(fn(\Stringable $obj) => (string) $obj, 'sql-value');

    // SqlValue -> scalar
    $registry->register(fn(SqlValue $obj) => $obj->toSqlValue(), 'sql-value');

    // =========================================================================
    // SQL → PHP (for entity hydration)
    // Source type: 'sql-value'
    // =========================================================================

    // sql-value -> bool
    // Handles: 0/1 (int), "0"/"1" (string), "" (empty string)
    $registry->register(function(int|string $v): bool {
        return $v !== 0 && $v !== '0' && $v !== '';
    }, null, 'sql-value');

    // sql-value -> DateTimeImmutable
    // Assumes database stores UTC. Converts to application timezone.
    $registry->register(function(string|int|float $v): \DateTimeImmutable {
        $utc = new \DateTimeZone('UTC');
        $appTz = new \DateTimeZone(date_default_timezone_get());

        if (is_string($v)) {
            // Parse as UTC, convert to app timezone
            $dt = new \DateTimeImmutable($v, $utc);
            return $dt->setTimezone($appTz);
        }
        if (is_float($v)) {
            // Float: seconds with microsecond precision (always UTC)
            $sec = (int) $v;
            $usec = (int) (($v - $sec) * 1_000_000);
            $dt = \DateTimeImmutable::createFromFormat('U u', "$sec $usec") ?: new \DateTimeImmutable("@$sec");
            return $dt->setTimezone($appTz);
        }
        // Integer: detect seconds vs milliseconds
        // Timestamps < 100 billion are seconds (covers until year ~5138)
        // Timestamps >= 100 billion are milliseconds
        if ($v >= 100_000_000_000) {
            $sec = intdiv($v, 1000);
            $usec = ($v % 1000) * 1000;
            $dt = \DateTimeImmutable::createFromFormat('U u', "$sec $usec") ?: new \DateTimeImmutable("@$sec");
            return $dt->setTimezone($appTz);
        }
        $dt = new \DateTimeImmutable("@$v");
        return $dt->setTimezone($appTz);
    }, null, 'sql-value');

    // sql-value -> DateTime
    // Assumes database stores UTC. Converts to application timezone.
    $registry->register(function(string|int|float $v): \DateTime {
        $utc = new \DateTimeZone('UTC');
        $appTz = new \DateTimeZone(date_default_timezone_get());

        if (is_string($v)) {
            // Parse as UTC, convert to app timezone
            $dt = new \DateTime($v, $utc);
            $dt->setTimezone($appTz);
            return $dt;
        }
        if (is_float($v)) {
            $sec = (int) $v;
            $usec = (int) (($v - $sec) * 1_000_000);
            $dt = \DateTime::createFromFormat('U u', "$sec $usec") ?: new \DateTime("@$sec");
            $dt->setTimezone($appTz);
            return $dt;
        }
        if ($v >= 100_000_000_000) {
            $sec = intdiv($v, 1000);
            $usec = ($v % 1000) * 1000;
            $dt = \DateTime::createFromFormat('U u', "$sec $usec") ?: new \DateTime("@$sec");
            $dt->setTimezone($appTz);
            return $dt;
        }
        $dt = new \DateTime("@$v");
        $dt->setTimezone($appTz);
        return $dt;
    }, null, 'sql-value');

    // =========================================================================
    // Fallback handler for type families
    // =========================================================================

    $registry->fallback->listen(function(mixed $input, string $targetType, ?string $sourceType): mixed {
        if ($sourceType !== 'sql-value') {
            return null;
        }

        // sql-value -> BackedEnum (any BackedEnum subclass)
        if (is_subclass_of($targetType, \BackedEnum::class)) {
            return $targetType::from($input);
        }

        // sql-value -> SqlValueHydrator (custom value objects)
        if (is_subclass_of($targetType, SqlValueHydrator::class)) {
            return $targetType::fromSqlValue($input);
        }

        return null;
    });
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
        'Implement SqlValue or register an sql-value converter.'
    );
}
