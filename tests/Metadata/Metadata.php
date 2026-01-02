<?php
/**
 * Test Metadata class - annotation builder
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Test;
use mini\Metadata\Metadata;

$test = new class extends Test {

    protected function setUp(): void
    {
        // Metadata class is standalone, no bootstrap needed for basic tests
    }

    // ========================================
    // Basic annotation methods
    // ========================================

    public function testTitleSetsAnnotation(): void
    {
        $meta = (new Metadata())->title('Username');
        $json = $meta->jsonSerialize();

        $this->assertSame('Username', $json['title']);
    }

    public function testDescriptionSetsAnnotation(): void
    {
        $meta = (new Metadata())->description('A detailed description');
        $json = $meta->jsonSerialize();

        $this->assertSame('A detailed description', $json['description']);
    }

    public function testDefaultSetsAnnotation(): void
    {
        $meta = (new Metadata())->default('default_value');
        $json = $meta->jsonSerialize();

        $this->assertSame('default_value', $json['default']);
    }

    public function testExamplesSetsAnnotation(): void
    {
        $meta = (new Metadata())->examples('johndoe', 'admin123');
        $json = $meta->jsonSerialize();

        $this->assertSame(['johndoe', 'admin123'], $json['examples']);
    }

    public function testReadOnlySetsAnnotation(): void
    {
        $meta = (new Metadata())->readOnly();
        $json = $meta->jsonSerialize();

        $this->assertTrue($json['readOnly']);
    }

    public function testWriteOnlySetsAnnotation(): void
    {
        $meta = (new Metadata())->writeOnly();
        $json = $meta->jsonSerialize();

        $this->assertTrue($json['writeOnly']);
    }

    public function testDeprecatedSetsAnnotation(): void
    {
        $meta = (new Metadata())->deprecated();
        $json = $meta->jsonSerialize();

        $this->assertTrue($json['deprecated']);
    }

    public function testFormatSetsAnnotation(): void
    {
        $meta = (new Metadata())->format('email');
        $json = $meta->jsonSerialize();

        $this->assertSame('email', $json['format']);
    }

    // ========================================
    // Immutability
    // ========================================

    public function testMethodsReturnNewInstance(): void
    {
        $original = new Metadata();
        $modified = $original->title('Test');

        $this->assertFalse($original === $modified, 'Should return different instance');
        $this->assertArrayHasKey('title', $modified->jsonSerialize());
        $this->assertSame([], $original->jsonSerialize());
    }

    public function testChainedMethodsPreserveAllValues(): void
    {
        $meta = (new Metadata())
            ->title('Username')
            ->description('User login identifier')
            ->examples('john', 'jane')
            ->readOnly();

        $json = $meta->jsonSerialize();

        $this->assertSame('Username', $json['title']);
        $this->assertSame('User login identifier', $json['description']);
        $this->assertSame(['john', 'jane'], $json['examples']);
        $this->assertTrue($json['readOnly']);
    }

    // ========================================
    // Null handling
    // ========================================

    public function testNullFormatRemovesAnnotation(): void
    {
        $meta = (new Metadata())
            ->format('email')
            ->format(null);

        $json = $meta->jsonSerialize();

        $this->assertFalse(array_key_exists('format', $json));
    }

    public function testNullTitleRemovesAnnotation(): void
    {
        $meta = (new Metadata())
            ->title('Username')
            ->title(null);

        $json = $meta->jsonSerialize();

        $this->assertFalse(array_key_exists('title', $json));
    }

    // ========================================
    // Properties (nested metadata)
    // ========================================

    public function testPropertiesSetsNestedMetadata(): void
    {
        $meta = (new Metadata())
            ->title('User')
            ->properties([
                'username' => (new Metadata())->title('Username'),
                'email' => (new Metadata())->title('Email')->format('email'),
            ]);

        $json = $meta->jsonSerialize();

        $this->assertArrayHasKey('properties', $json);
        $this->assertArrayHasKey('username', $json['properties']);
        $this->assertArrayHasKey('email', $json['properties']);
    }

    public function testMagicGetAccessesPropertyMetadata(): void
    {
        $meta = (new Metadata())
            ->properties([
                'username' => (new Metadata())->title('Username'),
            ]);

        $usernameMeta = $meta->username;

        $this->assertInstanceOf(Metadata::class, $usernameMeta);
        $this->assertSame('Username', $usernameMeta->jsonSerialize()['title']);
    }

    public function testMagicGetReturnsNullForUnknownProperty(): void
    {
        $meta = new Metadata();

        $this->assertNull($meta->nonexistent);
    }

    public function testPropertiesThrowsForNonMetadataValue(): void
    {
        $this->assertThrows(
            fn() => (new Metadata())->properties(['bad' => 'not metadata']),
            \InvalidArgumentException::class
        );
    }

    // ========================================
    // Items (array metadata)
    // ========================================

    public function testItemsSetsArrayMetadata(): void
    {
        $meta = (new Metadata())
            ->title('Integer Array')
            ->items((new Metadata())->title('Integer'));

        $json = $meta->jsonSerialize();

        $this->assertArrayHasKey('items', $json);
        $this->assertInstanceOf(Metadata::class, $json['items']);
    }

    // ========================================
    // JSON serialization
    // ========================================

    public function testEmptyMetadataSerializesToEmptyArray(): void
    {
        $meta = new Metadata();
        $this->assertSame([], $meta->jsonSerialize());
    }

    public function testJsonEncodeWorks(): void
    {
        $meta = (new Metadata())
            ->title('Test')
            ->description('A test field');

        $json = json_encode($meta);

        $this->assertJson($json);
        $decoded = json_decode($json, true);
        $this->assertSame('Test', $decoded['title']);
        $this->assertSame('A test field', $decoded['description']);
    }

    // ========================================
    // Stringable support (for Translatable)
    // ========================================

    public function testStringableValuesAreConverted(): void
    {
        $stringable = new class implements \Stringable {
            public function __toString(): string {
                return 'Translated Title';
            }
        };

        $meta = (new Metadata())->title($stringable);
        $json = $meta->jsonSerialize();

        $this->assertSame('Translated Title', $json['title']);
    }

    public function testStringableInExamplesAreConverted(): void
    {
        $stringable = new class implements \Stringable {
            public function __toString(): string {
                return 'example_value';
            }
        };

        $meta = (new Metadata())->examples($stringable, 'plain');
        $json = $meta->jsonSerialize();

        $this->assertSame(['example_value', 'plain'], $json['examples']);
    }

    // ========================================
    // Deep cloning
    // ========================================

    public function testCloneDeepCopiesProperties(): void
    {
        $original = (new Metadata())
            ->properties([
                'field' => (new Metadata())->title('Original'),
            ]);

        $clone = clone $original;

        // Verify the property metadata objects are different instances
        $this->assertFalse($original->field === $clone->field, 'Cloned properties should be different instances');
    }
};

exit($test->run());
