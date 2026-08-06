<?php

namespace mini\Database;

/**
 * The default scalar SQL function library.
 *
 * Nothing here is privileged: every function is registered through the same
 * mechanism a user gets from {@see VirtualDatabase::createFunction()}, so this
 * file doubles as the worked example of how to add your own.
 *
 * ```php
 * $vdb->createFunction('SLUGIFY', fn(?string $s) => $s === null
 *     ? null
 *     : strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $s), '-')), 1);
 *
 * $vdb->query("SELECT SLUGIFY(title) FROM posts");
 * ```
 *
 * Only functions that can be *expressed* as a PHP callable live here.
 * Constructs whose syntax is part of the grammar - `CAST(x AS INTEGER)`,
 * `POSITION(x IN y)`, `LIKE ... ESCAPE`, `CURRENT_DATE` - cannot be written as
 * a closure and stay native in the parser/evaluator. `POSITION` appears below
 * only because the parser lowers its `IN` syntax onto an ordinary two-argument
 * call.
 *
 * An `argCount` of -1 means variadic; the evaluator enforces anything else.
 * Functions with *optional* arguments (TRIM, ROUND, SUBSTR) are registered as
 * variadic and check their own arity.
 *
 * @see VirtualDatabase::createFunction()
 * @see ExpressionEvaluator::registerFunction()
 */
final class StandardFunctions
{
    /** @var array<string, array{fn: callable, argCount: int}>|null */
    private static ?array $registry = null;

    /**
     * The default registry contents, in the shape the evaluator stores.
     *
     * Built once and handed out by value - an evaluator that registers or
     * overrides a function mutates its own copy, never this one.
     *
     * @return array<string, array{fn: callable, argCount: int}>
     */
    public static function all(): array
    {
        if (self::$registry !== null) {
            return self::$registry;
        }

        $defs = [
            // --- String functions -------------------------------------------
            'UPPER' => [fn(mixed $s) => $s === null ? null : \strtoupper((string)$s), 1],
            'LOWER' => [fn(mixed $s) => $s === null ? null : \strtolower((string)$s), 1],
            'LENGTH' => [fn(mixed $s) => $s === null ? null : \strlen((string)$s), 1],
            'LEN' => [fn(mixed $s) => $s === null ? null : \strlen((string)$s), 1],
            // SQL:2003 spellings. Mini's strings are PHP byte strings, so
            // character length and octet length coincide - all three are LENGTH.
            'CHAR_LENGTH' => [fn(mixed $s) => $s === null ? null : \strlen((string)$s), 1],
            'CHARACTER_LENGTH' => [fn(mixed $s) => $s === null ? null : \strlen((string)$s), 1],
            'OCTET_LENGTH' => [fn(mixed $s) => $s === null ? null : \strlen((string)$s), 1],

            'TRIM' => [fn(mixed ...$a) => self::trim('trim', $a), -1],
            'LTRIM' => [fn(mixed ...$a) => self::trim('ltrim', $a), -1],
            'RTRIM' => [fn(mixed ...$a) => self::trim('rtrim', $a), -1],

            'SUBSTR' => [fn(mixed ...$a) => self::substr($a), -1],
            'SUBSTRING' => [fn(mixed ...$a) => self::substr($a), -1],

            'CONCAT' => [
                fn(mixed ...$a) => \implode('', \array_map(fn($v) => (string)($v ?? ''), $a)),
                -1,
            ],

            'REPLACE' => [
                fn(mixed $s, mixed $search, mixed $replace) =>
                    ($s === null || $search === null || $replace === null)
                        ? null
                        : \str_replace((string)$search, (string)$replace, (string)$s),
                3,
            ],

            // INSTR(haystack, needle) - 1-based position, 0 when not found
            'INSTR' => [
                fn(mixed $haystack, mixed $needle) => self::instr($haystack, $needle),
                2,
            ],

            // POSITION(needle IN haystack) - the parser lowers the IN syntax to
            // a plain two-argument call in (needle, haystack) order
            'POSITION' => [
                fn(mixed $needle, mixed $haystack) => self::instr($haystack, $needle),
                2,
            ],

            // --- Numeric functions ------------------------------------------
            'ABS' => [fn(mixed $x) => $x === null ? null : \abs(self::numeric('ABS', $x)), 1],
            'ROUND' => [fn(mixed ...$a) => self::round($a), -1],
            'FLOOR' => [fn(mixed $x) => $x === null ? null : \floor(self::numeric('FLOOR', $x)), 1],
            'CEIL' => [fn(mixed $x) => $x === null ? null : \ceil(self::numeric('CEIL', $x)), 1],
            'CEILING' => [fn(mixed $x) => $x === null ? null : \ceil(self::numeric('CEILING', $x)), 1],

            // --- NULL handling ----------------------------------------------
            'COALESCE' => [fn(mixed ...$a) => self::coalesce($a), -1],
            'NULLIF' => [
                fn(mixed $a, mixed $b) => ($a !== null && $b !== null && $a == $b) ? null : $a,
                2,
            ],
            'IFNULL' => [fn(mixed $a, mixed $b) => $a ?? $b, 2],
            'NVL' => [fn(mixed $a, mixed $b) => $a ?? $b, 2],
        ];

        $registry = [];
        foreach ($defs as $name => [$fn, $argCount]) {
            $registry[$name] = ['fn' => $fn, 'argCount' => $argCount];
        }
        return self::$registry = $registry;
    }

