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
    /**
     * Ceiling on a string a single function call may fabricate out of nothing.
     *
     * REPEAT and LPAD/RPAD take a length as *data*, so a hostile or mistyped
     * row can ask for a petabyte. PHP answers that with an uncatchable
     * out-of-memory fatal, which in a Fiber runtime takes every other
     * coroutine in the worker with it. An exception is a bug report.
     */
    private const MAX_STRING_BYTES = 16 * 1024 * 1024;

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
            'CHAR_LENGTH' => [fn(mixed $s) => $s === null ? null : self::charLength((string)$s), 1],
            'CHARACTER_LENGTH' => [fn(mixed $s) => $s === null ? null : self::charLength((string)$s), 1],
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

            'REPEAT' => [fn(mixed $s, mixed $n) => self::repeat($s, $n), 2],
            'REVERSE' => [fn(mixed $s) => $s === null ? null : self::reverse((string)$s), 1],
            'LPAD' => [fn(mixed ...$a) => self::pad('LPAD', \STR_PAD_LEFT, $a), -1],
            'RPAD' => [fn(mixed ...$a) => self::pad('RPAD', \STR_PAD_RIGHT, $a), -1],

            // --- Numeric functions ------------------------------------------
            'ABS' => [fn(mixed $x) => $x === null ? null : \abs(self::numeric('ABS', $x)), 1],
            'ROUND' => [fn(mixed ...$a) => self::round($a), -1],
            'FLOOR' => [fn(mixed $x) => $x === null ? null : \floor(self::numeric('FLOOR', $x)), 1],
            'CEIL' => [fn(mixed $x) => $x === null ? null : \ceil(self::numeric('CEIL', $x)), 1],
            'CEILING' => [fn(mixed $x) => $x === null ? null : \ceil(self::numeric('CEILING', $x)), 1],

            'MOD' => [fn(mixed $a, mixed $b) => self::mod($a, $b), 2],
            'SIGN' => [fn(mixed $x) => $x === null ? null : self::numeric('SIGN', $x) <=> 0, 1],
            'POWER' => [fn(mixed $b, mixed $e) => self::power($b, $e), 2],
            'POW' => [fn(mixed $b, mixed $e) => self::power($b, $e), 2],
            'SQRT' => [fn(mixed $x) => self::sqrt($x), 1],
            'EXP' => [fn(mixed $x) => self::exp($x), 1],
            'LN' => [fn(mixed $x) => self::ln($x), 1],
            // No LOG: one-argument LOG is base 10 in SQLite and PostgreSQL but
            // base e in MySQL, so the spelling has no agreed meaning. Write LN
            // and get an error, not a silently wrong base.

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

    /**
     * REPEAT(X, N) - X concatenated N times; N <= 0 gives the empty string.
     *
     * The size guard runs on N *before* it is narrowed to int. Casting an
     * out-of-range float to int in PHP wraps to an implementation-defined
     * value, and for the interesting magnitudes it wraps negative -
     * (int)1.0E+19 is -8446744073709551616 - which would read as "repeat zero
     * times" and hand back '' for a request that should have been refused.
     * A silently empty string is a wrong answer the query keeps building on.
     */
    private static function repeat(mixed $s, mixed $n): ?string
    {
        if ($s === null || $n === null) {
            return null;
        }

        $count = self::numeric('REPEAT', $n); // int|float, deliberately unnarrowed
        if ($count <= 0) {
            return '';
        }

        $s = (string)$s;
        if ($s === '') {
            return ''; // Any N of nothing is nothing, and costs nothing
        }

        // strlen($s) >= 1 here, so this product is >= $count: a $count over the
        // limit always raises, and past this line $count is small enough that
        // (int) is exact.
        self::checkWidth('REPEAT', $count * \strlen($s));
        return \str_repeat($s, (int)$count);
    }

    /**
     * LPAD/RPAD(X, LEN[, PAD]) - pad X to LEN with PAD (default a space).
     *
     * The pad string is repeated and clipped to fill exactly, so
     * LPAD('hi', 5, 'xy') is 'xyxhi'. A string already at least LEN long is
     * *truncated* to LEN, keeping its leading characters - the result is
     * always exactly LEN bytes. This is what MySQL, PostgreSQL and Oracle all
     * do; SQLite has no LPAD.
     *
     * As in {@see repeat()}, LEN is size-checked before it is narrowed to int -
     * an out-of-range float wraps negative under (int) and would look like a
     * non-positive length, returning '' instead of refusing the request.
     */
    private static function pad(string $name, int $mode, array $args): ?string
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \RuntimeException("$name() expects 2 or 3 arguments, $argc given");
        }
        if ($args[0] === null || $args[1] === null) {
            return null;
        }
        if ($argc === 3 && $args[2] === null) {
            return null;
        }

        $str = (string)$args[0];
        $length = self::numeric($name, $args[1]); // int|float, deliberately unnarrowed

        if ($length <= 0) {
            return '';
        }

        // Before any narrowing: the result is exactly $length bytes wide, so
        // this is the real cost of the call whatever the pad string turns out
        // to be. Past this line $length is small enough that (int) is exact.
        self::checkWidth($name, $length);
        $length = (int)$length;

        // Pad by CHARACTERS, not bytes. str_pad()/substr() would slice a
        // multibyte sequence in half and emit invalid UTF-8, which the
        // framework's own JSON encoder then refuses to serialize.
        $chars = self::chars($str);
        if ($chars === null) {
            // Not valid UTF-8 - fall back to byte semantics rather than throw
            return $length <= \strlen($str)
                ? \substr($str, 0, $length)
                : \str_pad($str, $length, (string)($args[2] ?? ' ') ?: ' ', $mode);
        }

        if ($length <= \count($chars)) {
            return \implode('', \array_slice($chars, 0, $length));
        }

        $pad = (string)($args[2] ?? ' ');
        if ($pad === '') {
            return $str; // Nothing to pad with; the string is all there is
        }

        $padChars = self::chars($pad) ?? \str_split($pad);
        $missing = $length - \count($chars);
        $fill = [];
        while (\count($fill) < $missing) {
            foreach ($padChars as $c) {
                if (\count($fill) >= $missing) {
                    break;
                }
                $fill[] = $c;
            }
        }
        $filler = \implode('', $fill);

        return $mode === \STR_PAD_LEFT ? $filler . $str : $str . $filler;
    }

    /**
     * Split a UTF-8 string into characters, or null when it is not valid UTF-8
     *
     * PCRE's /u mode is used rather than mbstring: ext-mbstring is not a
     * declared requirement, and the codebase already guards it elsewhere.
     * PCRE is always available.
     *
     * @return list<string>|null
     */
    private static function chars(string $s): ?array
    {
        if ($s === '') {
            return [];
        }
        $parts = \preg_split('//u', $s, -1, \PREG_SPLIT_NO_EMPTY);
        return $parts === false ? null : $parts;
    }

    /**
     * CHAR_LENGTH / CHARACTER_LENGTH - length in characters (SQL E021-04)
     *
     * Distinct from OCTET_LENGTH (bytes) and from this engine's legacy
     * byte-based LENGTH, whose contract predates these and is left alone.
     */
    private static function charLength(string $s): int
    {
        $count = \preg_match_all('/./us', $s);
        return $count === false ? \strlen($s) : $count;
    }

    /**
     * REVERSE - reverses characters, not bytes
     */
    private static function reverse(string $s): string
    {
        $chars = self::chars($s);
        return $chars === null ? \strrev($s) : \implode('', \array_reverse($chars));
    }

    /**
     * MOD(X, Y) - remainder of truncated division, so the sign follows X:
     * MOD(-7, 3) is -1, MOD(7, -3) is 1.
     *
     * Two integers give an integer (SQL:2003, MySQL, PostgreSQL, Oracle);
     * sqlite3 answers 1.0 for MOD(7,3) only because its mod() is a thin fmod()
     * wrapper. A float operand gives a float either way, so MOD(7.5, 2) is 1.5
     * in both. A zero divisor yields NULL, matching sqlite3 and the engine's
     * own `%` and `/` operators rather than raising.
     */
    private static function mod(mixed $a, mixed $b): int|float|null
    {
        if ($a === null || $b === null) {
            return null;
        }

        $a = self::numeric('MOD', $a);
        $b = self::numeric('MOD', $b);

        if ($b == 0) {
            return null;
        }

        return (\is_int($a) && \is_int($b)) ? $a % $b : \fmod($a, $b);
    }

    /**
     * POWER(X, Y) - always approximate numeric, as in sqlite3.
     *
     * A result that is not a real number is NULL: POWER(-8, 0.5) has no real
     * value and POWER(0, -1) is a division by zero. A result that is real but
     * too large to represent (POWER(10, 400)) raises instead - INF is not an
     * answer, it is a lost one.
     */
    private static function power(mixed $base, mixed $exponent): ?float
    {
        if ($base === null || $exponent === null) {
            return null;
        }

        $base = self::numeric('POWER', $base);
        $exponent = self::numeric('POWER', $exponent);

        if ($base == 0 && $exponent < 0) {
            return null;
        }

        $result = (float)($base ** $exponent);
        return \is_nan($result) ? null : self::finite('POWER', $result);
    }

    /** SQRT(X) - NULL for a negative X, which has no real square root. */
    private static function sqrt(mixed $x): ?float
    {
        if ($x === null) {
            return null;
        }

        $x = self::numeric('SQRT', $x);
        return $x < 0 ? null : \sqrt((float)$x);
    }

    /** LN(X) - natural logarithm; NULL outside its domain (X <= 0). */
    private static function ln(mixed $x): ?float
    {
        if ($x === null) {
            return null;
        }

        $x = self::numeric('LN', $x);
        return $x <= 0 ? null : \log((float)$x);
    }

    /** EXP(X) - e to the X. Overflow raises; underflow is an ordinary 0.0. */
    private static function exp(mixed $x): ?float
    {
        if ($x === null) {
            return null;
        }

        return self::finite('EXP', \exp(self::numeric('EXP', $x)));
    }

    /**
     * Refuse to hand back INF.
     *
     * NULL means "unknown", and an overflowed result is not unknown - it is
     * known and unrepresentable. Calling it NULL would quietly drop the row
     * out of a SUM; returning INF would quietly poison one.
     */
    private static function finite(string $function, float $value): float
    {
        if (\is_infinite($value)) {
            throw new \RuntimeException(
                "$function() overflowed: the result is too large to represent"
            );
        }
        return $value;
    }

    /**
     * @param int|float $bytes Float when the request arrived as one, or when
     *                         the multiplication left int range. Never narrow
     *                         it: the whole point is to reject sizes that int
     *                         cannot hold.
     */
    private static function checkWidth(string $function, int|float $bytes): void
    {
        if ($bytes > self::MAX_STRING_BYTES) {
            // %.0F rather than interpolation: a float would otherwise print as
            // "1.0E+19", which reads like a typo instead of a size.
            $size = \is_float($bytes) ? \sprintf('%.0F', $bytes) : (string)$bytes;
            throw new \RuntimeException(
                "$function() would build a string of $size bytes, over the "
                . self::MAX_STRING_BYTES . " byte limit"
            );
        }
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
