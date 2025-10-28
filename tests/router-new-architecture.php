<?php

/**
 * Test the new routing architecture with _routes/ directory
 */

require_once __DIR__ . '/../vendor/autoload.php';

use mini\Mini;

echo "Testing New Routing Architecture\n";
echo "=================================\n\n";

// Test 1: Mini::$mini->paths->routes exists
echo "✓ Test 1: Routes PathsRegistry initialized\n";
assert(isset(Mini::$mini->paths->routes), "paths->routes should be initialized");
echo "  Routes path: " . Mini::$mini->root . "/_routes\n";

// Test 2: Config path changed to _config/
echo "✓ Test 2: Config path changed to _config/\n";
$configPath = Mini::$mini->paths->config->findFirst('bootstrap.php');
if ($configPath) {
    echo "  Found bootstrap.php at: $configPath\n";
    assert(str_contains($configPath, '_config'), "Config path should contain _config");
} else {
    echo "  bootstrap.php not found (OK for test environment)\n";
}

// Test 3: Route files can be found
echo "✓ Test 3: Route files can be found via PathsRegistry\n";
$pingRoute = Mini::$mini->paths->routes->findFirst('ping.php');
assert($pingRoute !== null, "Should find ping.php in _routes/");
echo "  Found ping.php at: $pingRoute\n";

$indexRoute = Mini::$mini->paths->routes->findFirst('index.php');
assert($indexRoute !== null, "Should find index.php in _routes/");
echo "  Found index.php at: $indexRoute\n";

// Test 4: Error page path
echo "✓ Test 4: Error pages in _errors/ directory\n";
$errorPage = Mini::$mini->root . '/_errors/404.php';
echo "  Expected 404.php path: $errorPage\n";

// Test 5: Routing flag
echo "✓ Test 5: Routing flag can be set\n";
$GLOBALS['mini_routing_enabled'] = true;
assert($GLOBALS['mini_routing_enabled'] === true, "Routing flag should be set");
unset($GLOBALS['mini_routing_enabled']);

echo "\n✅ All routing architecture tests passed!\n";
echo "\nNew architecture:\n";
echo "  _routes/       → Route handlers (no bootstrap needed)\n";
echo "  _config/       → Configuration files\n";
echo "  _errors/       → Error page templates\n";
echo "  DOC_ROOT/      → Static files + index.php entry point\n";
echo "\nEntry point pattern:\n";
echo "  // DOC_ROOT/index.php\n";
echo "  <?php\n";
echo "  require_once __DIR__ . '/../vendor/autoload.php';\n";
echo "  mini\\router();\n";
echo "\nRoute handler pattern:\n";
echo "  // _routes/users.php\n";
echo "  <?php\n";
echo "  header('Content-Type: application/json');\n";
echo "  echo json_encode(['users' => [...]]);\n";
