<?php
/**
 * Test validator() helper function and ValidatorStore integration
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Test;
use mini\Validator\Validator;
use mini\Validator\ValidatorStore;
use mini\Validator\Attributes as V;

// Test class with attributes
#[V\Field(name: 'name', type: 'string', minLength: 1, required: true)]
#[V\Field(name: 'price', type: 'number', minimum: 0)]
class TestProduct {}

// Another test class
#[V\Field(name: 'title', type: 'string', required: true)]
class TestArticle {}

$test = new class extends Test {

    protected function setUp(): void
    {
        // Bootstrap Mini to register services
        \mini\bootstrap();
    }

    // ========================================
    // validator() without arguments
    // ========================================

    public function testValidatorWithoutArgumentsReturnsNewInstance(): void
    {
        $v1 = \mini\validator();
        $v2 = \mini\validator();

        $this->assertInstanceOf(Validator::class, $v1);
        $this->assertInstanceOf(Validator::class, $v2);
        $this->assertFalse($v1 === $v2, 'Should return different instances');
    }

    public function testValidatorWithoutArgumentsCanBuildRules(): void
    {
        $v = \mini\validator()
            ->type('string')
            ->minLength(5);

        $schema = $v->jsonSerialize();

        $this->assertSame('string', $schema['type']);
        $this->assertSame(5, $schema['minLength']);
    }

    // ========================================
    // validator() with class name - auto-building
    // ========================================

    public function testValidatorWithClassAutoBuildsFromAttributes(): void
    {
        $v = \mini\validator(TestProduct::class);

        $this->assertNotNull($v->name);
        $this->assertNotNull($v->price);
    }

    public function testValidatorWithClassReturnsClone(): void
    {
        // validator(Class) should return a clone to allow modification
        // without affecting the cached version
        $v1 = \mini\validator(TestProduct::class);
        $v2 = \mini\validator(TestProduct::class);

        $this->assertFalse($v1 === $v2, 'Should return clones, not same instance');
    }

    // ========================================
    // Caching behavior
    // ========================================

    public function testValidatorIsCachedInStore(): void
    {
        // First call builds and caches
        \mini\validator(TestArticle::class);

        // Check store directly
        $store = \mini\Mini::$mini->get(ValidatorStore::class);
        $this->assertTrue($store->has(TestArticle::class));
    }

    // ========================================
    // Manual registration
    // ========================================

    public function testManuallyRegisteredValidator(): void
    {
        $store = \mini\Mini::$mini->get(ValidatorStore::class);

        // Manually register validator
        $store['custom-email'] = \mini\validator()
            ->type('string')
            ->format('email')
            ->required();

        $v = \mini\validator('custom-email');
        $schema = $v->jsonSerialize();

        $this->assertSame('string', $schema['type']);
        $this->assertSame('email', $schema['format']);
    }

    // ========================================
    // Unknown identifiers
    // ========================================

    public function testUnknownIdentifierThrowsException(): void
    {
        $this->assertThrows(
            fn() => \mini\validator('NonExistentClass'),
            \InvalidArgumentException::class
        );
    }

    // ========================================
    // ValidatorStore direct access
    // ========================================

    public function testStoreArrayAccessSet(): void
    {
        $store = \mini\Mini::$mini->get(ValidatorStore::class);

        $store['test-validator'] = \mini\validator()->type('boolean');

        $this->assertTrue($store->has('test-validator'));
    }

    public function testStoreArrayAccessGet(): void
    {
        $store = \mini\Mini::$mini->get(ValidatorStore::class);

        $store['get-test'] = \mini\validator()->type('integer');

        $schema = $store['get-test']->jsonSerialize();
        $this->assertSame('integer', $schema['type']);
    }

    public function testStoreMagicGet(): void
    {
        $store = \mini\Mini::$mini->get(ValidatorStore::class);

        $store['magic-test'] = \mini\validator()->minLength(10);

        $v = $store->{'magic-test'};
        $this->assertInstanceOf(Validator::class, $v);
    }

    public function testStoreMagicGetThrowsForUnknown(): void
    {
        $store = \mini\Mini::$mini->get(ValidatorStore::class);

        $this->assertThrows(
            fn() => $store->unknownValidator,
            \RuntimeException::class
        );
    }

    // ========================================
    // Validation workflow
    // ========================================

    public function testCompleteValidationWorkflow(): void
    {
        // Define validator via class attributes
        $productValidator = \mini\validator(TestProduct::class);

        // Valid data
        $validProduct = ['name' => 'Widget', 'price' => 9.99];
        $this->assertNull($productValidator->isInvalid($validProduct));

        // Invalid data (missing required name)
        $invalidProduct = ['price' => 9.99];
        $errors = $productValidator->isInvalid($invalidProduct);
        $this->assertArrayHasKey('name', $errors);

        // Invalid data (negative price)
        $invalidProduct = ['name' => 'Widget', 'price' => -5];
        $errors = $productValidator->isInvalid($invalidProduct);
        $this->assertArrayHasKey('price', $errors);
    }
};

exit($test->run());
