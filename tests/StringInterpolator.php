<?php

/**
 * Simple test runner for StringInterpolator class
 *
 * Usage: php mini/tests/StringInterpolator.php
 */

// Find composer autoloader using the standard pattern
$autoloader = realpath(__DIR__ . '/../vendor/autoload.php')
    ?: realpath(__DIR__ . '/../../../vendor/autoload.php')
    ?: realpath(__DIR__ . '/../../vendor/autoload.php');

if (!require $autoloader) {
    fwrite(STDERR, "Error: Could not find composer autoloader\n");
    exit(1);
}

use mini\Util\StringInterpolator;

/**
 * Simple test assertion helper
 */
function test(string $description, callable $test): void
{
    try {
        $test();
        echo "✓ $description\n";
    } catch (Exception $e) {
        fwrite(STDERR, "✗ $description\n");
        fwrite(STDERR, "  Error: " . $e->getMessage() . "\n");
        exit(1);
    }
}

/**
 * Assert two values are equal
 */
function assertEqual($expected, $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        $msg = $message ?: "Expected '$expected', got '$actual'";
        throw new Exception($msg);
    }
}

echo "Running StringInterpolator tests...\n\n";

// Test 1: Basic variable replacement
test("Basic variable replacement", function() {
    $si = new StringInterpolator();
    $result = $si->interpolate("Hello {name}!", ['name' => 'World']);
    assertEqual("Hello World!", $result);
});

// Test 2: Multiple variables
test("Multiple variables", function() {
    $si = new StringInterpolator();
    $result = $si->interpolate("Hello {first} {last}!", ['first' => 'John', 'last' => 'Doe']);
    assertEqual("Hello John Doe!", $result);
});

// Test 3: Missing variable
test("Missing variable error", function() {
    $si = new StringInterpolator();
    $result = $si->interpolate("Hello {missing}!", ['name' => 'World']);
    assertEqual("Hello [missing variable 'missing']!", $result);
});

// Test 4: Single filter
test("Single filter", function() {
    $si = new StringInterpolator();
    $si->addFilterHandler(function($value, $filter) {
        if ($filter === 'upper') return strtoupper($value);
        return null;
    });

    $result = $si->interpolate("Hello {name:upper}!", ['name' => 'world']);
    assertEqual("Hello WORLD!", $result);
});

// Test 5: Chained filters
test("Chained filters", function() {
    $si = new StringInterpolator();
    $si->addFilterHandler(function($value, $filter) {
        if ($filter === 'upper') return strtoupper($value);
        if ($filter === 'exclaim') return $value . '!!!';
        return null;
    });

    $result = $si->interpolate("Say {word:upper:exclaim}", ['word' => 'hello']);
    assertEqual("Say HELLO!!!", $result);
});

// Test 6: Unknown filter
test("Unknown filter error", function() {
    $si = new StringInterpolator();
    $result = $si->interpolate("Hello {name:unknown}!", ['name' => 'World']);
    assertEqual("Hello [unknown filter 'unknown']!", $result);
});

// Test 7: Multiple handlers
test("Multiple filter handlers", function() {
    $si = new StringInterpolator();

    // Handler 1: Basic filters
    $si->addFilterHandler(function($value, $filter) {
        if ($filter === 'upper') return strtoupper($value);
        return null; // Pass to next handler
    });

    // Handler 2: Custom filters
    $si->addFilterHandler(function($value, $filter) {
        if ($filter === 'reverse') return strrev($value);
        return null;
    });

    $result = $si->interpolate("{word:upper} {word:reverse}", ['word' => 'hello']);
    assertEqual("HELLO olleh", $result);
});

// Test 8: Double brace escaping
test("Double brace escaping", function() {
    $si = new StringInterpolator();
    $result = $si->interpolate("Use {{variable}} to show {name}", ['name' => 'value']);
    assertEqual("Use {variable} to show value", $result);
});

// Test 9: Backslash escaping
test("Backslash escaping", function() {
    $si = new StringInterpolator();
    $result = $si->interpolate("Use \\{variable} to show {name}", ['name' => 'value']);
    assertEqual("Use {variable} to show value", $result);
});

// Test 10: Mixed escaping and interpolation
test("Mixed escaping and interpolation", function() {
    $si = new StringInterpolator();
    $result = $si->interpolate("Replace {name} but keep {{count}} and \\{other}", ['name' => 'John', 'count' => 5]);
    assertEqual("Replace John but keep {count} and {other}", $result);
});

// Test 11: Complex filter chain with missing variable
test("Filter chain with missing variable", function() {
    $si = new StringInterpolator();
    $si->addFilterHandler(function($value, $filter) {
        if ($filter === 'upper') return strtoupper($value);
        return null;
    });

    $result = $si->interpolate("Hello {missing:upper}!", ['name' => 'World']);
    assertEqual("Hello [missing variable 'missing']!", $result);
});

// Test 12: Numeric values
test("Numeric values", function() {
    $si = new StringInterpolator();
    $si->addFilterHandler(function($value, $filter) {
        if ($filter === 'double') return $value * 2;
        return null;
    });

    $result = $si->interpolate("Count: {num:double}", ['num' => 5]);
    assertEqual("Count: 10", $result);
});

echo "\n✅ All tests passed!\n";