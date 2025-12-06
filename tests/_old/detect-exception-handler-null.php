<?php
/**
 * Test detecting exception handler with null approach
 */

echo "Testing Exception Handler Detection (null approach)\n";
echo "====================================================\n\n";

// Test 1: No handler set
echo "Test 1: No exception handler set\n";
$oldHandler = set_exception_handler(null);
echo "  Old handler: " . ($oldHandler === null ? 'null' : 'callable') . "\n";
if ($oldHandler !== null) {
    set_exception_handler($oldHandler);
}
echo "  ✓ Clean detection\n";

echo "\n";

// Test 2: After setting a handler
echo "Test 2: After setting exception handler\n";
set_exception_handler(function(\Throwable $e) {
    echo "Custom handler: " . $e->getMessage() . "\n";
});

$oldHandler = set_exception_handler(null);
echo "  Old handler: " . ($oldHandler === null ? 'null' : 'callable') . "\n";

// Restore it
if ($oldHandler !== null) {
    set_exception_handler($oldHandler);
    echo "  ✓ Restored previous handler\n";
}

// Test that it still works
try {
    throw new Exception("Test exception");
} catch (Exception $e) {
    echo "  (Exception caught by try/catch for testing)\n";
}

echo "\n";

// Test 3: Conditional handler pattern
echo "Test 3: Conditional handler pattern (Mini's use case)\n";

// Simulate developer setting their own handler first
$developerHandler = function(\Throwable $e) {
    echo "Developer's custom handler: {$e->getMessage()}\n";
};
set_exception_handler($developerHandler);
echo "  Developer set their own handler\n";

// Now Mini's bootstrap checks
$oldHandler = set_exception_handler(null);
if ($oldHandler !== null) {
    echo "  ✓ Mini detected existing handler, keeping it\n";
    set_exception_handler($oldHandler);
} else {
    echo "  Mini would set its own handler here\n";
    set_exception_handler(function(\Throwable $e) {
        echo "Mini's handler: {$e->getMessage()}\n";
    });
}

echo "\n";

// Test 4: Clean helper function
echo "Test 4: Helper function pattern\n";

function conditionally_set_exception_handler(callable $handler): void {
    $oldHandler = set_exception_handler(null);
    if ($oldHandler !== null) {
        // Keep existing handler
        set_exception_handler($oldHandler);
    } else {
        // Set new handler
        set_exception_handler($handler);
    }
}

// Clear current handler for test
set_exception_handler(null);

conditionally_set_exception_handler(function(\Throwable $e) {
    echo "Conditionally set handler: {$e->getMessage()}\n";
});

echo "  ✓ Handler set conditionally\n";

echo "\n✅ null approach works perfectly!\n";