    /**
     * SUBSTR(X, Y[, Z]) - 1-indexed substring.
     *
     * Positions are character positions starting at 1. A negative Y counts back
     * from the end of the string. A negative Z selects the abs(Z) characters
     * *preceding* position Y. Positions outside the string contribute nothing
     * rather than shifting the window, so SUBSTR('abcdef', 0, 2) is 'a'.
     * Any NULL argument yields NULL.
     */
    private static function substr(array $args): ?string
    {
        if (!isset($args[0])) return null;
        // NULL start/length propagate (a supplied-but-NULL argument is UNKNOWN)
        if (\array_key_exists(1, $args) && $args[1] === null) return null;
        if (\array_key_exists(2, $args) && $args[2] === null) return null;

        $str = (string)$args[0];
        $len = \strlen($str);

        $start = (int)($args[1] ?? 1);
        if ($start < 0) {
            $start = $len + $start + 1;
        }

        if (\array_key_exists(2, $args)) {
            $length = (int)$args[2];
            if ($length < 0) {
                $start += $length;
                $length = -$length;
            }
            $end = $start + $length; // exclusive
        } else {
            $end = $len + 1;
        }

        // Clamp the window to the string; out-of-range positions select nothing
        if ($start < 1) $start = 1;
        if ($end > $len + 1) $end = $len + 1;
        if ($end <= $start) return '';

        return \substr($str, $start - 1, $end - $start);
    }

    /**
     * TRIM/LTRIM/RTRIM with an optional character set (SQL:2003 TRIM(c FROM s)).
     */
    private static function trim(string $fn, array $args): ?string
    {
        if (!isset($args[0])) return null;
        if (\array_key_exists(1, $args) && $args[1] === null) return null;

        if (\array_key_exists(1, $args)) {
            $chars = (string)$args[1];
            // Trimming an empty character set removes nothing
            return $chars === '' ? (string)$args[0] : $fn((string)$args[0], $chars);
        }

        return $fn((string)$args[0]);
    }

    /**
     * ROUND(X[, D]) - a NULL precision makes the whole result NULL.
     */
    private static function round(array $args): int|float|null
    {
        if (!isset($args[0])) return null;
        if (\array_key_exists(1, $args) && $args[1] === null) return null;

        return \round(self::numeric('ROUND', $args[0]), (int)($args[1] ?? 0));
    }

    private static function instr(mixed $haystack, mixed $needle): ?int
    {
        if ($haystack === null || $needle === null) {
            return null;
        }
        $pos = \strpos((string)$haystack, (string)$needle);
        return $pos === false ? 0 : $pos + 1;
    }

    private static function coalesce(array $args): mixed
    {
        foreach ($args as $arg) {
            if ($arg !== null) {
                return $arg;
            }
        }
        return null;
    }

    /**
     * Coerce a scalar function argument to a number, or fail at the SQL level.
     *
     * PHP's math builtins raise a TypeError for non-numeric strings, which would
     * surface to the caller as a raw PHP engine message. Numeric strings are
     * accepted (SQL type coercion); anything else is an SQL error.
     */
    private static function numeric(string $function, mixed $value): int|float
    {
        if (\is_int($value) || \is_float($value)) {
            return $value;
        }
        if (\is_bool($value)) {
            return (int)$value;
        }
        if (\is_string($value) && \is_numeric(\trim($value))) {
            return \trim($value) + 0;
        }

        $type = \get_debug_type($value);
        throw new \RuntimeException("$function() expects a numeric argument, $type given");
    }
}
