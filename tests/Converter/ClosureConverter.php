<?php
/**
 * Test ClosureConverter - wrapping typed closures as converters
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use mini\Test;
use mini\Converter\ClosureConverter;

$test = new class extends Test {

    protected function setUp(): void
    {
        // No bootstrap needed - ClosureConverter is standalone
    }

    // ========================================
    // Valid closure signatures
    // ========================================

    public function testSimpleTypedClosure(): void
    {
        $converter = new ClosureConverter(
            fn(string $text): string => strtoupper($text)
        );

        $this->assertSame('string', $converter->getInputType());
        $this->assertSame('string', $converter->getOutputType());
    }

    public function testClassTypedClosure(): void
    {
        $converter = new ClosureConverter(
            fn(\stdClass $obj): array => (array) $obj
        );

        $this->assertSame('stdClass', $converter->getInputType());
        $this->assertSame('array', $converter->getOutputType());
    }

    public function testInterfaceTypedClosure(): void
    {
        $converter = new ClosureConverter(
            fn(\Throwable $e): string => $e->getMessage()
        );

        $this->assertSame('Throwable', $converter->getInputType());
        $this->assertSame('string', $converter->getOutputType());
    }

    public function testUnionInputType(): void
    {
        $converter = new ClosureConverter(
            fn(string|int $value): string => (string) $value
        );

        // Union types are sorted alphabetically for canonical form
        $this->assertSame('int|string', $converter->getInputType());
        $this->assertSame('string', $converter->getOutputType());
    }

    public function testUnionInputTypeWithClasses(): void
    {
        $converter = new ClosureConverter(
            fn(\Exception|\Error $e): string => $e->getMessage()
        );

        $this->assertSame('Error|Exception', $converter->getInputType());
    }

    // ========================================
    // Named targets (bypass return type validation)
    // ========================================

    public function testNamedTargetBypassesReturnType(): void
    {
        // This closure has no return type, but we specify a named target
        $converter = new ClosureConverter(
            fn(\BackedEnum $e) => $e->value,
            'sql-value'
        );

        $this->assertSame('BackedEnum', $converter->getInputType());
        $this->assertSame('sql-value', $converter->getOutputType());
    }

    public function testNamedTargetOverridesReturnType(): void
    {
        $converter = new ClosureConverter(
            fn(string $s): string => $s,
            'custom-target'
        );

        $this->assertSame('custom-target', $converter->getOutputType());
    }

    // ========================================
    // Invalid closure signatures
    // ========================================

    public function testRejectsClosureWithNoParameters(): void
    {
        $this->assertThrows(
            fn() => new ClosureConverter(fn(): string => 'hello'),
            \InvalidArgumentException::class
        );
    }

    public function testRejectsClosureWithMultipleParameters(): void
    {
        $this->assertThrows(
            fn() => new ClosureConverter(fn(string $a, int $b): string => $a . $b),
            \InvalidArgumentException::class
        );
    }

    public function testRejectsClosureWithUntypedParameter(): void
    {
        $this->assertThrows(
            fn() => new ClosureConverter(fn($value): string => (string) $value),
            \InvalidArgumentException::class
        );
    }

    public function testRejectsClosureWithNoReturnType(): void
    {
        $this->assertThrows(
            fn() => new ClosureConverter(fn(string $s) => $s),
            \InvalidArgumentException::class
        );
    }

    public function testRejectsClosureWithNullableInput(): void
    {
        $this->assertThrows(
            fn() => new ClosureConverter(fn(?string $s): string => $s ?? ''),
            \InvalidArgumentException::class
        );
    }

    public function testRejectsClosureWithNullableReturn(): void
    {
        $this->assertThrows(
            fn() => new ClosureConverter(fn(string $s): ?string => $s),
            \InvalidArgumentException::class
        );
    }

    public function testRejectsClosureWithUnionReturnType(): void
    {
        $this->assertThrows(
            fn() => new ClosureConverter(fn(string $s): string|int => $s),
            \InvalidArgumentException::class
        );
    }

    // ========================================
    // supports() method
    // ========================================

    public function testSupportsMatchingScalarType(): void
    {
        $converter = new ClosureConverter(fn(string $s): string => $s);

        $this->assertTrue($converter->supports('hello', 'string'));
        $this->assertFalse($converter->supports(123, 'string'));
        $this->assertFalse($converter->supports(['array'], 'string'));
    }

    public function testSupportsMatchingObjectType(): void
    {
        $converter = new ClosureConverter(fn(\stdClass $obj): array => []);

        $obj = new \stdClass();
        $this->assertTrue($converter->supports($obj, 'array'));
        $this->assertFalse($converter->supports('not-object', 'array'));
    }

    public function testSupportsMatchingInterfaceType(): void
    {
        $converter = new ClosureConverter(fn(\Throwable $e): string => '');

        $this->assertTrue($converter->supports(new \Exception(), 'string'));
        $this->assertTrue($converter->supports(new \Error(), 'string'));
        $this->assertFalse($converter->supports(new \stdClass(), 'string'));
    }

    public function testSupportsWithUnionInputType(): void
    {
        $converter = new ClosureConverter(fn(string|int $v): string => '');

        $this->assertTrue($converter->supports('hello', 'string'));
        $this->assertTrue($converter->supports(42, 'string'));
        $this->assertFalse($converter->supports(3.14, 'string'));
    }

    public function testSupportsChecksTargetType(): void
    {
        $converter = new ClosureConverter(fn(string $s): string => $s);

        // Correct target type
        $this->assertTrue($converter->supports('hello', 'string'));

        // Wrong target type
        $this->assertFalse($converter->supports('hello', 'int'));
        $this->assertFalse($converter->supports('hello', 'array'));
    }

    public function testSupportsWithSubclassTarget(): void
    {
        // Converter outputs Exception, but target is Throwable (parent)
        $converter = new ClosureConverter(
            fn(string $s): \Exception => new \Exception($s)
        );

        // Exception is subclass of Throwable, so this should work
        $this->assertTrue($converter->supports('msg', 'Throwable'));
        $this->assertTrue($converter->supports('msg', 'Exception'));
    }

    // ========================================
    // convert() method
    // ========================================

    public function testConvertExecutesClosure(): void
    {
        $converter = new ClosureConverter(fn(string $s): string => strtoupper($s));

        $this->assertSame('HELLO', $converter->convert('hello', 'string'));
    }

    public function testConvertWithUnionType(): void
    {
        $converter = new ClosureConverter(
            fn(string|int $v): string => 'type:' . gettype($v)
        );

        $this->assertSame('type:string', $converter->convert('hello', 'string'));
        $this->assertSame('type:integer', $converter->convert(42, 'string'));
    }

    public function testConvertWithObject(): void
    {
        $converter = new ClosureConverter(
            fn(\Exception $e): array => ['message' => $e->getMessage()]
        );

        $result = $converter->convert(new \Exception('test error'), 'array');
        $this->assertSame(['message' => 'test error'], $result);
    }

    // ========================================
    // Mixed type handling
    // ========================================

    public function testRejectsMixedInputType(): void
    {
        // mixed allows null, so it's rejected
        $this->assertThrows(
            fn() => new ClosureConverter(fn(mixed $v): string => gettype($v)),
            \InvalidArgumentException::class
        );
    }
};

exit($test->run());
