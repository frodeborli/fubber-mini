<?php

namespace mini;

/**
 * Base class for structured tests
 *
 * Usage:
 *   // tests/Mini/container.php
 *   $test = new class extends mini\Test {
 *       public function testSingletonReturnsSameInstance(): void {
 *           $this->assertSame($a, $b);
 *       }
 *   };
 *   exit($test->run());
 *
 * Test methods must be public and start with "test".
 * Method names are converted from camelCase to readable output:
 *   testSingletonReturnsSameInstance → "Singleton returns same instance"
 *
 * Lifecycle:
 *   1. setUp() is called once (override to do pre-bootstrap setup)
 *   2. bootstrap() is called automatically if setUp() didn't call it
 *   3. All test* methods run in sequence
 *
 * If you need to register services or configure Mini before bootstrap,
 * override setUp() and call bootstrap() yourself after your setup:
 *
 *   protected function setUp(): void {
 *       Mini::$mini->set('service', $mock);
 *       \mini\bootstrap();
 *   }
 */
abstract class Test
{
    private array $results = [];
    private array $logs = [];
    private ?string $currentTest = null;

    /**
     * Run all test methods and return exit code
     */
    public function run(): int
    {
        $methods = $this->getTestMethods();
        $passed = 0;
        $failed = 0;

        // Call setUp once before all tests
        $this->setUp();

        // If setUp didn't bootstrap, do it now
        if (Mini::$mini->phase->getCurrentState() !== Phase::Ready) {
            \mini\bootstrap();
        }

        foreach ($methods as $method) {
            $this->currentTest = $method;
            $this->logs[$method] = [];
            $name = $this->methodToName($method);

            try {
                $this->$method();

                echo "✓ $name\n";
                $this->results[$method] = ['status' => 'passed'];
                $passed++;
            } catch (\Throwable $e) {
                echo "✗ $name\n";
                echo "  " . $e->getMessage() . "\n";
                if ($e->getFile() && $e->getLine()) {
                    echo "  at " . basename($e->getFile()) . ":" . $e->getLine() . "\n";
                }
                $this->results[$method] = ['status' => 'failed', 'error' => $e];
                $failed++;
            }

            // Output any logs for this test
            foreach ($this->logs[$method] as $log) {
                echo "  → $log\n";
            }
        }

        echo "\n";
        if ($failed === 0) {
            echo "✅ All $passed test(s) passed!\n";
        } else {
            echo "❌ $failed of " . ($passed + $failed) . " test(s) failed\n";
        }

        return $failed > 0 ? 1 : 0;
    }

    /**
     * Override to set up state before tests run
     *
     * Called once before all test methods. If you need to configure
     * services before bootstrap, do it here and call bootstrap() yourself.
     * If you don't call bootstrap(), it will be called automatically
     * after setUp() returns.
     */
    protected function setUp(): void {}

    /**
     * Log intermediate output during a test
     */
    protected function log(string $message): void
    {
        if ($this->currentTest !== null) {
            $this->logs[$this->currentTest][] = $message;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Assertions
    // ─────────────────────────────────────────────────────────────────────────

    protected function assertTrue(mixed $value, string $message = ''): void
    {
        if ($value !== true) {
            throw new \AssertionError($message ?: "Expected true, got " . $this->export($value));
        }
    }

    protected function assertFalse(mixed $value, string $message = ''): void
    {
        if ($value !== false) {
            throw new \AssertionError($message ?: "Expected false, got " . $this->export($value));
        }
    }

    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            throw new \AssertionError(
                $message ?: "Expected " . $this->export($expected) . ", got " . $this->export($actual)
            );
        }
    }

    protected function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected != $actual) {
            throw new \AssertionError(
                $message ?: "Expected " . $this->export($expected) . ", got " . $this->export($actual)
            );
        }
    }

    protected function assertNull(mixed $value, string $message = ''): void
    {
        if ($value !== null) {
            throw new \AssertionError($message ?: "Expected null, got " . $this->export($value));
        }
    }

    protected function assertNotNull(mixed $value, string $message = ''): void
    {
        if ($value === null) {
            throw new \AssertionError($message ?: "Expected non-null value");
        }
    }

    protected function assertThrows(callable $fn, string $exceptionClass = \Throwable::class, string $message = ''): void
    {
        try {
            $fn();
            throw new \AssertionError($message ?: "Expected $exceptionClass to be thrown");
        } catch (\Throwable $e) {
            if (!$e instanceof $exceptionClass) {
                throw new \AssertionError(
                    $message ?: "Expected $exceptionClass, got " . get_class($e) . ": " . $e->getMessage()
                );
            }
        }
    }

    protected function assertContains(string $needle, string $haystack, string $message = ''): void
    {
        if (!str_contains($haystack, $needle)) {
            throw new \AssertionError($message ?: "String does not contain '$needle'");
        }
    }

    protected function assertCount(int $expected, array|\Countable $value, string $message = ''): void
    {
        $actual = count($value);
        if ($expected !== $actual) {
            throw new \AssertionError($message ?: "Expected count $expected, got $actual");
        }
    }

    protected function assertInstanceOf(string $class, mixed $value, string $message = ''): void
    {
        if (!$value instanceof $class) {
            $actual = is_object($value) ? get_class($value) : gettype($value);
            throw new \AssertionError($message ?: "Expected instance of $class, got $actual");
        }
    }

    protected function fail(string $message = 'Test failed'): void
    {
        throw new \AssertionError($message);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Get all public methods starting with "test"
     */
    private function getTestMethods(): array
    {
        $methods = [];
        $reflection = new \ReflectionClass($this);

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (str_starts_with($method->getName(), 'test')) {
                $methods[] = $method->getName();
            }
        }

        return $methods;
    }

    /**
     * Convert camelCase method name to readable string
     *
     * testSingletonReturnsSameInstance → "Singleton returns same instance"
     */
    private function methodToName(string $method): string
    {
        // Remove "test" prefix
        $name = substr($method, 4);

        // Insert spaces before uppercase letters
        $name = preg_replace('/([a-z])([A-Z])/', '$1 $2', $name);

        // Lowercase and capitalize first letter
        return ucfirst(strtolower($name));
    }

    /**
     * Export value for error messages
     */
    private function export(mixed $value): string
    {
        if (is_object($value)) {
            return get_class($value) . ' object';
        }
        return var_export($value, true);
    }
}
