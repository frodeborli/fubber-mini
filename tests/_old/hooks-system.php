<?php
/**
 * Test the hooks/events system
 */

require_once __DIR__ . '/../vendor/autoload.php';

use mini\Mini;
use mini\Hooks\Event;
use mini\Hooks\Trigger;
use mini\Hooks\Filter;
use mini\Hooks\Handler;

echo "Testing Hooks System\n";
echo "===================\n\n";

// Test 1: Event (can trigger multiple times)
echo "✓ Test 1: Event - multiple triggers\n";
$event = new Event('test-event');
$callCount = 0;
$event->listen(function() use (&$callCount) {
    $callCount++;
});

$event->trigger();
$event->trigger();
assert($callCount === 2, "Event should fire twice");
echo "  Event fired {$callCount} times\n";

// Test 2: Trigger (fires only once)
echo "✓ Test 2: Trigger - single fire\n";
$trigger = new Trigger('test-trigger');
$triggerCallCount = 0;
$trigger->listen(function() use (&$triggerCallCount) {
    $triggerCallCount++;
});

$trigger->trigger('data');
assert($triggerCallCount === 1, "Trigger should fire once");

try {
    $trigger->trigger('again');
    assert(false, "Should throw exception on second trigger");
} catch (LogicException $e) {
    echo "  Correctly prevented double trigger\n";
}

// Test 3: Trigger - late subscribers get immediate callback
echo "✓ Test 3: Trigger - late subscriber\n";
$lateCallCount = 0;
$trigger->listen(function($data) use (&$lateCallCount) {
    $lateCallCount++;
    assert($data === 'data', "Should receive original data");
});
assert($lateCallCount === 1, "Late subscriber should be called immediately");
echo "  Late subscriber called immediately with original data\n";

// Test 4: Filter - chain transformations
echo "✓ Test 4: Filter - transform value\n";
$filter = new Filter('test-filter');
$filter->listen(function($value) {
    return $value * 2;
});
$filter->listen(function($value) {
    return $value + 10;
});

$result = $filter->filter(5);
assert($result === 20, "Filter should apply transformations: (5 * 2) + 10 = 20");
echo "  Filtered 5 through chain: {$result}\n";

// Test 5: Handler - first non-null wins
echo "✓ Test 5: Handler - first non-null response\n";
$handler = new Handler('test-handler');
$handler->listen(function($value) {
    return null; // This one doesn't handle it
});
$handler->listen(function($value) {
    return $value * 100; // This one handles it
});
$handler->listen(function($value) {
    throw new Exception("Should never be called");
});

$result = $handler->trigger(3);
assert($result === 300, "Handler should return first non-null");
echo "  Handler returned: {$result}\n";

// Test 6: Phase state machine exists
echo "✓ Test 6: Phase state machine exists\n";
assert(isset(Mini::$mini->phase), "Phase state machine should exist");
assert(Mini::$mini->phase instanceof \mini\Hooks\StateMachine, "Should be StateMachine");
echo "  Phase state machine available\n";

// Test 7: Phase hooks can be subscribed to
echo "✓ Test 7: Phase transition hooks\n";
$phaseCount = 0;
Mini::$mini->phase->onEnteringState(\mini\Phase::Shutdown, function() use (&$phaseCount) {
    $phaseCount++;
});
echo "  Phase hook listener registered successfully\n";

// Test 8: Current phase can be checked
echo "✓ Test 8: Current phase detection\n";
$currentPhase = Mini::$mini->phase->getCurrentState();
assert($currentPhase === \mini\Phase::Bootstrap, "Should be in Bootstrap phase initially");
echo "  Current phase: {$currentPhase->value}\n";

// Test 9: Once listeners
echo "✓ Test 9: Event.once() - auto-unsubscribe\n";
$onceEvent = new Event('once-test');
$onceCount = 0;
$onceEvent->once(function() use (&$onceCount) {
    $onceCount++;
});

$onceEvent->trigger();
$onceEvent->trigger();
assert($onceCount === 1, "once() listener should only fire once");
echo "  once() listener fired only once despite multiple triggers\n";

// Test 10: off() unsubscribe
echo "✓ Test 10: off() - unsubscribe\n";
$offEvent = new Event('off-test');
$offCount = 0;
$listener = function() use (&$offCount) {
    $offCount++;
};

$offEvent->listen($listener);
$offEvent->trigger();
assert($offCount === 1, "Should fire once");

$offEvent->off($listener);
$offEvent->trigger();
assert($offCount === 1, "Should not fire after off()");
echo "  Listener unsubscribed successfully\n";

echo "\n✅ All hooks system tests passed!\n";
echo "\nLifecycle management:\n";
echo "  Mini::\$mini->phase - StateMachine tracking application lifecycle\n";
echo "  Phases: Initializing → Bootstrap → Ready → Shutdown (+ Failed)\n";
echo "\nPhase hooks:\n";
echo "  phase->onEnteringState() - Before phase transition\n";
echo "  phase->onEnteredState()  - After phase transition\n";
echo "  phase->onExitingState()  - Before leaving phase\n";
echo "  phase->onExitedState()   - After leaving phase\n";
echo "\nHook types available:\n";
echo "  Event            - Can trigger multiple times\n";
echo "  Trigger          - Fires once, late subscribers called immediately\n";
echo "  Filter           - Chain of transformations\n";
echo "  Handler          - First non-null response wins\n";
echo "  StateMachine     - Validated state transitions with hooks\n";
echo "  PerItemTriggers  - Trigger once per source (object or string)\n";
