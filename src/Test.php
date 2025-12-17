<?php

namespace mini;

use Composer\Autoload\ClassLoader;
use ReflectionClass;

/**
 * Base class for structured tests
 *
 * Usage:
 *   $test = new class extends mini\Test {
 *       public function testSomething(): void {
 *           $this->assertSame($expected, $actual);
 *       }
 *   };
 *   exit($test->run());
 *
 * Test methods must be public and start with "test".
 * Method names are converted from camelCase to readable output:
 *   testSingletonReturnsSameInstance → "Singleton returns same instance"
 */
abstract class Test
{
    private array $logs = [];
    private ?string $expectedExceptionClass = null;

    // Output state
    private bool $isTty;
    private bool $verbose;
    private int $passed = 0;
    private int $failed = 0;
    private ?string $currentTestName = null;
    private string $normal = '';
    private string $white = '';
    private string $green = '';
    private string $red = '';
    private float $startTime = 0;
    private string $indent = '';
    /** @var resource|null File descriptor 3 for reporting to test runner */
    private $runnerPipe = null;

    /**
     * Run a test file in a subprocess
     *
     * @return array{exitCode: int, info: ?array}
     */
    public static function runTestFile(string $path): array
    {
        $depth = ((int) getenv('MINI_TEST_RUNNER')) + 1;

        // Pass env to child without modifying parent's environment
        $env = getenv();
        $env['MINI_TEST_RUNNER'] = (string) $depth;

        $process = proc_open(
            ['php', '-d', 'zend.assertions=1', '-d', 'assert.exception=1', $path],
            [STDIN, STDOUT, STDERR, 3 => ['pipe', 'w']],
            $pipes,
            null,
            $env
        );

        $info = null;
        if (is_resource($process)) {
            $json = stream_get_contents($pipes[3]);
            fclose($pipes[3]);
            if ($json) {
                $info = json_decode($json, true);
            }
            $exitCode = proc_close($process);
        } else {
            $exitCode = 1;
        }

        return ['exitCode' => $exitCode, 'info' => $info];
    }

    private function getIndentString(): string
    {
        $depth = (int) getenv('MINI_TEST_RUNNER') ?: 0;
        return str_repeat('  ', 1 + $depth);
    }


    public function run(bool $exit = true): int
    {
        $this->isTty = stream_isatty(STDOUT);
        $this->indent = $this->getIndentString();
        if ($this->isTty) {
            $this->normal = "\033[0m";
            $this->green = "\033[92m";
            $this->red = "\033[91m";
            $this->white = "\033[97m";
        }
        $this->verbose = in_array('-v', $GLOBALS['argv']) || (bool) getenv('MINI_TEST_RUNNER');

        // Open fd 3 for reporting to test runner if available
        if (getenv('MINI_TEST_RUNNER')) {
            $this->runnerPipe = @fopen('php://fd/3', 'w');
        }

        $this->setUp();

        if (Mini::$mini->phase->getCurrentState() !== Phase::Ready) {
            \mini\bootstrap();
        }

        foreach ($this->getTestMethods() as $method) {
            $this->expectedExceptionClass = null;
            $this->logs[$method] = [];
            $name = $this->methodToName($method);

            $this->startTest($name);

            try {
                $this->$method();

                if ($this->expectedExceptionClass !== null) {
                    throw new \AssertionError("Expected {$this->expectedExceptionClass} to be thrown");
                }

                $this->endTest(true);
            } catch (\Throwable $e) {
                if ($this->expectedExceptionClass !== null && $e instanceof $this->expectedExceptionClass) {
                    $this->endTest(true);
                } else {
                    $this->endTest(false, $e);
                }
            }

            // Show logs (only in standalone mode)
            if (!$this->verbose) {
                foreach ($this->logs[$method] as $log) {
                    echo "  → $log\n";
                }
            }
        }

        $this->printSummary();
        $this->reportToRunner([
            'passed' => $this->passed,
            'failed' => $this->failed,
        ]);

        $exitCode = $this->failed > 0 ? 1 : 0;
        if ($exit) {
            exit($exitCode);
        }
        return $exitCode;
    }

