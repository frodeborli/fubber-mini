<?php
/**
 * Test ConverterRegistry - converter registration and lookup
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use mini\Test;
use mini\Converter\ConverterRegistry;
use mini\Converter\ClosureConverter;

$test = new class extends Test {

    /**
     * Create a fresh registry for each test
     */
    private function createRegistry(): ConverterRegistry
    {
        return new ConverterRegistry();
    }

    protected function setUp(): void
    {
        // No bootstrap needed - ConverterRegistry is standalone
    }

    // ========================================
    // Basic registration and lookup
    // ========================================

    public function testRegisterAndConvertSimpleType(): void
    {
        $registry = $this->createRegistry();
        $registry->register(fn(string $s): array => [$s]);

        $result = $registry->convert('hello', 'array');
        $this->assertSame(['hello'], $result);
    }

    public function testRegisterClosureDirectly(): void
    {
        $registry = $this->createRegistry();
        $registry->register(fn(int $n): string => "number:$n");

        $this->assertTrue($registry->has(42, 'string'));
        $this->assertSame('number:42', $registry->convert(42, 'string'));
    }

    public function testRegisterConverterInterface(): void
    {
        $registry = $this->createRegistry();
        $converter = new ClosureConverter(fn(float $f): string => number_format($f, 2));
        $registry->register($converter);

        $this->assertSame('3.14', $registry->convert(3.14159, 'string'));
    }

    public function testHasReturnsFalseWhenNoConverter(): void
    {
        $registry = $this->createRegistry();
        $this->assertFalse($registry->has('value', 'NonExistentType'));
    }

    public function testConvertThrowsWhenNoConverter(): void
    {
        $registry = $this->createRegistry();
        $this->assertThrows(
            fn() => $registry->convert('value', 'NonExistentType'),
            \RuntimeException::class
        );
    }

    public function testGetReturnsNullWhenNoConverter(): void
    {
        $registry = $this->createRegistry();
        $this->assertNull($registry->get('value', 'NonExistentType'));
    }

    public function testGetReturnsConverter(): void
    {
        $registry = $this->createRegistry();
        $registry->register(fn(string $s): int => strlen($s));

        $converter = $registry->get('hello', 'int');
        $this->assertNotNull($converter);
        $this->assertInstanceOf(ClosureConverter::class, $converter);
    }

    // ========================================
    // Union input types
    // ========================================

    public function testUnionInputTypeHandlesMultipleTypes(): void
    {
        $registry = $this->createRegistry();
        $registry->register(fn(string|int $v): string => "value:$v");

        $this->assertSame('value:hello', $registry->convert('hello', 'string'));
        $this->assertSame('value:42', $registry->convert(42, 'string'));
    }

    public function testUnionTypeDoesNotMatchOtherTypes(): void
    {
        $registry = $this->createRegistry();
        $registry->register(fn(string|int $v): string => "value:$v");

        // float is not in the union
        $this->assertFalse($registry->has(3.14, 'string'));

        // Attempting to convert should throw
        $this->assertThrows(
            fn() => $registry->convert(3.14, 'string'),
            \RuntimeException::class
        );
    }

    // ========================================
    // Type specificity (single vs union)
    // ========================================

    public function testSingleTypeOverridesUnionMember(): void
    {
        $registry = $this->createRegistry();

        // Register union first
        $registry->register(fn(string|int $v): string => "union:$v");

        // Register more specific single-type converter
        $registry->register(fn(string $s): string => "single:$s");

        // String should use the more specific converter
        $this->assertSame('single:hello', $registry->convert('hello', 'string'));

        // Int should still use the union converter
        $this->assertSame('union:42', $registry->convert(42, 'string'));
    }

    public function testSingleTypeRegisteredFirstBlocksUnion(): void
    {
        $registry = $this->createRegistry();

        // Register single type first
        $registry->register(fn(string $s): string => "single:$s");

        // Registering a union that overlaps with the single type is a conflict
        // (the union would have "hidden" members that never get used)
        $this->assertThrows(
            fn() => $registry->register(fn(string|int $v): string => "union:$v"),
            \InvalidArgumentException::class
        );
    }

    // ========================================
    // Object type hierarchy
    // ========================================

    public function testConverterMatchesExactClass(): void
    {
        $registry = $this->createRegistry();
        $registry->register(fn(\Exception $e): string => "exception:{$e->getMessage()}");

        $result = $registry->convert(new \Exception('test'), 'string');
        $this->assertSame('exception:test', $result);
    }

    public function testConverterMatchesParentClass(): void
    {
        $registry = $this->createRegistry();
        $registry->register(fn(\Exception $e): string => "exception:{$e->getMessage()}");

        // RuntimeException extends Exception
        $result = $registry->convert(new \RuntimeException('runtime'), 'string');
        $this->assertSame('exception:runtime', $result);
    }

    public function testConverterMatchesInterface(): void
    {
        $registry = $this->createRegistry();
        $registry->register(fn(\Throwable $e): string => "throwable:{$e->getMessage()}");

        $this->assertSame('throwable:error', $registry->convert(new \Error('error'), 'string'));
        $this->assertSame('throwable:exception', $registry->convert(new \Exception('exception'), 'string'));
    }

    public function testMoreSpecificClassConverterWins(): void
    {
        $registry = $this->createRegistry();

        // Register general Throwable converter
        $registry->register(fn(\Throwable $e): string => "throwable");

        // Register more specific Exception converter
        $registry->register(fn(\Exception $e): string => "exception");

        // Exception should use Exception converter (more specific)
        $this->assertSame('exception', $registry->convert(new \Exception(), 'string'));

        // Error should use Throwable converter
        $this->assertSame('throwable', $registry->convert(new \Error(), 'string'));
    }

    public function testInterfaceConverterMatchesImplementors(): void
    {
        $registry = $this->createRegistry();
        $registry->register(fn(\Countable $c): int => count($c));

        $this->assertSame(3, $registry->convert(new \ArrayObject([1, 2, 3]), 'int'));
    }

    // ========================================
    // Conflict detection
    // ========================================

    public function testDuplicateSingleTypeThrowsException(): void
    {
        $registry = $this->createRegistry();
        $registry->register(fn(string $s): string => $s);

        $this->assertThrows(
            fn() => $registry->register(fn(string $s): string => strtoupper($s)),
            \InvalidArgumentException::class
        );
    }

    public function testDuplicateUnionTypeThrowsException(): void
    {
        $registry = $this->createRegistry();
        $registry->register(fn(string|int $v): string => (string) $v);

        $this->assertThrows(
            fn() => $registry->register(fn(string|int $v): string => "again"),
            \InvalidArgumentException::class
        );
    }

    public function testOverlappingUnionsThrowException(): void
    {
        $registry = $this->createRegistry();
        $registry->register(fn(string|int $v): string => (string) $v);

        // int|bool overlaps with string|int on 'int'
        $this->assertThrows(
            fn() => $registry->register(fn(int|bool $v): string => (string) $v),
            \InvalidArgumentException::class
        );
    }

    // ========================================
    // replace() method
    // ========================================

    public function testReplaceOverwritesExistingConverter(): void
    {
        $registry = $this->createRegistry();
        $registry->register(fn(string $s): string => "original:$s");
        $registry->replace(fn(string $s): string => "replaced:$s");

        $this->assertSame('replaced:hello', $registry->convert('hello', 'string'));
    }

    public function testReplaceWorksWhenNoExistingConverter(): void
    {
        $registry = $this->createRegistry();
        // No existing converter - replace behaves like register
        $registry->replace(fn(string $s): string => "new:$s");

        $this->assertSame('new:hello', $registry->convert('hello', 'string'));
    }

    public function testReplaceUnionConverter(): void
    {
        $registry = $this->createRegistry();
        $registry->register(fn(string|int $v): string => "original");
        $registry->replace(fn(string|int $v): string => "replaced");

        $this->assertSame('replaced', $registry->convert('hello', 'string'));
        $this->assertSame('replaced', $registry->convert(42, 'string'));
    }

    // ========================================
    // Named targets
    // ========================================

    public function testNamedTargetRegistration(): void
    {
        $registry = $this->createRegistry();
        $registry->register(fn(\BackedEnum $e) => $e->value, 'sql-value');

        // Create a backed enum for testing
        $enum = TestStatus::Active;

        $this->assertTrue($registry->has($enum, 'sql-value'));
        $this->assertSame('active', $registry->convert($enum, 'sql-value'));
    }

    public function testMultipleNamedTargets(): void
    {
        $registry = $this->createRegistry();
        $registry->register(fn(string $s) => strtoupper($s), 'uppercase');
        $registry->register(fn(string $s) => strtolower($s), 'lowercase');

        $this->assertSame('HELLO', $registry->convert('Hello', 'uppercase'));
        $this->assertSame('hello', $registry->convert('Hello', 'lowercase'));
    }

    // ========================================
    // Multiple target types
    // ========================================

    public function testSameInputDifferentTargets(): void
    {
        $registry = $this->createRegistry();
        $registry->register(fn(string $s): int => strlen($s));
        $registry->register(fn(string $s): array => str_split($s));

        $this->assertSame(5, $registry->convert('hello', 'int'));
        $this->assertSame(['h', 'e', 'l', 'l', 'o'], $registry->convert('hello', 'array'));
    }

    public function testDifferentInputsSameTarget(): void
    {
        $registry = $this->createRegistry();
        $registry->register(fn(string $s): int => strlen($s));
        $registry->register(fn(array $a): int => count($a));

        $this->assertSame(5, $registry->convert('hello', 'int'));
        $this->assertSame(3, $registry->convert([1, 2, 3], 'int'));
    }

    // ========================================
    // Edge cases
    // ========================================

    public function testConverterNotCalledWhenSupportsReturnsFalse(): void
    {
        $registry = $this->createRegistry();
        // Register converter for Throwable -> string
        $registry->register(fn(\Throwable $e): string => $e->getMessage());

        // stdClass is not Throwable - should throw, not silently return null
        $this->assertThrows(
            fn() => $registry->convert(new \stdClass(), 'string'),
            \RuntimeException::class
        );
    }

    public function testBooleanTypes(): void
    {
        $registry = $this->createRegistry();
        $registry->register(fn(bool $b): string => $b ? 'yes' : 'no');

        $this->assertSame('yes', $registry->convert(true, 'string'));
        $this->assertSame('no', $registry->convert(false, 'string'));
    }

    public function testArrayType(): void
    {
        $registry = $this->createRegistry();
        $registry->register(fn(array $a): string => json_encode($a));

        $this->assertSame('[1,2,3]', $registry->convert([1, 2, 3], 'string'));
    }

    // ========================================
    // Error messages
    // ========================================

    public function testThrowsDescriptiveErrorMessage(): void
    {
        $registry = $this->createRegistry();

        try {
            $registry->convert('hello', 'SomeTarget');
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('string', $e->getMessage());
            $this->assertStringContainsString('SomeTarget', $e->getMessage());
        }
    }
};

// Helper enum for testing
enum TestStatus: string {
    case Active = 'active';
    case Inactive = 'inactive';
}

exit($test->run());
