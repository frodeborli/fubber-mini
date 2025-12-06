<?php
/**
 * Minimal test assertions for Mini framework tests
 *
 * Usage in test files:
 *   require __DIR__ . '/assert.php';
 *
 *   assert_eq($expected, $actual);
 *   assert_true($condition);
 *   assert_throws(fn() => dangerousCode(), SomeException::class);
 *
 * Note: The test runner enables zend.assertions=1 and assert.exception=1
 */

/**
 * Assert two values are strictly equal
 */
function assert_eq(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        $e = var_export($expected, true);
        $a = var_export($actual, true);
        throw new AssertionError($msg ?: "Expected $e, got $a");
    }
}

/**
 * Assert condition is true
 */
function assert_true(mixed $condition, string $msg = ''): void
{
    if ($condition !== true) {
        throw new AssertionError($msg ?: "Expected true, got " . var_export($condition, true));
    }
}

/**
 * Assert condition is false
 */
function assert_false(mixed $condition, string $msg = ''): void
{
    if ($condition !== false) {
        throw new AssertionError($msg ?: "Expected false, got " . var_export($condition, true));
    }
}

/**
 * Assert value is null
 */
function assert_null(mixed $value, string $msg = ''): void
{
    if ($value !== null) {
        throw new AssertionError($msg ?: "Expected null, got " . var_export($value, true));
    }
}

/**
 * Assert value is not null
 */
function assert_not_null(mixed $value, string $msg = ''): void
{
    if ($value === null) {
        throw new AssertionError($msg ?: "Expected non-null value");
    }
}

/**
 * Assert callable throws expected exception
 */
function assert_throws(callable $fn, string $exceptionClass = Throwable::class, string $msg = ''): void
{
    try {
        $fn();
        throw new AssertionError($msg ?: "Expected $exceptionClass to be thrown");
    } catch (Throwable $e) {
        if (!$e instanceof $exceptionClass) {
            throw new AssertionError(
                $msg ?: "Expected $exceptionClass, got " . get_class($e) . ": " . $e->getMessage()
            );
        }
    }
}

/**
 * Assert string contains substring
 */
function assert_contains(string $needle, string $haystack, string $msg = ''): void
{
    if (!str_contains($haystack, $needle)) {
        throw new AssertionError($msg ?: "String does not contain '$needle'");
    }
}

/**
 * Assert array has key
 */
function assert_has_key(string|int $key, array $array, string $msg = ''): void
{
    if (!array_key_exists($key, $array)) {
        throw new AssertionError($msg ?: "Array missing key '$key'");
    }
}

/**
 * Assert count matches
 */
function assert_count(int $expected, array|Countable $value, string $msg = ''): void
{
    $actual = count($value);
    if ($expected !== $actual) {
        throw new AssertionError($msg ?: "Expected count $expected, got $actual");
    }
}
