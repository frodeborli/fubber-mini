<?php
/**
 * Tests for mini\I18n\Fmt - verifies locale-aware formatting
 */

require __DIR__ . '/../../ensure-autoloader.php';
require_once __DIR__ . '/../assert.php';

use mini\I18n\Fmt;

$originalLocale = \Locale::getDefault();

// --- Number formatting ---

\Locale::setDefault('en_US');
$result = Fmt::number(1234.56, 2);
assert_eq('1,234.56', $result, 'en_US uses comma as thousands separator');

\Locale::setDefault('de_DE');
$result = Fmt::number(1234.56, 2);
// German uses period for thousands, comma for decimals
assert(str_contains($result, '1.234') || str_contains($result, '1 234'), "de_DE: got $result");
assert(str_contains($result, ',56') || str_contains($result, '.56'), "de_DE decimals: got $result");

\Locale::setDefault('nb_NO');
$result = Fmt::number(1234.56, 2);
// Norwegian uses space for thousands, comma for decimals
assert(str_contains($result, ',56'), "nb_NO uses comma for decimals: got $result");

// --- Currency formatting ---

\Locale::setDefault('en_US');
$result = Fmt::currency(19.99, 'USD');
assert(str_contains($result, '$'), "en_US USD has dollar sign: got $result");
assert(str_contains($result, '19.99') || str_contains($result, '19,99'), "en_US USD amount: got $result");

\Locale::setDefault('de_DE');
$result = Fmt::currency(19.99, 'EUR');
assert(str_contains($result, '€'), "de_DE EUR has euro sign: got $result");
assert(str_contains($result, '19,99'), "de_DE uses comma for decimals: got $result");

\Locale::setDefault('nb_NO');
$result = Fmt::currency(19.99, 'NOK');
assert(str_contains($result, 'kr') || str_contains($result, 'NOK'), "nb_NO NOK: got $result");

// --- Percent formatting ---

\Locale::setDefault('en_US');
$result = Fmt::percent(0.75, 0);
assert(str_contains($result, '75'), "percent contains 75: got $result");
assert(str_contains($result, '%'), "percent has % symbol: got $result");

// --- Date formatting ---

$date = new DateTime('2024-12-25 14:30:00');

\Locale::setDefault('en_US');
$short = Fmt::dateShort($date);
// US format: M/D/YY or similar
assert(str_contains($short, '12') && str_contains($short, '25'), "en_US dateShort: got $short");

$long = Fmt::dateLong($date);
assert(str_contains($long, 'December') || str_contains($long, 'Dec'), "en_US dateLong has month name: got $long");
assert(str_contains($long, '25'), "en_US dateLong has day: got $long");
assert(str_contains($long, '2024'), "en_US dateLong has year: got $long");

\Locale::setDefault('de_DE');
$short = Fmt::dateShort($date);
// German format: DD.MM.YY
assert(str_contains($short, '25') && str_contains($short, '12'), "de_DE dateShort: got $short");

$long = Fmt::dateLong($date);
assert(str_contains($long, 'Dezember') || str_contains($long, 'Dez'), "de_DE dateLong: got $long");

\Locale::setDefault('nb_NO');
$long = Fmt::dateLong($date);
assert(str_contains($long, 'desember') || str_contains($long, 'des'), "nb_NO dateLong: got $long");

// --- Time formatting ---

\Locale::setDefault('en_US');
$time = Fmt::timeShort($date);
// US typically uses 12-hour with AM/PM
assert(str_contains($time, '2:30') || str_contains($time, '14:30'), "en_US timeShort: got $time");

\Locale::setDefault('de_DE');
$time = Fmt::timeShort($date);
// German uses 24-hour
assert(str_contains($time, '14:30') || str_contains($time, '14.30'), "de_DE timeShort: got $time");

// --- File size formatting (uses locale for number formatting) ---

\Locale::setDefault('en_US');
$size = Fmt::fileSize(1536);
assert(str_contains($size, '1.5') || str_contains($size, '1,5'), "fileSize formats number: got $size");
assert(str_contains($size, 'KB'), "fileSize has unit: got $size");

// --- DateTime short formatting ---

\Locale::setDefault('en_US');
$dtShort = Fmt::dateTimeShort($date);
// Should contain both date and time parts
assert(str_contains($dtShort, '12') && str_contains($dtShort, '25'), "en_US dateTimeShort has date: got $dtShort");
assert(str_contains($dtShort, '2:30') || str_contains($dtShort, '14:30'), "en_US dateTimeShort has time: got $dtShort");

\Locale::setDefault('de_DE');
$dtShort = Fmt::dateTimeShort($date);
assert(str_contains($dtShort, '25') && str_contains($dtShort, '12'), "de_DE dateTimeShort has date: got $dtShort");
assert(str_contains($dtShort, '14:30') || str_contains($dtShort, '14.30'), "de_DE dateTimeShort has time: got $dtShort");

// --- DateTime long formatting ---

\Locale::setDefault('en_US');
$dtLong = Fmt::dateTimeLong($date);
assert(str_contains($dtLong, 'December') || str_contains($dtLong, 'Dec'), "en_US dateTimeLong has month: got $dtLong");
assert(str_contains($dtLong, '2024'), "en_US dateTimeLong has year: got $dtLong");

\Locale::setDefault('de_DE');
$dtLong = Fmt::dateTimeLong($date);
assert(str_contains($dtLong, 'Dezember') || str_contains($dtLong, 'Dez'), "de_DE dateTimeLong has month: got $dtLong");

// Restore
\Locale::setDefault($originalLocale);

echo "✓ Fmt locale-aware formatting tests passed\n";
