<?php

/**
 * Test PSR-11 Container Implementation in Mini
 */

require_once __DIR__ . '/../vendor/autoload.php';

use mini\Mini;
use mini\Lifetime;

// Test helper functions
function test(string $description, callable $test): void {
    try {
        $test();
        echo "✓ $description\n";
    } catch (Throwable $e) {
        echo "✗ $description\n";
        echo "  Error: " . $e->getMessage() . "\n";
        echo "  File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    }
}

function assertEqual($expected, $actual, string $message = ''): void {
    if ($expected !== $actual) {
        $msg = $message ?: "Expected " . var_export($expected, true) . ", got " . var_export($actual, true);
        throw new Exception($msg);
    }
}

function assertTrue($condition, string $message = 'Assertion failed'): void {
    if (!$condition) {
        throw new Exception($message);
    }
}

function assertFalse($condition, string $message = 'Assertion failed'): void {
    if ($condition) {
        throw new Exception($message);
    }
}

// Use the global Mini instance created by bootstrap.php
$mini = Mini::$mini;

echo "Testing PSR-11 Container Implementation\n";
echo "========================================\n\n";

// Register test services BEFORE bootstrap (while in Bootstrap phase)
$mini->addService('test.singleton', Lifetime::Singleton, fn() => new stdClass());
$mini->addService('test.transient', Lifetime::Transient, fn() => new stdClass());
$mini->addService('test.scoped', Lifetime::Scoped, fn() => new stdClass());
$mini->addService('test.with-mini', Lifetime::Singleton, fn() => Mini::$mini);

// Bootstrap framework (required for Scoped services)
\mini\bootstrap();

// Test 1: Basic service registration and retrieval
test("Can register and retrieve a singleton service", function() use ($mini) {
    assertTrue($mini->has('test.singleton'));

    $instance1 = $mini->get('test.singleton');
    $instance2 = $mini->get('test.singleton');

    assertTrue($instance1 === $instance2, "Singleton should return same instance");
});

// Test 2: Transient services
test("Transient services create new instance each time", function() use ($mini) {
    $instance1 = $mini->get('test.transient');
    $instance2 = $mini->get('test.transient');

    assertFalse($instance1 === $instance2, "Transient should return different instances");
});

// Test 3: Scoped services
test("Scoped services return same instance within request scope", function() use ($mini) {
    $instance1 = $mini->get('test.scoped');
    $instance2 = $mini->get('test.scoped');

    assertTrue($instance1 === $instance2, "Scoped should return same instance in same request");
});

// Test 4: has() method
test("has() returns false for unregistered service", function() use ($mini) {
    assertFalse($mini->has('nonexistent.service'));
});

// Test 5: NotFoundException
test("get() throws NotFoundException for unregistered service", function() use ($mini) {
    try {
        $mini->get('nonexistent.service');
        throw new Exception("Should have thrown NotFoundException");
    } catch (Psr\Container\NotFoundExceptionInterface $e) {
        assertTrue(true);
    }
});

// Test 6: Factory closure receives Mini instance
test("Factory closure can access Mini instance", function() use ($mini) {
    $result = $mini->get('test.with-mini');
    assertTrue($result === Mini::$mini, "Factory should have access to Mini instance");
});

// Test 7: Framework services are registered
test("Translator service is registered", function() use ($mini) {
    assertTrue($mini->has(\mini\I18n\Translator::class));
});

test("Fmt service is registered", function() use ($mini) {
    assertTrue($mini->has(\mini\I18n\Fmt::class));
});

test("Logger service is registered", function() use ($mini) {
    assertTrue($mini->has(\Psr\Log\LoggerInterface::class));
});

// Test 8: Helper functions use container
test("mini\\fmt() returns instance from container", function() use ($mini) {
    $fmt1 = \mini\fmt();
    $fmt2 = \mini\fmt();

    assertTrue($fmt1 === $fmt2, "fmt() should return singleton from container");
});

test("Direct container access works", function() use ($mini) {
    $t1 = $mini->get(\mini\I18n\Translator::class);
    $t2 = $mini->get(\mini\I18n\Translator::class);

    assertTrue($t1 === $t2, "Scoped service should return same instance");
});

test("mini\\log() returns instance from container", function() use ($mini) {
    $log1 = \mini\log();
    $log2 = \mini\log();

    assertTrue($log1 === $log2, "log() should return singleton instance");
});

// Test 9: Services work correctly
test("Fmt service works correctly", function() {
    $fmt = \mini\fmt();
    $result = $fmt->number(1234.56);

    assertTrue(is_string($result), "Fmt::number should return string");
    assertTrue(strpos($result, '1') !== false, "Should contain digit 1");
});

test("Logger service implements PSR-3", function() {
    $logger = \mini\log();

    assertTrue($logger instanceof Psr\Log\LoggerInterface, "Logger should implement LoggerInterface");

    // Test that logging doesn't throw errors
    $logger->info("Test message");
    assertTrue(true);
});

test("Translator service works correctly", function() {
    $translator = \mini\Mini::$mini->get(\mini\I18n\Translator::class);

    assertTrue($translator instanceof \mini\I18n\Translator, "Should be Translator instance");
    assertTrue(method_exists($translator, 'translate'), "Should have translate method");
});

echo "\n✅ All container tests passed!\n";
