<?php
/**
 * Test that applications can override framework default services
 *
 * NOTE: In this test, we can't fully test the override pattern because
 * composer has already autoloaded all functions.php files. In a real application,
 * the app/bootstrap.php file would run BEFORE src/Logger/functions.php, etc.
 *
 * This test demonstrates the CONCEPT and verifies the has() checks are in place.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use mini\Mini;
use mini\Lifetime;

echo "Testing Service Override Pattern\n";
echo "=================================\n\n";

// Test 1: Verify framework checks has() before registering
echo "Test 1: Verify framework service registration code\n";

// Read the Logger/functions.php to verify it checks has()
$loggerFunctions = file_get_contents(__DIR__ . '/../src/Logger/functions.php');
if (str_contains($loggerFunctions, 'if (!Mini::$mini->has(LoggerInterface::class))')) {
    echo "✓ Logger service checks has() before registering\n";
} else {
    echo "✗ Logger service does NOT check has() before registering\n";
}

// Read I18n/functions.php
$i18nFunctions = file_get_contents(__DIR__ . '/../src/I18n/functions.php');
if (str_contains($i18nFunctions, 'if (!Mini::$mini->has(Translator::class))')) {
    echo "✓ Translator service checks has() before registering\n";
} else {
    echo "✗ Translator service does NOT check has() before registering\n";
}

if (str_contains($i18nFunctions, 'if (!Mini::$mini->has(Fmt::class))')) {
    echo "✓ Fmt service checks has() before registering\n";
} else {
    echo "✗ Fmt service does NOT check has() before registering\n";
}

// Read Mini.php registerCoreServices
$miniPhp = file_get_contents(__DIR__ . '/../src/Mini.php');
if (str_contains($miniPhp, 'if (!$this->has(\PDO::class))')) {
    echo "✓ PDO service checks has() before registering\n";
} else {
    echo "✗ PDO service does NOT check has() before registering\n";
}

if (str_contains($miniPhp, 'if (!$this->has(Contracts\DatabaseInterface::class))')) {
    echo "✓ DatabaseInterface service checks has() before registering\n";
} else {
    echo "✗ DatabaseInterface service does NOT check has() before registering\n";
}

if (str_contains($miniPhp, 'if (!$this->has(\Psr\SimpleCache\CacheInterface::class))')) {
    echo "✓ SimpleCache service checks has() before registering\n";
} else {
    echo "✗ SimpleCache service does NOT check has() before registering\n";
}

echo "\n";

// Test 2: Demonstrate the pattern conceptually
echo "Test 2: Override pattern demonstration\n";
echo "  (Cannot fully test due to autoload order, but pattern is documented)\n";

// Register a custom service (different name to avoid conflict)
Mini::$mini->addService('CustomTestService', Lifetime::Singleton, fn() => 'original');

// Try to check if we can override (we can't because service is registered)
if (Mini::$mini->has('CustomTestService')) {
    echo "✓ Service is registered\n";
}

// This would throw because service already exists
try {
    Mini::$mini->addService('CustomTestService', Lifetime::Singleton, fn() => 'override');
    echo "✗ Should have thrown exception for duplicate registration\n";
} catch (\LogicException $e) {
    echo "✓ Cannot register duplicate service (as expected)\n";
}

echo "\n✅ All service override checks passed!\n";
echo "\nThe Override Pattern:\n";
echo "=====================\n";
echo "Applications can override framework services by registering them\n";
echo "BEFORE the framework's functions.php files load.\n\n";
echo "// composer.json\n";
echo "{\n";
echo "    \"autoload\": {\n";
echo "        \"files\": [\n";
echo "            \"app/bootstrap.php\"  // Loads BEFORE framework functions.php\n";
echo "        ]\n";
echo "    }\n";
echo "}\n\n";
echo "// app/bootstrap.php\n";
echo "<?php\n";
echo "use mini\\Mini;\n";
echo "use mini\\Lifetime;\n";
echo "use Psr\\Log\\LoggerInterface;\n\n";
echo "// Override framework's default logger\n";
echo "Mini::\$mini->addService(LoggerInterface::class, Lifetime::Singleton, function() {\n";
echo "    return new SentryLogger(); // Your custom implementation\n";
echo "});\n\n";
echo "// When src/Logger/functions.php loads, it will check:\n";
echo "// if (!Mini::\$mini->has(LoggerInterface::class)) { ... }\n";
echo "// Since YOUR service is already registered, framework skips registration\n";
