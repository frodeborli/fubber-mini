<?php
/**
 * Test exception handling in lifecycle hooks
 */

require_once __DIR__ . '/../vendor/autoload.php';

use mini\Mini;

echo "Testing Hook Exception Handling\n";
echo "=================================\n\n";

// Test 1: Exception in a simple Event hook
echo "Test 1: Exception in Event hook (default behavior)\n";
$testEvent = new \mini\Hooks\Event('test-event');
$testEvent->listen(function() {
    echo "  Listener 1 called\n";
});
$testEvent->listen(function() {
    echo "  Listener 2 called\n";
    throw new Exception("Error in listener 2");
});
$testEvent->listen(function() {
    echo "  Listener 3 called (should not execute)\n";
});

try {
    echo "  Triggering event...\n";
    $testEvent->trigger();
    echo "  ❌ Should not reach here\n";
} catch (Exception $e) {
    echo "  ✓ Exception propagated: {$e->getMessage()}\n";
    echo "  Note: Listener 3 was never called (exception stopped execution)\n";
}

echo "\n";

// Test 2: Demonstrate the problem with onRequestReceived
echo "Test 2: Theoretical behavior of onRequestReceived exception\n";
echo "  onRequestReceived fires BEFORE set_exception_handler()\n";
echo "  If exception thrown:\n";
echo "    1. Dispatcher catches it in runEvents()\n";
echo "    2. handleException() checks for custom handler\n";
echo "    3. No custom handler set → re-throws exception\n";
echo "    4. No global exception handler set yet → PHP default handling\n";
echo "    5. Result: Fatal error, script terminates\n";
echo "    6. No error page, just raw error message\n";

echo "\n";

// Test 3: Behavior with onAfterBootstrap
echo "Test 3: Theoretical behavior of onAfterBootstrap exception\n";
echo "  onAfterBootstrap fires AFTER set_exception_handler()\n";
echo "  If exception thrown:\n";
echo "    1. Dispatcher catches it in runEvents()\n";
echo "    2. handleException() re-throws (no custom handler)\n";
echo "    3. Global exception handler catches it\n";
echo "    4. showErrorPage() renders proper error page\n";
echo "    5. Result: Clean error page with debug info if Mini::debug\n";

echo "\n";

// Test 4: Demonstrate async runtime can handle this
echo "Test 4: How async runtimes would handle hook exceptions\n";
echo "  Async runtimes like phasync can configure Dispatcher:\n";
echo "  \mini\Hooks\Dispatcher::configure(..., exceptionHandler: fn() => log());\n";
echo "  This prevents hooks from crashing the entire runtime\n";
echo "  Exceptions logged but execution continues\n";

echo "\n✅ Analysis complete\n\n";

echo "RECOMMENDATION:\n";
echo "================\n";
echo "Hook listeners should handle their own exceptions gracefully.\n";
echo "Unhandled exceptions in onRequestReceived could crash before error handling ready.\n";
echo "Framework could potentially:\n";
echo "  1. Set a basic exception handler BEFORE onRequestReceived\n";
echo "  2. Configure Dispatcher with default exception logger\n";
echo "  3. Document that hook listeners must handle exceptions\n";
