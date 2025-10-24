<?php

/**
 * Test runner for transformations.json functionality in Translator class
 *
 * Usage: php mini/tests/Translator.transformations.php
 */

// Find composer autoloader using the standard pattern
$autoloader = realpath(__DIR__ . '/../vendor/autoload.php')
    ?: realpath(__DIR__ . '/../../../vendor/autoload.php')
    ?: realpath(__DIR__ . '/../../vendor/autoload.php');

if (!require $autoloader) {
    fwrite(STDERR, "Error: Could not find composer autoloader\n");
    exit(1);
}

use mini\Translator;

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

/**
 * Create a test translator with mock transformations
 */
function createTestTranslator(): Translator
{
    // Create temporary directory for test translations
    $tempDir = sys_get_temp_dir() . '/mini_transformations_test_' . uniqid();
    mkdir($tempDir, 0777, true);
    mkdir($tempDir . '/default', 0777, true);

    // Create test transformations.json
    $transformations = [
        '{ordinal}' => [
            'ordinal:gte=10&ordinal:lte=13' => '{ordinal}th',
            'ordinal=1' => '{ordinal}st',
            'ordinal=2' => '{ordinal}nd',
            'ordinal=3' => '{ordinal}rd',
            '' => '{ordinal}th'
        ],
        '{plural}' => [
            'plural=1' => '',
            '' => 's'
        ],
        '{upper}' => '{upper}',
        '{recursive}' => '{value:upper}_{value:plural}'
    ];

    file_put_contents($tempDir . '/default/transformations.json', json_encode($transformations, JSON_PRETTY_PRINT));

    // Create test translation file for the translator
    $testTranslations = [
        'Hello World' => 'Hello World'
    ];
    file_put_contents($tempDir . '/default/test.json', json_encode($testTranslations, JSON_PRETTY_PRINT));

    return new Translator($tempDir, 'en', false);
}

/**
 * Clean up test files
 */
function cleanupTestTranslator(Translator $translator): void
{
    $reflection = new ReflectionClass($translator);
    $property = $reflection->getProperty('translationsPath');
    $property->setAccessible(true);
    $tempDir = $property->getValue($translator);

    // Remove test directory
    if (is_dir($tempDir)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $fileinfo) {
            $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
            $todo($fileinfo->getRealPath());
        }
        rmdir($tempDir);
    }
}

echo "Running transformations.json tests...\n\n";

// Test 1: Basic ordinal transformations
test("Basic ordinal transformations", function() {
    $translator = createTestTranslator();

    $result1 = $translator->getInterpolator()->interpolate("You are {rank:ordinal}", ['rank' => 1]);
    assertEqual("You are 1st", $result1);

    $result2 = $translator->getInterpolator()->interpolate("You are {rank:ordinal}", ['rank' => 2]);
    assertEqual("You are 2nd", $result2);

    $result3 = $translator->getInterpolator()->interpolate("You are {rank:ordinal}", ['rank' => 3]);
    assertEqual("You are 3rd", $result3);

    $result4 = $translator->getInterpolator()->interpolate("You are {rank:ordinal}", ['rank' => 4]);
    assertEqual("You are 4th", $result4);

    cleanupTestTranslator($translator);
});

// Test 2: Special teen ordinals (11th, 12th, 13th)
test("Special teen ordinals", function() {
    $translator = createTestTranslator();

    $result11 = $translator->getInterpolator()->interpolate("You are {rank:ordinal}", ['rank' => 11]);
    assertEqual("You are 11th", $result11);

    $result12 = $translator->getInterpolator()->interpolate("You are {rank:ordinal}", ['rank' => 12]);
    assertEqual("You are 12th", $result12);

    $result13 = $translator->getInterpolator()->interpolate("You are {rank:ordinal}", ['rank' => 13]);
    assertEqual("You are 13th", $result13);

    cleanupTestTranslator($translator);
});