    /**
     * Write structured data to the test runner via fd 3
     */
    private function reportToRunner(array $data): void
    {
        if ($this->runnerPipe) {
            fwrite($this->runnerPipe, json_encode($data) . "\n");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Output methods
    // ─────────────────────────────────────────────────────────────────────────

    private function startTest(string $name): void
    {
        $this->startTime = microtime(true);
        $this->currentTestName = $name;

        if ($this->verbose) {
            if ($this->isTty) {
                // Show name, will be cleared on success
                echo "{$this->indent}  {$this->white}]{$this->normal} $name\r{$this->indent}{$this->white}[{$this->normal}";
            } else {
                $displayName = strlen($name) >= 50 ? substr($name, 0, 45) . '...' : $name;
                echo "{$this->indent}" . str_pad($displayName, 50);
            }
        }
        ob_flush();
    }

    private function endTest(bool $success, ?\Throwable $error = null): void
    {
        $name = $this->currentTestName;

        if ($success) {
            $this->passed++;
            if ($this->verbose) {
                if ($this->isTty) {
                    $time = microtime(true) - $this->startTime;
                    if ($time > 3) {
                        if ($time > 1) {
                            $timeStr = number_format($time, 3) . ' s';
                        } else {
                            $timeStr = number_format($time * 1000, 1) . 'ms';
                        }
                        echo "\r{$this->indent}{$this->white}[{$this->green}✓{$this->white}]{$this->normal} $name ({$this->red}" . $timeStr . "{$this->normal})\n";
                    } else {
                        echo "\r\033[2K"; // Clear line
                    }
                } else {
                    echo "SUCCESS\n";
                }
            }
        } else {
            $this->failed++;
            if ($this->verbose) {
                if ($this->isTty) {
                    echo "\r{$this->indent}{$this->white}[{$this->red}✗{$this->white}]{$this->normal} $name\n";
                } else {
                    echo "FAIL\n";
                }
            } else {
                echo "{$this->indent}" . $this->currentTestName . " {$this->red}FAIL{$this->normal}\n";
            }
            if ($error !== null) {
                echo rtrim($this->indentText($this->cleanup($error))) . "\n\n";
            }
        }
    }

    private function printSummary(): void
    {
        if ($this->verbose) {
            if ($this->failed > 0) {
                echo "{$this->indent}✗ {$this->failed} failed, {$this->passed} passed\n";
            }
        } else {
            if ($this->failed === 0) {
                echo "{$this->indent}✅ All {$this->passed} test(s) passed!\n";
            } else {
                $total = $this->passed + $this->failed;
                echo "{$this->indent}❌ {$this->failed} of $total test(s) failed\n";
            }
        }
    }

    private function indentText(string $text): string {
        $indent = $this->indent . '  ';
        return preg_replace('/^/m', $indent, $text);
    }

    private function cleanup(string $text): string {
        if (class_exists(ClassLoader::class)) {
            $rc = new ReflectionClass(ClassLoader::class);
            $prefixDir = dirname($rc->getFileName(), 3) . '/';
        } else {
            $prefixDir = getcwd() . '/';
        }
        return str_replace($prefixDir, '', $text);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Lifecycle
    // ─────────────────────────────────────────────────────────────────────────

    protected function setUp(): void {}

    protected function log(string $message): void
    {
        if ($this->currentTestName !== null) {
            $key = array_key_last($this->logs);
            if ($key !== null) {
                $this->logs[$key][] = $message;
            }
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

    protected function assertArrayHasKey(string|int $key, array|\ArrayAccess $array, string $message = ''): void
    {
        $exists = $array instanceof \ArrayAccess
            ? $array->offsetExists($key)
            : array_key_exists($key, $array);

        if (!$exists) {
            throw new \AssertionError($message ?: "Array does not have key '$key'");
        }
    }

    protected function assertIsArray(mixed $value, string $message = ''): void
    {
        if (!is_array($value)) {
            throw new \AssertionError($message ?: "Expected array, got " . gettype($value));
        }
    }

    protected function assertIsObject(mixed $value, string $message = ''): void
    {
        if (!is_object($value)) {
            throw new \AssertionError($message ?: "Expected object, got " . gettype($value));
        }
    }

    protected function assertJson(string $value, string $message = ''): void
    {
        json_decode($value);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \AssertionError($message ?: "Invalid JSON: " . json_last_error_msg());
        }
    }

    protected function assertStringContainsString(string $needle, string $haystack, string $message = ''): void
    {
        if (!str_contains($haystack, $needle)) {
            throw new \AssertionError($message ?: "String does not contain '$needle'");
        }
    }

    protected function assertStringNotContainsString(string $needle, string $haystack, string $message = ''): void
    {
        if (str_contains($haystack, $needle)) {
            throw new \AssertionError($message ?: "String unexpectedly contains '$needle'");
        }
    }

    protected function assertStringStartsWith(string $prefix, string $string, string $message = ''): void
    {
        if (!str_starts_with($string, $prefix)) {
            throw new \AssertionError($message ?: "String does not start with '$prefix'");
        }
    }

    protected function assertStringEndsWith(string $suffix, string $string, string $message = ''): void
    {
        if (!str_ends_with($string, $suffix)) {
            throw new \AssertionError($message ?: "String does not end with '$suffix'");
        }
    }

    protected function assertNotSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected === $actual) {
            throw new \AssertionError($message ?: "Values are unexpectedly the same");
        }
    }

    protected function assertNotEmpty(mixed $value, string $message = ''): void
    {
        if (empty($value)) {
            throw new \AssertionError($message ?: "Value is unexpectedly empty");
        }
    }

    protected function assertGreaterThan(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($actual <= $expected) {
            throw new \AssertionError($message ?: "Expected value greater than $expected, got $actual");
        }
    }

    protected function assertLessThan(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($actual >= $expected) {
            throw new \AssertionError($message ?: "Expected value less than $expected, got $actual");
        }
    }

    protected function assertLessThanOrEqual(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($actual > $expected) {
            throw new \AssertionError($message ?: "Expected value <= $expected, got $actual");
        }
    }

    protected function assertGreaterThanOrEqual(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($actual < $expected) {
            throw new \AssertionError($message ?: "Expected value >= $expected, got $actual");
        }
    }

    protected function expectException(string $exceptionClass): void
    {
        $this->expectedExceptionClass = $exceptionClass;
    }

    protected function fail(string $message = 'Test failed'): void
    {
        throw new \AssertionError($message);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

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

    private function methodToName(string $method): string
    {
        $name = substr($method, 4);
        $name = preg_replace('/([a-z])([A-Z])/', '$1 $2', $name);
        return ucfirst(strtolower($name));
    }

    private function export(mixed $value): string
    {
        if (is_object($value)) {
            return get_class($value) . ' object';
        }
        return var_export($value, true);
    }
}
