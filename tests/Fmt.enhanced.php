<?php

// Find composer autoloader using the standard pattern
$autoloader = realpath(__DIR__ . '/../vendor/autoload.php')
    ?: realpath(__DIR__ . '/../../../vendor/autoload.php')
    ?: realpath(__DIR__ . '/../../vendor/autoload.php');

if (!require $autoloader) {
    fwrite(STDERR, "Error: Could not find composer autoloader\n");
    exit(1);
}

use mini\I18n\Fmt;

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
        fwrite(STDERR, "  " . $e->getMessage() . "\n");
        exit(1);
    }
}

function assertEqual($expected, $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        $msg = $message ?: "Expected '$expected', got '$actual'";
        throw new Exception($msg);
    }
}

function assertTrue($condition, string $message = 'Assertion failed'): void
{
    if (!$condition) {
        throw new Exception($message);
    }
}

echo "Running Fmt static API tests...\n\n";

// Bootstrap framework
mini\bootstrap();

// Test 1: Number formatting
test("Number formatting", function() {
    $result = Fmt::number(1234.567, 2);
    assertTrue(is_string($result), "Should return string");
    assertTrue(str_contains($result, '1'), "Should contain digit 1");
});

// Test 2: Currency formatting
test("Currency formatting", function() {
    $result = Fmt::currency(19.99, 'USD');
    assertTrue(is_string($result), "Should return string");
    assertTrue(str_contains($result, '19') || str_contains($result, '20'), "Should contain amount");
});

// Test 3: Percent formatting
test("Percent formatting", function() {
    $result = Fmt::percent(0.75, 1);
    assertTrue(is_string($result), "Should return string");
    assertTrue(str_contains($result, '75'), "Should contain 75");
});

// Test 4: File size formatting
test("File size formatting", function() {
    $result = Fmt::fileSize(1024);
    assertTrue(is_string($result), "Should return string");
    assertTrue(str_contains($result, 'K') || str_contains($result, '1'), "Should format file size");
});

// Test 5: Date formatting
test("Date short formatting", function() {
    $date = new DateTime('2024-09-25 15:00:00');
    $result = Fmt::dateShort($date);
    assertTrue(is_string($result), "Should return string");
});

// Test 6: Date long formatting
test("Date long formatting", function() {
    $date = new DateTime('2024-09-25 15:00:00');
    $result = Fmt::dateLong($date);
    assertTrue(is_string($result), "Should return string");
});

// Test 7: Time short formatting
test("Time short formatting", function() {
    $time = new DateTime('2024-09-25 15:00:00');
    $result = Fmt::timeShort($time);
    assertTrue(is_string($result), "Should return string");
});

// Test 8: DateTime short formatting
test("DateTime short formatting", function() {
    $dateTime = new DateTime('2024-09-25 15:00:00');
    $result = Fmt::dateTimeShort($dateTime);
    assertTrue(is_string($result), "Should return string");
});

// Test 9: DateTime long formatting
test("DateTime long formatting", function() {
    $dateTime = new DateTime('2024-09-25 15:00:00');
    $result = Fmt::dateTimeLong($dateTime);
    assertTrue(is_string($result), "Should return string");
});

echo "\n✓ All Fmt static API tests passed!\n";
