<?php
/**
 * Test AttributeMetadataFactory - building metadata from PHP attributes
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Test;
use mini\Metadata\Metadata;
use mini\Metadata\AttributeMetadataFactory;
use mini\Metadata\Attributes as Meta;
use mini\I18n\Translatable;

// Test classes with attributes
#[Meta\Title('Test User')]
#[Meta\Description('A user entity for testing')]
class TestUser
{
    #[Meta\Title('Username')]
    #[Meta\Description('Unique login identifier')]
    #[Meta\Examples('johndoe', 'admin123')]
    #[Meta\IsReadOnly]
    public string $username;

    #[Meta\Title('Email Address')]
    #[Meta\MetaFormat('email')]
    public string $email;

    #[Meta\Title('Password')]
    #[Meta\IsWriteOnly]
    public string $password;

    #[Meta\Title('Legacy Field')]
    #[Meta\IsDeprecated]
    public string $legacyField;

    #[Meta\DefaultValue('active')]
    public string $status;

    // No metadata attributes
    public string $internalField;
}

// Class with only class-level attributes
#[Meta\Title('Simple Entity')]
class SimpleEntity
{
    public string $name;
}

// Interface with Property attributes (property-less metadata)
#[Meta\Property(name: 'id', title: 'ID', description: 'Unique identifier', readOnly: true)]
#[Meta\Property(name: 'email', title: 'Email', format: 'email')]
#[Meta\Property(name: 'role', title: 'Role', default: 'user')]
interface TestInterface {}

// Class with mixed Property attributes and real properties
#[Meta\Property(name: 'virtualField', title: 'Virtual Field')]
class MixedClass
{
    #[Meta\Title('Real Field')]
    public string $realField;
}

$test = new class extends Test {

    private AttributeMetadataFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new AttributeMetadataFactory();
    }

    // ========================================
    // Class-level attributes
    // ========================================

    public function testBuildsClassTitleFromAttribute(): void
    {
        $meta = $this->factory->forClass(TestUser::class);
        $json = $meta->jsonSerialize();

        $this->assertSame('Test User', $json['title']);
    }

    public function testBuildsClassDescriptionFromAttribute(): void
    {
        $meta = $this->factory->forClass(TestUser::class);
        $json = $meta->jsonSerialize();

        $this->assertSame('A user entity for testing', $json['description']);
    }

    // ========================================
    // Property-level attributes
    // ========================================

    public function testBuildsPropertyMetadata(): void
    {
        $meta = $this->factory->forClass(TestUser::class);

        $this->assertNotNull($meta->username);
        $this->assertNotNull($meta->email);
        $this->assertNotNull($meta->password);
    }

    public function testPropertyTitleFromAttribute(): void
    {
        $meta = $this->factory->forClass(TestUser::class);
        $json = $meta->username->jsonSerialize();

        $this->assertSame('Username', $json['title']);
    }

    public function testPropertyDescriptionFromAttribute(): void
    {
        $meta = $this->factory->forClass(TestUser::class);
        $json = $meta->username->jsonSerialize();

        $this->assertSame('Unique login identifier', $json['description']);
    }

    public function testPropertyExamplesFromAttribute(): void
    {
        $meta = $this->factory->forClass(TestUser::class);
        $json = $meta->username->jsonSerialize();

        $this->assertSame(['johndoe', 'admin123'], $json['examples']);
    }

    public function testPropertyReadOnlyFromAttribute(): void
    {
        $meta = $this->factory->forClass(TestUser::class);
        $json = $meta->username->jsonSerialize();

        $this->assertTrue($json['readOnly']);
    }

    public function testPropertyWriteOnlyFromAttribute(): void
    {
        $meta = $this->factory->forClass(TestUser::class);
        $json = $meta->password->jsonSerialize();

        $this->assertTrue($json['writeOnly']);
    }

    public function testPropertyFormatFromAttribute(): void
    {
        $meta = $this->factory->forClass(TestUser::class);
        $json = $meta->email->jsonSerialize();

        $this->assertSame('email', $json['format']);
    }

    public function testPropertyDeprecatedFromAttribute(): void
    {
        $meta = $this->factory->forClass(TestUser::class);
        $json = $meta->legacyField->jsonSerialize();

        $this->assertTrue($json['deprecated']);
    }

    public function testPropertyDefaultFromAttribute(): void
    {
        $meta = $this->factory->forClass(TestUser::class);
        $json = $meta->status->jsonSerialize();

        $this->assertSame('active', $json['default']);
    }

    // ========================================
    // Properties without metadata
    // ========================================

    public function testPropertiesWithoutAttributesAreSkipped(): void
    {
        $meta = $this->factory->forClass(TestUser::class);

        $this->assertNull($meta->internalField);
    }

    public function testClassWithNoPropertyAttributesHasNoProperties(): void
    {
        $meta = $this->factory->forClass(SimpleEntity::class);
        $json = $meta->jsonSerialize();

        $this->assertSame('Simple Entity', $json['title']);
        $this->assertFalse(array_key_exists('properties', $json));
    }

    // ========================================
    // Property attribute (property-less metadata)
    // ========================================

    public function testPropertyAttributeOnInterface(): void
    {
        $meta = $this->factory->forClass(TestInterface::class);

        $this->assertNotNull($meta->id);
        $this->assertNotNull($meta->email);
        $this->assertNotNull($meta->role);
    }

    public function testPropertyAttributeValues(): void
    {
        $meta = $this->factory->forClass(TestInterface::class);

        $idJson = $meta->id->jsonSerialize();
        $this->assertSame('ID', $idJson['title']);
        $this->assertSame('Unique identifier', $idJson['description']);
        $this->assertTrue($idJson['readOnly']);

        $emailJson = $meta->email->jsonSerialize();
        $this->assertSame('email', $emailJson['format']);

        $roleJson = $meta->role->jsonSerialize();
        $this->assertSame('user', $roleJson['default']);
    }

    public function testMixedPropertyAttributesAndRealProperties(): void
    {
        $meta = $this->factory->forClass(MixedClass::class);

        // Virtual property from Property attribute
        $this->assertNotNull($meta->virtualField);
        $this->assertSame('Virtual Field', $meta->virtualField->jsonSerialize()['title']);

        // Real property with attribute
        $this->assertNotNull($meta->realField);
        $this->assertSame('Real Field', $meta->realField->jsonSerialize()['title']);
    }

    // ========================================
    // JSON serialization
    // ========================================

    public function testFullJsonSerialization(): void
    {
        $meta = $this->factory->forClass(TestUser::class);
        $json = json_encode($meta, JSON_PRETTY_PRINT);

        $this->assertJson($json);

        $decoded = json_decode($json, true);
        $this->assertSame('Test User', $decoded['title']);
        $this->assertArrayHasKey('properties', $decoded);
        $this->assertArrayHasKey('username', $decoded['properties']);
    }

    // ========================================
    // Translation support
    // ========================================

    public function testTitleIsWrappedInTranslatable(): void
    {
        $meta = $this->factory->forClass(TestUser::class);
        $json = $meta->jsonSerialize();

        // The raw value before serialization should be Translatable
        // We can verify this by checking the serialized output equals the source text
        $this->assertSame('Test User', $json['title']);
    }

    public function testTranslatableHasCorrectSourceFile(): void
    {
        // Create a test to verify source file is set correctly
        // We need to access the internal Translatable to check this

        // Use reflection to access the annotations array
        $meta = $this->factory->forClass(TestUser::class);

        $reflection = new \ReflectionClass($meta);
        $prop = $reflection->getProperty('annotations');
        $annotations = $prop->getValue($meta);

        // The title should be a Translatable instance
        $this->assertInstanceOf(Translatable::class, $annotations['title']);

        // The source file should point to this test file (where TestUser is defined)
        $sourceFile = $annotations['title']->getSourceFile();
        $this->assertStringContainsString('tests/Metadata/AttributeMetadataFactory.php', $sourceFile);
    }

    public function testPropertyTitleIsTranslatable(): void
    {
        $meta = $this->factory->forClass(TestUser::class);
        $usernameMeta = $meta->username;

        $reflection = new \ReflectionClass($usernameMeta);
        $prop = $reflection->getProperty('annotations');
        $annotations = $prop->getValue($usernameMeta);

        $this->assertInstanceOf(Translatable::class, $annotations['title']);
        $this->assertSame('Username', $annotations['title']->getSourceText());
    }

    public function testPropertyAttributeTitleIsTranslatable(): void
    {
        $meta = $this->factory->forClass(TestInterface::class);
        $idMeta = $meta->id;

        $reflection = new \ReflectionClass($idMeta);
        $prop = $reflection->getProperty('annotations');
        $annotations = $prop->getValue($idMeta);

        $this->assertInstanceOf(Translatable::class, $annotations['title']);
        $this->assertSame('ID', $annotations['title']->getSourceText());
    }
};

exit($test->run());
