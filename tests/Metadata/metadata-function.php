<?php
/**
 * Test metadata() helper function and MetadataStore integration
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use mini\Test;
use mini\Metadata\Metadata;
use mini\Metadata\MetadataStore;
use mini\Metadata\Attributes as Meta;

// Test class with attributes
#[Meta\Title('Product')]
#[Meta\Description('A product in the catalog')]
class TestProduct
{
    #[Meta\Title('Product Name')]
    public string $name;

    #[Meta\Title('Price')]
    #[Meta\MetaFormat('currency')]
    public float $price;
}

// Another test class
#[Meta\Title('Category')]
class TestCategory
{
    #[Meta\Title('Category Name')]
    public string $name;
}

$test = new class extends Test {

    protected function setUp(): void
    {
        // Bootstrap Mini to register services
        \mini\bootstrap();
    }

    // ========================================
    // metadata() without arguments
    // ========================================

    public function testMetadataWithoutArgumentsReturnsNewInstance(): void
    {
        $meta1 = \mini\metadata();
        $meta2 = \mini\metadata();

        $this->assertInstanceOf(Metadata::class, $meta1);
        $this->assertInstanceOf(Metadata::class, $meta2);
        $this->assertFalse($meta1 === $meta2, 'Should return different instances');
    }

    public function testMetadataWithoutArgumentsCanBuildAnnotations(): void
    {
        $meta = \mini\metadata()
            ->title('Custom Field')
            ->description('A custom description');

        $json = $meta->jsonSerialize();

        $this->assertSame('Custom Field', $json['title']);
        $this->assertSame('A custom description', $json['description']);
    }

    // ========================================
    // metadata() with class name - auto-building
    // ========================================

    public function testMetadataWithClassAutoBuildsFromAttributes(): void
    {
        $meta = \mini\metadata(TestProduct::class);
        $json = $meta->jsonSerialize();

        $this->assertSame('Product', $json['title']);
        $this->assertSame('A product in the catalog', $json['description']);
    }

    public function testMetadataWithClassBuildsPropertyMetadata(): void
    {
        $meta = \mini\metadata(TestProduct::class);

        $this->assertNotNull($meta->name);
        $this->assertNotNull($meta->price);

        $this->assertSame('Product Name', $meta->name->jsonSerialize()['title']);
        $this->assertSame('currency', $meta->price->jsonSerialize()['format']);
    }

    // ========================================
    // Caching behavior
    // ========================================

    public function testMetadataIsCachedAfterFirstCall(): void
    {
        // First call builds and caches
        $meta1 = \mini\metadata(TestCategory::class);

        // Second call returns cached instance
        $meta2 = \mini\metadata(TestCategory::class);

        $this->assertSame($meta1, $meta2);
    }

    public function testMetadataStoreCanBeAccessedDirectly(): void
    {
        $store = \mini\Mini::$mini->get(MetadataStore::class);

        // Access via store
        $meta = $store->get(TestProduct::class);

        $this->assertInstanceOf(Metadata::class, $meta);
        $this->assertSame('Product', $meta->jsonSerialize()['title']);
    }

    // ========================================
    // Manual registration
    // ========================================

    public function testManuallyRegisteredMetadataTakesPrecedence(): void
    {
        $store = \mini\Mini::$mini->get(MetadataStore::class);

        // Manually register metadata
        $store['CustomEntity'] = \mini\metadata()
            ->title('Custom Entity')
            ->description('Manually registered');

        $meta = \mini\metadata('CustomEntity');
        $json = $meta->jsonSerialize();

        $this->assertSame('Custom Entity', $json['title']);
        $this->assertSame('Manually registered', $json['description']);
    }

    public function testManualRegistrationOverridesAutoBuilt(): void
    {
        $store = \mini\Mini::$mini->get(MetadataStore::class);

        // First, trigger auto-build
        $auto = \mini\metadata(TestCategory::class);
        $this->assertSame('Category', $auto->jsonSerialize()['title']);

        // Override with manual registration
        $store[TestCategory::class] = \mini\metadata()
            ->title('Overridden Category');

        $manual = \mini\metadata(TestCategory::class);
        $this->assertSame('Overridden Category', $manual->jsonSerialize()['title']);
    }

    // ========================================
    // Unknown identifiers
    // ========================================

    public function testUnknownIdentifierReturnsEmptyMetadata(): void
    {
        $meta = \mini\metadata('NonExistentClass');

        $this->assertInstanceOf(Metadata::class, $meta);
        $this->assertSame([], $meta->jsonSerialize());
    }

    public function testUnknownIdentifierDoesNotThrow(): void
    {
        // Should not throw, just return empty metadata
        $meta = \mini\metadata('some.custom.identifier');

        $this->assertInstanceOf(Metadata::class, $meta);
    }

    // ========================================
    // MetadataStore array access
    // ========================================

    public function testStoreArrayAccessSet(): void
    {
        $store = \mini\Mini::$mini->get(MetadataStore::class);

        $store['ArrayAccessTest'] = \mini\metadata()->title('Array Access');

        $meta = $store->get('ArrayAccessTest');
        $this->assertSame('Array Access', $meta->jsonSerialize()['title']);
    }

    public function testStoreArrayAccessGet(): void
    {
        $store = \mini\Mini::$mini->get(MetadataStore::class);

        $store['GetTest'] = \mini\metadata()->title('Get Test');

        $this->assertSame('Get Test', $store['GetTest']->jsonSerialize()['title']);
    }

    public function testStoreHasMethod(): void
    {
        $store = \mini\Mini::$mini->get(MetadataStore::class);

        $this->assertFalse($store->has('NotRegistered'));

        $store['Registered'] = \mini\metadata()->title('Test');

        $this->assertTrue($store->has('Registered'));
    }
};

exit($test->run());
