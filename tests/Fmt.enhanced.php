<?php

/**
 * Test runner for enhanced Fmt API with DateTimeInterface and timezone overrides
 *
 * Usage: php mini/tests/Fmt.enhanced.php
 */

// Find composer autoloader using the standard pattern
$autoloader = realpath(__DIR__ . '/../vendor/autoload.php')
    ?: realpath(__DIR__ . '/../../../vendor/autoload.php')
    ?: realpath(__DIR__ . '/../../vendor/autoload.php');

if (!require $autoloader) {
    fwrite(STDERR, "Error: Could not find composer autoloader\n");
    exit(1);
}

use mini\Fmt;

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

echo "Running enhanced Fmt API tests...\n\n";

// Save original timezone to restore later
$originalTimezone = date_default_timezone_get();

// Test 1: Integer timestamp input
test("Integer timestamp input", function() {
    $fmt = new Fmt();
    $fmt->trySetUserTimezone('UTC');

    $timestamp = 1727276400; // 2024-09-25 15:00:00 UTC
    $result = $fmt->dateTime($timestamp);

    assertEqual(true, is_string($result), "Should return string");
    assertEqual(true, strlen($result) > 0, "Should not be empty");
});

// Test 2: DateTime object input
test("DateTime object input", function() {
    $fmt = new Fmt();
    $fmt->trySetUserTimezone('UTC');

    $dateTime = new DateTime('2024-09-25 15:00:00', new DateTimeZone('UTC'));
    $result = $fmt->dateTime($dateTime);

    assertEqual(true, is_string($result), "Should handle DateTime objects");
    assertEqual(true, strlen($result) > 0, "Should not be empty");
});

// Test 3: DateTimeImmutable object input
test("DateTimeImmutable object input", function() {
    $fmt = new Fmt();
    $fmt->trySetUserTimezone('UTC');

    $dateTime = new DateTimeImmutable('2024-09-25 15:00:00', new DateTimeZone('UTC'));
    $result = $fmt->dateTime($dateTime);

    assertEqual(true, is_string($result), "Should handle DateTimeImmutable objects");
    assertEqual(true, strlen($result) > 0, "Should not be empty");
});

// Test 4: String input
test("String input", function() {
    $fmt = new Fmt();
    $fmt->trySetUserTimezone('UTC');

    $result = $fmt->dateTime('2024-09-25 15:00:00');

    assertEqual(true, is_string($result), "Should handle string input");
    assertEqual(true, strlen($result) > 0, "Should not be empty");
});

// Test 5: Timezone override with string
test("Timezone override with string", function() {
    $fmt = new Fmt();
    $fmt->trySetUserTimezone('UTC');

    $timestamp = 1727276400; // 2024-09-25 15:00:00 UTC

    $utcResult = $fmt->dateTime($timestamp);
    $osloResult = $fmt->dateTime($timestamp, 'Europe/Oslo');

    assertEqual(false, $utcResult === $osloResult, "UTC and Oslo should be different");
});

// Test 6: Timezone override with DateTimeZone object
test("Timezone override with DateTimeZone object", function() {
    $fmt = new Fmt();
    $fmt->trySetUserTimezone('UTC');

    $timestamp = 1727276400; // 2024-09-25 15:00:00 UTC
    $osloTz = new DateTimeZone('Europe/Oslo');

    $utcResult = $fmt->dateTime($timestamp);
    $osloResult = $fmt->dateTime($timestamp, $osloTz);

    assertEqual(false, $utcResult === $osloResult, "UTC and Oslo should be different with DateTimeZone object");
});

// Test 7: All date methods work with enhanced API
test("All date methods work with enhanced API", function() {
    $fmt = new Fmt();
    $fmt->trySetUserTimezone('Europe/Oslo');

    $dateTime = new DateTime('2024-09-25 15:00:00', new DateTimeZone('UTC'));

    $dateShort = $fmt->dateShort($dateTime);
    $dateLong = $fmt->dateLong($dateTime);
    $dateTimeResult = $fmt->dateTime($dateTime);
    $time = $fmt->time($dateTime);

    assertEqual(true, is_string($dateShort), "dateShort should work");
    assertEqual(true, is_string($dateLong), "dateLong should work");
    assertEqual(true, is_string($dateTimeResult), "dateTime should work");
    assertEqual(true, is_string($time), "time should work");

    // All should be non-empty
    assertEqual(true, strlen($dateShort) > 0, "dateShort should not be empty");
    assertEqual(true, strlen($dateLong) > 0, "dateLong should not be empty");
    assertEqual(true, strlen($dateTimeResult) > 0, "dateTime should not be empty");
    assertEqual(true, strlen($time) > 0, "time should not be empty");
});

// Test 8: Override doesn't affect user timezone
test("Override doesn't affect user timezone", function() {
    $fmt = new Fmt();
    $fmt->trySetUserTimezone('UTC');

    $timestamp = 1727276400;

    // Use override
    $osloResult = $fmt->dateTime($timestamp, 'Europe/Oslo');

    // Check that user timezone is still UTC
    assertEqual('UTC', $fmt->getUserTimezone(), "User timezone should remain UTC");

    // Next call without override should use UTC
    $utcResult = $fmt->dateTime($timestamp);
    assertEqual(false, $osloResult === $utcResult, "Override should not persist");
});

// Test 9: Invalid timezone override handling
test("Invalid timezone override handling", function() {
    $fmt = new Fmt();
    $fmt->trySetUserTimezone('UTC');

    $timestamp = 1727276400;

    try {
        $result = $fmt->dateTime($timestamp, 'Invalid/Timezone');
        throw new Exception("Should have thrown exception for invalid timezone");
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Should have thrown') !== false) {
            throw $e;
        }
        // Expected exception for invalid timezone
        assertEqual(true, true, "Correctly threw exception for invalid timezone");
    }
});

// Test 10: Complex real-world scenario
test("Complex real-world scenario", function() {
    $fmt = new Fmt();
    $fmt->trySetUserTimezone('America/New_York');

    // Different input types with timezone override
    $timestamp = 1727276400; // UTC timestamp
    $dateTime = new DateTime('2024-09-25 15:00:00', new DateTimeZone('UTC'));
    $dateTimeString = '2024-09-25 15:00:00';

    // All should produce the same result when converted to Oslo time
    $result1 = $fmt->dateTime($timestamp, 'Europe/Oslo');
    $result2 = $fmt->dateTime($dateTime, 'Europe/Oslo');
    $result3 = $fmt->dateTime($dateTimeString, 'Europe/Oslo');

    assertEqual($result1, $result2, "Timestamp and DateTime object should match");
    assertEqual($result1, $result3, "Timestamp and string should match");
});

// Test 11: Invalid string handling
test("Invalid string handling", function() {
    $fmt = new Fmt();
    $fmt->trySetUserTimezone('UTC');

    try {
        $result = $fmt->dateTime('not-a-date');
        throw new Exception("Should have thrown exception for invalid date string");
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Should have thrown') !== false) {
            throw $e;
        }
        // Expected exception
        assertEqual(true, true, "Correctly handled invalid date string");
    }
});

// Restore original timezone
date_default_timezone_set($originalTimezone);
if (session_status() === PHP_SESSION_ACTIVE) {
    unset($_SESSION['timezone']);
}

echo "\n✅ All enhanced Fmt API tests passed!\n";
echo "🚀 Enhanced API ready: DateTimeInterface support + timezone overrides!\n";