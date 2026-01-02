<?php
/**
 * Tests that Fmt respects locale changes during execution
 *
 * This catches any internal caching that might cause stale formatting
 */

require __DIR__ . '/../../ensure-autoloader.php';
require_once __DIR__ . '/../assert.php';

use mini\I18n\Fmt;

$originalLocale = \Locale::getDefault();

$date = new DateTime('2024-12-25 14:30:00');
$number = 1234.56;

// Format in English
\Locale::setDefault('en_US');
$enNumber = Fmt::number($number, 2);
$enDate = Fmt::dateLong($date);
$enCurrency = Fmt::currency(19.99, 'USD');

// Switch to German and format same values
\Locale::setDefault('de_DE');
$deNumber = Fmt::number($number, 2);
$deDate = Fmt::dateLong($date);
$deCurrency = Fmt::currency(19.99, 'EUR');

// Verify they're different (locale was respected)
assert($enNumber !== $deNumber, "Number format should differ: en='$enNumber' de='$deNumber'");
assert($enDate !== $deDate, "Date format should differ: en='$enDate' de='$deDate'");

// Switch back to English - should match original
\Locale::setDefault('en_US');
$enNumber2 = Fmt::number($number, 2);
$enDate2 = Fmt::dateLong($date);

assert_eq($enNumber, $enNumber2, 'Switching back to en_US produces same number format');
assert_eq($enDate, $enDate2, 'Switching back to en_US produces same date format');

// Rapid switching test - format alternating between locales
$results = [];
for ($i = 0; $i < 5; $i++) {
    \Locale::setDefault('en_US');
    $results[] = ['en', Fmt::number($number, 2)];

    \Locale::setDefault('de_DE');
    $results[] = ['de', Fmt::number($number, 2)];
}

// Verify all English results match each other, all German match each other
$enResults = array_filter($results, fn($r) => $r[0] === 'en');
$deResults = array_filter($results, fn($r) => $r[0] === 'de');

$enValues = array_unique(array_column($enResults, 1));
$deValues = array_unique(array_column($deResults, 1));

assert_count(1, $enValues, 'All en_US results should be identical');
assert_count(1, $deValues, 'All de_DE results should be identical');
assert($enValues !== $deValues, 'en_US and de_DE should produce different results');

// Restore
\Locale::setDefault($originalLocale);

echo "✓ Fmt locale-switching tests passed\n";
