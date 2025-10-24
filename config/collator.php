<?php

/**
 * Collator factory configuration
 *
 * This file should return a configured Collator instance for the application.
 * The collator is used for consistent sorting and string comparisons.
 */

// Use the current PHP default locale set by bootstrap
// For SQLite compatibility, we may want to use POSIX collation for English
$currentLocale = \Locale::getDefault();
$locale = str_starts_with($currentLocale, 'en') ? 'en_US_POSIX' : $currentLocale;

$collator = new \Collator($locale);
$collator->setAttribute(\Collator::NUMERIC_COLLATION, \Collator::ON);

// Additional configuration can be added here:
// $collator->setAttribute(\Collator::CASE_FIRST, \Collator::UPPER_FIRST);

return $collator;