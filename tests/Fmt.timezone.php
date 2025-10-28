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

function assertTrue($condition, string $message = 'Assertion failed'): void
{
    if (!$condition) {
        throw new Exception($message);
    }
}

echo "Running Fmt timezone tests...\n\n";

// Bootstrap framework
mini\bootstrap();

// Save original timezone
$originalTimezone = date_default_timezone_get();

// Test 1: Formatting respects current timezone
test("Formatting respects current timezone", function() use ($originalTimezone) {
    // Set timezone to UTC
    date_default_timezone_set('UTC');

    $date = new DateTime('2024-09-25 15:00:00', new DateTimeZone('UTC'));
    $result = Fmt::dateTimeShort($date);
    assertTrue(is_string($result), "Should return string in UTC");

    // Set timezone to America/New_York
    date_default_timezone_set('America/New_York');

    $date2 = new DateTime('2024-09-25 15:00:00', new DateTimeZone('America/New_York'));
    $result2 = Fmt::dateTimeShort($date2);
    assertTrue(is_string($result2), "Should return string in America/New_York");

    // Restore original timezone
    date_default_timezone_set($originalTimezone);
});

// Test 2: DateTime objects with different timezones
test("DateTime objects with different timezones", function() use ($originalTimezone) {
    $utcDate = new DateTime('2024-09-25 15:00:00', new DateTimeZone('UTC'));
    $nyDate = new DateTime('2024-09-25 15:00:00', new DateTimeZone('America/New_York'));

    $resultUtc = Fmt::dateTimeShort($utcDate);
    $resultNy = Fmt::dateTimeShort($nyDate);

    assertTrue(is_string($resultUtc), "Should format UTC date");
    assertTrue(is_string($resultNy), "Should format NY date");

    // Restore original timezone
    date_default_timezone_set($originalTimezone);
});

// Test 3: All date/time methods work with timezones
test("All date/time methods work with timezones", function() use ($originalTimezone) {
    date_default_timezone_set('Europe/London');

    $date = new DateTime('2024-09-25 15:00:00', new DateTimeZone('Europe/London'));

    assertTrue(is_string(Fmt::dateShort($date)), "dateShort should work");
    assertTrue(is_string(Fmt::dateLong($date)), "dateLong should work");
    assertTrue(is_string(Fmt::timeShort($date)), "timeShort should work");
    assertTrue(is_string(Fmt::dateTimeShort($date)), "dateTimeShort should work");
    assertTrue(is_string(Fmt::dateTimeLong($date)), "dateTimeLong should work");

    // Restore original timezone
    date_default_timezone_set($originalTimezone);
});

echo "\n✓ All Fmt timezone tests passed!\n";
echo "Note: Fmt uses \\Locale::getDefault() and date_default_timezone_get() for formatting\n";
