<?php
/**
 * Test Scoped service lifecycle and access control
 *
 * Tests: getRequestScope() behavior, scoped service access before/after bootstrap
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../assert.php';

use mini\Mini;
use mini\Lifetime;

$mini = Mini::$mini;

// Register a scoped service before bootstrap
$mini->addService('test.scoped', Lifetime::Scoped, fn() => new stdClass());

// Test: Accessing scoped service before bootstrap throws
assert_throws(
    fn() => $mini->get('test.scoped'),
    LogicException::class
);
echo "✓ Scoped service access throws before bootstrap\n";

// Test: getRequestScope() throws before bootstrap (not in fiber)
assert_throws(
    fn() => $mini->getRequestScope(),
    LogicException::class
);
echo "✓ getRequestScope() throws before bootstrap\n";

// Bootstrap
\mini\bootstrap();

// Test: getRequestScope() works after bootstrap
$scope = $mini->getRequestScope();
assert_not_null($scope);
assert_true(is_object($scope));
echo "✓ getRequestScope() returns object after bootstrap\n";

// Test: Scoped service works after bootstrap
$scoped = $mini->get('test.scoped');
assert_not_null($scoped);
echo "✓ Scoped service accessible after bootstrap\n";

// Test: Same scope object returned within request
$scope1 = $mini->getRequestScope();
$scope2 = $mini->getRequestScope();
assert_true($scope1 === $scope2, 'Should return same scope object');
echo "✓ getRequestScope() returns same object within request\n";

echo "\n✅ All scoped lifecycle tests passed!\n";
