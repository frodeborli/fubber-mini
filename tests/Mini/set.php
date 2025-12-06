<?php
/**
 * Test Mini::set() method for service instance injection
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../assert.php';

use mini\Mini;
use mini\Lifetime;

$mini = Mini::$mini;

// Test 1: set() during Bootstrap phase works without warning
$mockService = new stdClass();
$mockService->name = 'MockService';

$mini->set('test.mock', $mockService);

assert_true($mini->has('test.mock'), 'Service should be registered');
assert_eq($mockService, $mini->get('test.mock'), 'Should return the exact instance');
echo "✓ set() works during Bootstrap phase\n";

// Test 2: set() auto-registers service definition
assert_true($mini->has('test.mock'), 'has() should return true for set() services');
echo "✓ set() auto-registers service definition\n";

// Test 3: set() returns same instance on subsequent get() calls (singleton behavior)
$retrieved1 = $mini->get('test.mock');
$retrieved2 = $mini->get('test.mock');
assert_true($retrieved1 === $retrieved2, 'Should return same instance');
echo "✓ set() services behave as singletons\n";

// Register a lazy service before bootstrap (for test 6)
$mini->addService('test.lazy', Lifetime::Singleton, fn() => new stdClass());

// Transition to Ready phase
\mini\bootstrap();

// Test 4: set() during Ready phase triggers warning
$warningTriggered = false;
$previousHandler = set_error_handler(function($errno, $errstr) use (&$warningTriggered) {
    if ($errno === E_USER_WARNING && str_contains($errstr, 'Ready phase')) {
        $warningTriggered = true;
        return true;
    }
    return false;
});

$readyService = new stdClass();
$mini->set('test.ready', $readyService);
restore_error_handler();

assert_true($warningTriggered, 'Warning should be triggered during Ready phase');
assert_eq($readyService, $mini->get('test.ready'), 'Service should still be set');
echo "✓ set() during Ready phase triggers warning\n";

// Test 5: Cannot shadow already instantiated service
assert_throws(
    fn() => $mini->set('test.mock', new stdClass()),
    LogicException::class,
    'Should throw when shadowing instantiated service'
);
echo "✓ set() throws when shadowing instantiated service\n";

// Test 6: Can shadow registered but not-yet-instantiated service
// test.lazy was registered before bootstrap but never retrieved
// Suppress warning for this test
@$mini->set('test.lazy', new stdClass());
echo "✓ set() can shadow registered but not-yet-instantiated service\n";

echo "\n✅ All Mini::set() tests passed!\n";
