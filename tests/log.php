<?php

/**
 * Tests for mini\log() function and Logger implementation
 */

// Find and require the autoloader
$autoloader = realpath(__DIR__ . '/../vendor/autoload.php')
    ?: realpath(__DIR__ . '/../../../vendor/autoload.php')
    ?: realpath(__DIR__ . '/../../vendor/autoload.php');

if (!$autoloader) {
    die("Could not find autoloader\n");
}

require_once $autoloader;

use function mini\log;

// Test helpers
function test(string $description, callable $test): void
{
    try {
        $test();
        echo "✓ {$description}\n";
    } catch (Exception $e) {
        echo "✗ {$description}\n";
        echo "  Error: {$e->getMessage()}\n";
        echo "  at {$e->getFile()}:{$e->getLine()}\n";
    }
}

function assertEqual($expected, $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        $msg = $message ?: "Expected " . var_export($expected, true) . " but got " . var_export($actual, true);
        throw new Exception($msg);
    }
}

function assertInstanceOf(string $expectedClass, $actual, string $message = ''): void
{
    if (!($actual instanceof $expectedClass)) {
        $actualClass = is_object($actual) ? get_class($actual) : gettype($actual);
        $msg = $message ?: "Expected instance of {$expectedClass} but got {$actualClass}";
        throw new Exception($msg);
    }
}

echo "Testing mini\\log() function\n";
echo "============================\n\n";

// Bootstrap framework
\mini\bootstrap();

// Test 1: Logger returns PSR-3 LoggerInterface
test("log() returns PSR-3 LoggerInterface", function() {
    $logger = log();
    assertInstanceOf(\Psr\Log\LoggerInterface::class, $logger);
});

// Test 2: Logger returns same instance (singleton)
test("log() returns same instance (singleton)", function() {
    $logger1 = log();
    $logger2 = log();
    assertEqual($logger1, $logger2, "Should return the same instance");
});

// Test 3: Logger has PSR-3 methods
test("Logger has all PSR-3 log level methods", function() {
    $logger = log();
    assertEqual(true, method_exists($logger, 'emergency'));
    assertEqual(true, method_exists($logger, 'alert'));
    assertEqual(true, method_exists($logger, 'critical'));
    assertEqual(true, method_exists($logger, 'error'));
    assertEqual(true, method_exists($logger, 'warning'));
    assertEqual(true, method_exists($logger, 'notice'));
    assertEqual(true, method_exists($logger, 'info'));
    assertEqual(true, method_exists($logger, 'debug'));
    assertEqual(true, method_exists($logger, 'log'));
});

// Test 4: Basic logging doesn't throw errors
test("Basic logging works without errors", function() {
    $logger = log();
    $logger->info("Test info message");
    $logger->warning("Test warning message");
    $logger->error("Test error message");
});

// Test 5: Logging with context variables
test("Logging with context variables", function() {
    $logger = log();
    $logger->info("User {username} logged in from {ip}", [
        'username' => 'john',
        'ip' => '192.168.1.1'
    ]);
});

// Test 6: Logging with exception context
test("Logging with exception in context", function() {
    $logger = log();
    try {
        throw new \RuntimeException("Something went wrong");
    } catch (\Exception $e) {
        $logger->error("An error occurred: {message}", [
            'message' => $e->getMessage(),
            'exception' => $e
        ]);
    }
});

// Test 7: All log levels work
test("All PSR-3 log levels work", function() {
    $logger = log();
    $logger->emergency("Emergency message");
    $logger->alert("Alert message");
    $logger->critical("Critical message");
    $logger->error("Error message");
    $logger->warning("Warning message");
    $logger->notice("Notice message");
    $logger->info("Info message");
    $logger->debug("Debug message");
});

// Test 8: Complex context with arrays
test("Logging with array context values", function() {
    $logger = log();
    $logger->info("User data: {user}", [
        'user' => ['id' => 123, 'name' => 'John Doe']
    ]);
});

// Test 9: Null context values
test("Logging with null context values", function() {
    $logger = log();
    $logger->info("Value is {value}", [
        'value' => null
    ]);
});

// Test 10: Numeric context values
test("Logging with numeric context values", function() {
    $logger = log();
    $logger->info("Processing {count} items at {price} each", [
        'count' => 42,
        'price' => 19.99
    ]);
});

echo "\n✓ All logger tests passed!\n";
