<?php
/**
 * Test PSR-11 Container Implementation in Mini
 *
 * Tests: addService(), get(), has() methods and Lifetime behaviors
 */

require __DIR__ . '/../../ensure-autoloader.php';
require_once __DIR__ . '/../assert.php';

use mini\Mini;
use mini\Lifetime;

$mini = Mini::$mini;

// Register test services BEFORE bootstrap (while in Bootstrap phase)
$mini->addService('test.singleton', Lifetime::Singleton, fn() => new stdClass());
$mini->addService('test.transient', Lifetime::Transient, fn() => new stdClass());
$mini->addService('test.scoped', Lifetime::Scoped, fn() => new stdClass());
$mini->addService('test.with-mini', Lifetime::Singleton, fn() => Mini::$mini);

// Test: Cannot register duplicate service
assert_throws(
    fn() => $mini->addService('test.singleton', Lifetime::Singleton, fn() => new stdClass()),
    LogicException::class
);
echo "✓ addService() throws on duplicate registration\n";

// Bootstrap framework (required for Scoped services)
\mini\bootstrap();

// Test: Cannot register services after bootstrap
assert_throws(
    fn() => $mini->addService('test.new', Lifetime::Singleton, fn() => new stdClass()),
    LogicException::class
);
echo "✓ addService() throws after bootstrap (Ready phase)\n";

// Test: Singleton returns same instance
$singleton1 = $mini->get('test.singleton');
$singleton2 = $mini->get('test.singleton');
assert_true($singleton1 === $singleton2, 'Singleton should return same instance');
echo "✓ Singleton returns same instance\n";

// Test: Transient returns new instance each time
$transient1 = $mini->get('test.transient');
$transient2 = $mini->get('test.transient');
assert_false($transient1 === $transient2, 'Transient should return different instances');
echo "✓ Transient returns new instance each time\n";

// Test: Scoped returns same instance within request
$scoped1 = $mini->get('test.scoped');
$scoped2 = $mini->get('test.scoped');
assert_true($scoped1 === $scoped2, 'Scoped should return same instance in same request');
echo "✓ Scoped returns same instance within request\n";

// Test: has() returns true for registered service
assert_true($mini->has('test.singleton'));
echo "✓ has() returns true for registered service\n";

// Test: has() returns false for unregistered service
assert_false($mini->has('nonexistent.service'));
echo "✓ has() returns false for unregistered service\n";

// Test: get() throws NotFoundException for unregistered service
assert_throws(
    fn() => $mini->get('nonexistent.service'),
    Psr\Container\NotFoundExceptionInterface::class
);
echo "✓ get() throws NotFoundException for unregistered service\n";

// Test: Factory can access Mini instance
$result = $mini->get('test.with-mini');
assert_true($result === Mini::$mini);
echo "✓ Factory closure can access Mini::\$mini\n";

echo "\n✅ All container tests passed!\n";
