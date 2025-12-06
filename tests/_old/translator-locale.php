<?php

/**
 * Test that Translator respects Locale::setDefault() changes
 */

require_once __DIR__ . '/../vendor/autoload.php';

use mini\Mini;
use mini\I18n\Translator;
use function mini\t;

echo "Testing Translator Locale Switching\n";
echo "====================================\n\n";

// Get the singleton translator
$translator = Mini::$mini->get(Translator::class);

// Test 1: Translator reads current locale
\Locale::setDefault('en_US');
echo "✓ Set locale to en_US\n";
echo "  Current language: " . $translator->getLanguageCode() . "\n";
assert($translator->getLanguageCode() === 'en', "Should be 'en'");

// Test 2: Changing locale affects translator
\Locale::setDefault('nb_NO');
echo "✓ Set locale to nb_NO\n";
echo "  Current language: " . $translator->getLanguageCode() . "\n";
assert($translator->getLanguageCode() === 'nb', "Should be 'nb'");

// Test 3: Singleton is reused
$translator2 = Mini::$mini->get(Translator::class);
assert($translator === $translator2, "Should be same instance");
echo "✓ Translator is singleton (same instance)\n";

// Test 4: setLanguageCode updates global locale
$translator->setLanguageCode('de');
echo "✓ Called setLanguageCode('de')\n";
echo "  Global locale: " . \Locale::getDefault() . "\n";
echo "  Language code: " . $translator->getLanguageCode() . "\n";
assert($translator->getLanguageCode() === 'de', "Should be 'de'");
assert(str_starts_with(\Locale::getDefault(), 'de'), "Global locale should start with 'de'");

// Test 5: Multiple locale switches
\Locale::setDefault('fr_FR');
echo "✓ Set locale to fr_FR\n";
echo "  Current language: " . $translator->getLanguageCode() . "\n";
assert($translator->getLanguageCode() === 'fr', "Should be 'fr'");

echo "\n✅ All locale switching tests passed!\n";
echo "\nKey insight: Translator is now Lifetime::Singleton because it reads\n";
echo "locale dynamically from \\Locale::getDefault() instead of storing it.\n";
echo "Translation file cache is shared across all requests! 🎉\n";