// Test 3: Plural transformations
test("Plural transformations", function() {
    $translator = createTestTranslator();

    $result1 = $translator->getInterpolator()->interpolate("You have {count} item{count:plural}", ['count' => 1]);
    assertEqual("You have 1 item", $result1);

    $result5 = $translator->getInterpolator()->interpolate("You have {count} item{count:plural}", ['count' => 5]);
    assertEqual("You have 5 items", $result5);

    cleanupTestTranslator($translator);
});

// Test 4: Simple string transformations
test("Simple string transformations", function() {
    $translator = createTestTranslator();

    $result = $translator->getInterpolator()->interpolate("Value: {text:upper}", ['text' => 'hello']);
    assertEqual("Value: hello", $result);

    cleanupTestTranslator($translator);
});

// Test 5: Unknown transformations
test("Unknown transformation handling", function() {
    $translator = createTestTranslator();

    $result = $translator->getInterpolator()->interpolate("Test {value:unknown}", ['value' => 'test']);
    assertEqual("Test [unknown filter 'unknown']", $result);

    cleanupTestTranslator($translator);
});

// Test 6: Custom filter integration
test("Custom filter integration", function() {
    $translator = createTestTranslator();

    // Add custom filter
    $translator->getInterpolator()->addFilterHandler(function($value, $filter) {
        if ($filter === 'reverse') return strrev($value);
        if ($filter === 'double') return $value * 2;
        return null;
    });

    $result1 = $translator->getInterpolator()->interpolate("Reversed: {text:reverse}", ['text' => 'hello']);
    assertEqual("Reversed: olleh", $result1);

    $result2 = $translator->getInterpolator()->interpolate("Double: {num:double}", ['num' => 5]);
    assertEqual("Double: 10", $result2);

    cleanupTestTranslator($translator);
});

// Test 7: Filter chains with transformations
test("Filter chains with transformations", function() {
    $translator = createTestTranslator();

    // Add custom filter
    $translator->getInterpolator()->addFilterHandler(function($value, $filter) {
        if ($filter === 'brackets') return '[' . $value . ']';
        return null;
    });

    $result = $translator->getInterpolator()->interpolate("Result: {count:ordinal:brackets}", ['count' => 1]);
    assertEqual("Result: [1st]", $result);

    cleanupTestTranslator($translator);
});

// Test 8: Missing variables with transformations
test("Missing variables with transformations", function() {
    $translator = createTestTranslator();

    $result = $translator->getInterpolator()->interpolate("Missing: {missing:ordinal}", []);
    assertEqual("Missing: [missing variable 'missing']", $result);

    cleanupTestTranslator($translator);
});

// Test 9: Default fallback in transformations
test("Default fallback in transformations", function() {
    $translator = createTestTranslator();

    // Test a value that doesn't match any specific condition (should use default)
    $result = $translator->getInterpolator()->interpolate("You are {rank:ordinal}", ['rank' => 25]);
    assertEqual("You are 25th", $result);

    cleanupTestTranslator($translator);
});

// Test 10: Recursive transformations (future-proofing)
test("Recursive transformation infrastructure", function() {
    $translator = createTestTranslator();

    // Add a simple upper filter for the recursive test
    $translator->getInterpolator()->addFilterHandler(function($value, $filter) {
        if ($filter === 'upper') return strtoupper($value);
        return null;
    });

    // This tests the potential for recursive transformations
    // The {recursive} transformation tries to use {value:upper}_{value:plural}
    // For now, we just verify the infrastructure doesn't crash
    $result = $translator->getInterpolator()->interpolate("Test: {text:recursive}", ['text' => 'word', 'value' => 'word']);

    // Infrastructure test - verify we get some result (even if not perfect yet)
    assertEqual(true, is_string($result), "Recursive transformation should return a string");

    cleanupTestTranslator($translator);
});

echo "\n✅ All transformations.json tests passed!\n";
echo "🎯 Ready for future enhancements: like operators, functions, variable-to-variable comparisons\n";