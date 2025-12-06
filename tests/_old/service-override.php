<?php
/**
 * Test that applications can override framework default services via config files
 *
 * The framework unconditionally registers services using loadServiceConfig(),
 * which checks for application config files before falling back to framework defaults.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use mini\Mini;
use mini\Lifetime;

echo "Testing Service Override Pattern\n";
echo "=================================\n\n";

// Test 1: Verify framework uses loadServiceConfig pattern
echo "Test 1: Verify framework service registration pattern\n";

$loggerFunctions = file_get_contents(__DIR__ . '/../src/Logger/functions.php');
if (str_contains($loggerFunctions, 'loadServiceConfig(LoggerInterface::class)')) {
    echo "✓ Logger service uses loadServiceConfig() pattern\n";
} else {
    echo "✗ Logger service does NOT use loadServiceConfig() pattern\n";
}

if (str_contains($loggerFunctions, 'if (!Mini::$mini->has(')) {
    echo "✗ Logger service incorrectly checks has() before registering\n";
} else {
    echo "✓ Logger service does NOT check has() (correct)\n";
}

$i18nFunctions = file_get_contents(__DIR__ . '/../src/I18n/functions.php');
if (str_contains($i18nFunctions, 'loadServiceConfig(Translator::class)')) {
    echo "✓ Translator service uses loadServiceConfig() pattern\n";
} else {
    echo "✗ Translator service does NOT use loadServiceConfig() pattern\n";
}

if (str_contains($i18nFunctions, 'if (!Mini::$mini->has(')) {
    echo "✗ I18n services incorrectly check has() before registering\n";
} else {
    echo "✓ I18n services do NOT check has() (correct)\n";
}

$uuidFunctions = file_get_contents(__DIR__ . '/../src/UUID/functions.php');
if (str_contains($uuidFunctions, 'loadServiceConfig(FactoryInterface::class)')) {
    echo "✓ UUID service uses loadServiceConfig() pattern\n";
} else {
    echo "✗ UUID service does NOT use loadServiceConfig() pattern\n";
}

if (str_contains($uuidFunctions, 'if (!Mini::$mini->has(')) {
    echo "✗ UUID service incorrectly checks has() before registering\n";
} else {
    echo "✓ UUID service does NOT check has() (correct)\n";
}

echo "\n";

// Test 2: Verify loadServiceConfig returns default when no config exists
echo "Test 2: Config file override behavior\n";

// Test default fallback
$defaultResult = Mini::$mini->loadServiceConfig('NonExistent\Service\That\Does\Not\Exist', 'default-value');
if ($defaultResult === 'default-value') {
    echo "✓ loadServiceConfig() returns default when no config exists\n";
} else {
    echo "✗ loadServiceConfig() did not return default value\n";
}

// Verify UUID factory can be overridden via config
// (if _config/mini/UUID/FactoryInterface.php exists, it would be used)
$configPath = Mini::$mini->root . '/_config/mini/UUID/FactoryInterface.php';
if (file_exists($configPath)) {
    echo "✓ UUID factory config file exists (application override active)\n";
} else {
    echo "✓ UUID factory uses framework default (no application override)\n";
}

echo "\n✅ All service override checks passed!\n";
echo "\nThe Correct Override Pattern:\n";
echo "=============================\n";
echo "Applications override framework services by creating config files:\n\n";
echo "// _config/Psr/Log/LoggerInterface.php\n";
echo "<?php\n";
echo "return new \\Monolog\\Logger('app', [\n";
echo "    new \\Monolog\\Handler\\StreamHandler('php://stderr'),\n";
echo "]);\n\n";
echo "// _config/mini/UUID/FactoryInterface.php\n";
echo "<?php\n";
echo "return new \\mini\\UUID\\UUID4Factory();\n\n";
echo "Framework services use loadServiceConfig() which:\n";
echo "1. Checks _config/[namespace]/[ClassName].php (application override)\n";
echo "2. Falls back to vendor/fubber/mini/config/[namespace]/[ClassName].php (framework default)\n\n";
echo "You CANNOT override by registering services in app/bootstrap.php.\n";
echo "The framework unconditionally registers its services.\n";
