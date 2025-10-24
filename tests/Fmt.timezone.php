<?php

/**
 * Test runner for timezone functionality in Fmt class
 *
 * Usage: php mini/tests/Fmt.timezone.php
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

/**
 * Create a test timestamp (2024-09-25 15:30:00 UTC)
 */
function getTestTimestamp(): int
{
    return mktime(15, 30, 0, 9, 25, 2024); // Uses current timezone
}

echo "Running Fmt timezone tests...\n\n";

// Save original timezone to restore later
$originalTimezone = date_default_timezone_get();

// Test 1: Valid timezone setting
test("Valid timezone setting", function() {
    $fmt = new Fmt();

    $result = $fmt->trySetUserTimezone('Europe/Oslo');
    assertEqual(true, $result, "Should return true for valid timezone");
    assertEqual('Europe/Oslo', $fmt->getUserTimezone(), "Should store timezone correctly");
    assertEqual('Europe/Oslo', date_default_timezone_get(), "Should set default timezone globally");
});

// Test 2: Invalid timezone handling
test("Invalid timezone handling", function() {
    $fmt = new Fmt();

    $result = $fmt->trySetUserTimezone('Invalid/Timezone');
    assertEqual(false, $result, "Should return false for invalid timezone");
});

// Test 3: Timezone affects date formatting
test("Timezone affects date formatting", function() {
    $fmt = new Fmt();
    $timestamp = 1727276400; // 2024-09-25 15:00:00 UTC

    // Set to UTC
    $fmt->trySetUserTimezone('UTC');
    $utcTime = $fmt->dateTime($timestamp);

    // Set to Oslo (UTC+2 in summer)
    $fmt->trySetUserTimezone('Europe/Oslo');
    $osloTime = $fmt->dateTime($timestamp);

    // Times should be different (Oslo should be +2 hours)
    assertEqual(false, $utcTime === $osloTime, "UTC and Oslo times should be different");
});

// Test 4: Session persistence
test("Session persistence", function() {
    // Start session for testing (suppress warnings in test mode)
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }

    $fmt1 = new Fmt();
    $fmt1->trySetUserTimezone('America/New_York');

    // Create new instance - should pick up timezone from session
    $fmt2 = new Fmt();
    assertEqual('America/New_York', $fmt2->getUserTimezone(), "New instance should pick up timezone from session");

    // Clean up session
    unset($_SESSION['timezone']);
});

// Test 5: Default timezone when none set
test("Default timezone when none set", function() {
    // Clear any session data
    if (session_status() === PHP_SESSION_ACTIVE) {
        unset($_SESSION['timezone']);
    }

    $fmt = new Fmt();
    $currentDefault = date_default_timezone_get();
    assertEqual($currentDefault, $fmt->getUserTimezone(), "Should return current default timezone when none set");
});

// Test 6: Common timezone scenarios
test("Common timezone scenarios", function() {
    $fmt = new Fmt();
    $timestamp = 1727276400; // 2024-09-25 15:00:00 UTC

    $timezones = [
        'UTC' => 'UTC',
        'Europe/Oslo' => 'Europe/Oslo',
        'America/New_York' => 'America/New_York',
        'Asia/Tokyo' => 'Asia/Tokyo',
        'Australia/Sydney' => 'Australia/Sydney'
    ];

    foreach ($timezones as $tz => $expected) {
        $result = $fmt->trySetUserTimezone($tz);
        assertEqual(true, $result, "Should accept common timezone: $tz");
        assertEqual($expected, $fmt->getUserTimezone(), "Should store timezone: $tz");
    }
});

// Test 7: Timezone with different date formats
test("Timezone with different date formats", function() {
    $fmt = new Fmt();
    $timestamp = 1727276400; // 2024-09-25 15:00:00 UTC

    // Test with Oslo timezone
    $fmt->trySetUserTimezone('Europe/Oslo');

    $dateShort = $fmt->dateShort($timestamp);
    $dateLong = $fmt->dateLong($timestamp);
    $dateTime = $fmt->dateTime($timestamp);
    $time = $fmt->time($timestamp);

    // All should be strings (basic smoke test)
    assertEqual(true, is_string($dateShort), "dateShort should return string");
    assertEqual(true, is_string($dateLong), "dateLong should return string");
    assertEqual(true, is_string($dateTime), "dateTime should return string");
    assertEqual(true, is_string($time), "time should return string");

    // Basic format checks (should not be empty)
    assertEqual(true, strlen($dateShort) > 0, "dateShort should not be empty");
    assertEqual(true, strlen($dateLong) > 0, "dateLong should not be empty");
    assertEqual(true, strlen($dateTime) > 0, "dateTime should not be empty");
    assertEqual(true, strlen($time) > 0, "time should not be empty");
});

// Test 8: String timestamp handling with timezone
test("String timestamp handling with timezone", function() {
    $fmt = new Fmt();
    $fmt->trySetUserTimezone('Europe/Oslo');

    // Test with string timestamp
    $dateTime1 = $fmt->dateTime('2024-09-25 15:00:00');
    $dateTime2 = $fmt->dateTime(strtotime('2024-09-25 15:00:00'));

    // Both should produce valid strings
    assertEqual(true, is_string($dateTime1), "String timestamp should work");
    assertEqual(true, is_string($dateTime2), "Integer timestamp should work");
});

// Test 9: Multiple Fmt instances share timezone
test("Multiple Fmt instances share timezone", function() {
    $fmt1 = new Fmt();
    $fmt1->trySetUserTimezone('Pacific/Auckland');

    $fmt2 = new Fmt();
    // fmt2 should see the global timezone change
    assertEqual('Pacific/Auckland', date_default_timezone_get(), "Global timezone should be set");
    assertEqual('Pacific/Auckland', $fmt2->getUserTimezone(), "New instance should see global timezone");
});

// Restore original timezone
date_default_timezone_set($originalTimezone);
if (session_status() === PHP_SESSION_ACTIVE) {
    unset($_SESSION['timezone']);
}

echo "\n✅ All Fmt timezone tests passed!\n";
echo "🌍 Timezone support ready for enterprise-grade datetime handling\n";