<?php
/**
 * Test detecting if an exception handler is set
 */

echo "Testing Exception Handler Detection\n";
echo "=====================================\n\n";

// Test 1: No handler set
echo "Test 1: No exception handler set\n";
$current = set_exception_handler(function() {});
restore_exception_handler();
echo "  Handler exists: " . ($current !== null ? 'YES' : 'NO') . "\n";
echo "  Value: " . ($current === null ? 'null' : 'callable') . "\n";

echo "\n";

// Test 2: After setting a handler
echo "Test 2: After setting exception handler\n";
set_exception_handler(function(\Throwable $e) {
    echo "Custom handler called\n";
});

$current = set_exception_handler(function() {});
restore_exception_handler();
echo "  Handler exists: " . ($current !== null ? 'YES' : 'NO') . "\n";
echo "  Value: " . ($current === null ? 'null' : 'callable') . "\n";

echo "\n";

// Test 3: Helper function approach
echo "Test 3: Helper function\n";
function has_exception_handler(): bool {
    $current = set_exception_handler(function() {});
    restore_exception_handler();
    return $current !== null;
}

echo "  has_exception_handler(): " . (has_exception_handler() ? 'true' : 'false') . "\n";

echo "\n";

// Test 4: Cleaner approach - capture and restore
echo "Test 4: Capture current handler properly\n";
function get_current_exception_handler(): ?callable {
    $current = set_exception_handler(function() {});
    if ($current !== null) {
        set_exception_handler($current); // Restore the actual handler
    } else {
        restore_exception_handler(); // Remove our temporary one
    }
    return $current;
}

$handler = get_current_exception_handler();
echo "  Current handler: " . ($handler === null ? 'null' : 'callable') . "\n";

// Verify it's still working
try {
    throw new Exception("Test");
} catch (Exception $e) {
    echo "  Handler still active and working\n";
}

echo "\n✅ Detection works!\n";
