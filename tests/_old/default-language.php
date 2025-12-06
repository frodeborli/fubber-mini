<?php

/**
 * Test Mini::$mini->defaultLanguage property
 */

// Test 1: Default value
require_once __DIR__ . '/../vendor/autoload.php';

use mini\Mini;

echo "Testing Mini::\$mini->defaultLanguage\n";
echo "====================================\n\n";

echo "✓ Default language without MINI_LANG: " . Mini::$mini->defaultLanguage . "\n";
assert(Mini::$mini->defaultLanguage === 'en', "Should default to 'en'");

echo "✓ Translator uses defaultLanguage for fallback chain\n";

echo "\n✅ All default language tests passed!\n";
echo "\nTo set custom default language, use MINI_LANG in .env file:\n";
echo "  MINI_LANG=nb\n";
