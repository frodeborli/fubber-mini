<?php
/**
 * Test Metadata system from a developer's perspective
 *
 * These tests mirror how developers would actually use the metadata API
 * in real applications.
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Test;
use mini\Metadata\Metadata;
use mini\Metadata\Attributes as Meta;

// ============================================================================
// Test entities - realistic domain models
// ============================================================================

class Group
{
    public string $name;
}

#[Meta\Title('User Account')]
#[Meta\Description('Represents a user in the system')]
class User
{
    #[Meta\Title('Username')]
    #[Meta\Description('Unique login identifier')]
    #[Meta\Examples('johndoe', 'admin')]
    #[Meta\IsReadOnly]
    public string $username;

    #[Meta\Title('Email')]
    #[Meta\MetaFormat('email')]
    public string $email;

    #[Meta\Title('Age')]
    #[Meta\Examples(25, 30, 45)]
    public int $age;

    #[Meta\Title('Active')]
    #[Meta\DefaultValue(true)]
    public bool $isActive;

    // Property with object type but no metadata
    public Group $group;

    // Property with no metadata at all
    public string $internalNote;
}

#[Meta\Property(name: 'id', title: 'ID', readOnly: true)]
#[Meta\Property(name: 'name', title: 'Name', description: 'Display name')]
interface EntityInterface {}

$test = new class extends Test {

    // ========================================
    // Basic class metadata access
    // ========================================

    public function testGetClassTitle(): void
    {
        $meta = \mini\metadata(User::class);
        $json = $meta->jsonSerialize();

        $this->assertSame('User Account', $json['title']);
    }

    public function testGetClassDescription(): void
    {
        $meta = \mini\metadata(User::class);
        $json = $meta->jsonSerialize();

        $this->assertSame('Represents a user in the system', $json['description']);
    }

    // ========================================
    // Property metadata access via magic getter
    // ========================================

    public function testAccessPropertyMetadataViaMagicGetter(): void
    {
        $meta = \mini\metadata(User::class);

        // This is the primary way developers access property metadata
        $usernameMeta = $meta->username;

        $this->assertNotNull($usernameMeta);
        $this->assertInstanceOf(Metadata::class, $usernameMeta);
    }

    public function testPropertyMetadataContainsExpectedValues(): void
    {
        $meta = \mini\metadata(User::class);
        $json = $meta->username->jsonSerialize();

        $this->assertSame('Username', $json['title']);
        $this->assertSame('Unique login identifier', $json['description']);
        $this->assertSame(['johndoe', 'admin'], $json['examples']);
        $this->assertTrue($json['readOnly']);
    }

    public function testPropertyWithFormatAttribute(): void
    {
        $meta = \mini\metadata(User::class);
        $json = $meta->email->jsonSerialize();

        $this->assertSame('Email', $json['title']);
        $this->assertSame('email', $json['format']);
    }

    public function testPropertyWithNumericExamples(): void
    {
        $meta = \mini\metadata(User::class);
        $json = $meta->age->jsonSerialize();

        $this->assertSame('Age', $json['title']);
        $this->assertSame([25, 30, 45], $json['examples']);
    }

    public function testPropertyWithBooleanDefault(): void
    {
        $meta = \mini\metadata(User::class);
        $json = $meta->isActive->jsonSerialize();

        $this->assertSame('Active', $json['title']);
        $this->assertTrue($json['default']);
    }

    // ========================================
    // Properties without explicit metadata
    // ========================================

    public function testPropertyWithClassTypeAutoResolvesMetadata(): void
    {
        $meta = \mini\metadata(User::class);

        // Object-typed property resolves to the type's metadata
        $this->assertNotNull($meta->group);
        // Group class has no metadata, so it's empty
        $this->assertSame([], $meta->group->jsonSerialize());
    }

    public function testPropertyWithoutMetadataOrTypeReturnsNull(): void
    {
        $meta = \mini\metadata(User::class);

        // String property without metadata attributes returns null
        // (builtin types don't auto-resolve)
        $this->assertNull($meta->internalNote);
    }

    public function testNonExistentPropertyReturnsNull(): void
    {
        $meta = \mini\metadata(User::class);

        $this->assertNull($meta->nonExistentProperty);
    }

    // ========================================
    // Interface with Property attributes
    // ========================================

    public function testInterfacePropertyAttributes(): void
    {
        $meta = \mini\metadata(EntityInterface::class);

        $this->assertNotNull($meta->id);
        $this->assertNotNull($meta->name);

        $idJson = $meta->id->jsonSerialize();
        $this->assertSame('ID', $idJson['title']);
        $this->assertTrue($idJson['readOnly']);

        $nameJson = $meta->name->jsonSerialize();
        $this->assertSame('Name', $nameJson['title']);
        $this->assertSame('Display name', $nameJson['description']);
    }

    // ========================================
    // Class without metadata
    // ========================================

    public function testClassWithoutMetadataReturnsEmptyMetadata(): void
    {
        $meta = \mini\metadata(Group::class);
        $json = $meta->jsonSerialize();

        // Should return empty metadata, not null
        $this->assertSame([], $json);
    }

    // ========================================
    // JSON serialization for API responses
    // ========================================

    public function testFullJsonSerializationForApiDocs(): void
    {
        $meta = \mini\metadata(User::class);
        $json = json_encode($meta, JSON_PRETTY_PRINT);

        $this->assertJson($json);

        $data = json_decode($json, true);

        // Class-level metadata
        $this->assertSame('User Account', $data['title']);
        $this->assertSame('Represents a user in the system', $data['description']);

        // Properties with explicit metadata
        $this->assertArrayHasKey('properties', $data);
        $this->assertArrayHasKey('username', $data['properties']);
        $this->assertArrayHasKey('email', $data['properties']);

        // Class-typed property (group) IS included via auto-resolution
        $this->assertArrayHasKey('group', $data['properties']);

        // Builtin-typed property without metadata should NOT appear
        $this->assertFalse(array_key_exists('internalNote', $data['properties']));
    }

    // ========================================
    // Programmatic metadata building
    // ========================================

    public function testBuildMetadataProgrammatically(): void
    {
        $meta = \mini\metadata()
            ->title('Custom Entity')
            ->description('Built programmatically')
            ->properties([
                'field1' => \mini\metadata()->title('Field One'),
                'field2' => \mini\metadata()->title('Field Two')->readOnly(),
            ]);

        $json = $meta->jsonSerialize();

        $this->assertSame('Custom Entity', $json['title']);
        $this->assertSame('Field One', $meta->field1->jsonSerialize()['title']);
        $this->assertTrue($meta->field2->jsonSerialize()['readOnly']);
    }

    // ========================================
    // Caching behavior
    // ========================================

    public function testMetadataIsCachedBetweenCalls(): void
    {
        $meta1 = \mini\metadata(User::class);
        $meta2 = \mini\metadata(User::class);

        // Same instance should be returned
        $this->assertTrue($meta1 === $meta2);
    }

    // ========================================
    // Unknown class/identifier
    // ========================================

    public function testUnknownClassReturnsEmptyMetadata(): void
    {
        $meta = \mini\metadata('App\\NonExistent\\Class');

        $this->assertInstanceOf(Metadata::class, $meta);
        $this->assertSame([], $meta->jsonSerialize());
    }

    // ========================================
    // Ref attribute for class references
    // ========================================

    public function testRefAttributeOverridesTypeHint(): void
    {
        // RefTestUser has $group typed as RefTestBaseGroup but with Ref(RefTestAdminGroup::class)
        $meta = \mini\metadata(RefTestUser::class);

        // Should resolve to AdminGroup metadata, not BaseGroup
        $this->assertSame('Admin Group', $meta->group->jsonSerialize()['title']);
    }

    public function testAutoResolveFromTypeHint(): void
    {
        // AutoResolveUser has $group typed as AutoResolveGroup with no Ref attribute
        $meta = \mini\metadata(AutoResolveUser::class);

        $this->assertSame('Auto Group', $meta->group->jsonSerialize()['title']);
    }

    public function testRefWithExplicitMetadataCombined(): void
    {
        // RefWithMetaUser has $group with both Ref and Title attributes
        $meta = \mini\metadata(RefWithMetaUser::class);

        // The property-level title should be used, but the ref resolves for nested access
        $groupPropMeta = $meta->group;
        $this->assertSame('User Group', $groupPropMeta->jsonSerialize()['title']);
    }
};

// Additional test classes for Ref tests
#[Meta\Title('Base Group')]
class RefTestBaseGroup {}

#[Meta\Title('Admin Group')]
class RefTestAdminGroup {}

class RefTestUser {
    #[Meta\Ref(RefTestAdminGroup::class)]
    public RefTestBaseGroup $group;
}

#[Meta\Title('Auto Group')]
class AutoResolveGroup {}

class AutoResolveUser {
    public AutoResolveGroup $group;
}

#[Meta\Title('Meta Group')]
class RefTestMetaGroup {}

class RefWithMetaUser {
    #[Meta\Title('User Group')]
    #[Meta\Ref(RefTestMetaGroup::class)]
    public $group;
}

exit($test->run());
