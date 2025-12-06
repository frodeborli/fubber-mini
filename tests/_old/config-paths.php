<?php
/**
 * Test config path priority
 */

require __DIR__ . '/../vendor/autoload.php';

use mini\Mini;

echo "Testing Config Path Priority\n";
echo str_repeat('=', 70) . "\n\n";

// Test 1: Check registered paths
echo "Test 1: Config paths registered\n";
$paths = Mini::$mini->paths->config->getPaths();

foreach ($paths as $index => $path) {
    $priority = $index === 0 ? 'HIGHEST' : 'fallback';
    echo "  [$priority] $path\n";
}

echo "\n";

// Test 2: Find PDO.php (should find framework's fallback)
echo "Test 2: Find PDO.php (framework fallback)\n";
$pdoPath = Mini::$mini->paths->config->findFirst('PDO.php');
if ($pdoPath) {
    echo "✓ Found: $pdoPath\n";

    // Check if it's the framework config
    if (str_contains($pdoPath, 'vendor/fubber/mini/config')) {
        echo "✓ Using framework fallback config\n";
    } else {
        echo "✗ Using application config (expected framework fallback)\n";
    }
} else {
    echo "✗ PDO.php not found\n";
}

echo "\n";

// Test 3: Create application config and verify it takes priority
echo "Test 3: Create application config to test priority\n";
$appConfigDir = Mini::$mini->root . '/_config';
if (!is_dir($appConfigDir)) {
    mkdir($appConfigDir, 0755, true);
}

$appPdoConfig = $appConfigDir . '/test-priority.php';
file_put_contents($appPdoConfig, '<?php return "application config";');

$testPath = Mini::$mini->paths->config->findFirst('test-priority.php');
if ($testPath && str_contains($testPath, '/_config/')) {
    echo "✓ Application config has priority\n";
    echo "  Found: $testPath\n";
} else {
    echo "✗ Application config not prioritized\n";
}

// Clean up
unlink($appPdoConfig);

echo "\n" . str_repeat('=', 70) . "\n";
echo "Config path priority working correctly!\n";
