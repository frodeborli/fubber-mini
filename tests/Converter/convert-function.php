<?php
/**
 * Test convert() helper function - integration with container
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Test;
use mini\Converter\ConverterRegistryInterface;

$test = new class extends Test {

    protected function setUp(): void
    {
        // Bootstrap Mini to get the container and converter registry
        \mini\bootstrap();

        // Register test converters
        $registry = \mini\Mini::$mini->get(ConverterRegistryInterface::class);

        $registry->register(fn(string $s): array => ['text' => $s]);
        $registry->register(fn(int $n): array => ['number' => $n]);
        $registry->register(fn(\Exception $e): array => ['error' => $e->getMessage()]);
    }

    // ========================================
    // Basic usage
    // ========================================

    public function testConvertStringToArray(): void
    {
        $result = \mini\convert('hello', 'array');

        $this->assertSame(['text' => 'hello'], $result);
    }

    public function testConvertIntToArray(): void
    {
        $result = \mini\convert(42, 'array');

        $this->assertSame(['number' => 42], $result);
    }

    public function testConvertExceptionToArray(): void
    {
        $result = \mini\convert(new \Exception('oops'), 'array');

        $this->assertSame(['error' => 'oops'], $result);
    }

    // ========================================
    // No converter found
    // ========================================

    public function testConvertThrowsWhenNoConverter(): void
    {
        // No converter registered for float -> array
        $this->assertThrows(
            fn() => \mini\convert(3.14, 'array'),
            \RuntimeException::class
        );
    }

    public function testConvertThrowsForUnknownTargetType(): void
    {
        $this->assertThrows(
            fn() => \mini\convert('hello', 'SomeUnknownType'),
            \RuntimeException::class
        );
    }

    // ========================================
    // Type hierarchy
    // ========================================

    public function testConvertUsesParentClassConverter(): void
    {
        // RuntimeException extends Exception
        $result = \mini\convert(new \RuntimeException('runtime error'), 'array');

        $this->assertSame(['error' => 'runtime error'], $result);
    }
};

exit($test->run());
