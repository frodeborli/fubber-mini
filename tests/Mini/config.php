<?php
/**
 * Test Mini configuration loading
 *
 * Tests: loadConfig(), loadServiceConfig() methods
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../assert.php';

use mini\Mini;

$mini = Mini::$mini;

// Bootstrap needed for some operations
\mini\bootstrap();

// Test: loadConfig() with default value for missing file
$result = $mini->loadConfig('nonexistent-file.php', 'default-value');
assert_eq('default-value', $result);
echo "✓ loadConfig() returns default for missing file\n";

// Test: loadConfig() with null default
$result = $mini->loadConfig('nonexistent-file.php', null);
assert_null($result);
echo "✓ loadConfig() returns null default for missing file\n";

// Test: loadConfig() throws without default for missing file
assert_throws(
    fn() => $mini->loadConfig('nonexistent-file.php'),
    Exception::class
);
echo "✓ loadConfig() throws for missing file without default\n";

// Test: loadServiceConfig() converts class name to path
$result = $mini->loadServiceConfig('NonExistent\\Service\\Class', 'default');
assert_eq('default', $result);
echo "✓ loadServiceConfig() returns default for missing service config\n";

// Test: loadServiceConfig() throws without default
assert_throws(
    fn() => $mini->loadServiceConfig('NonExistent\\Service\\Class'),
    Exception::class
);
echo "✓ loadServiceConfig() throws for missing config without default\n";

// Test: paths registry is accessible
assert_not_null($mini->paths);
assert_not_null($mini->paths->config);
echo "✓ paths registry is accessible\n";

echo "\n✅ All config tests passed!\n";
