<?php
/**
 * Test AttributeValidatorFactory - building validators from PHP attributes
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use mini\Test;
use mini\Validator\Validator;
use mini\Validator\AttributeValidatorFactory;
use mini\Validator\Attributes as V;

// Test classes with attributes
#[V\Field(name: 'virtualField', type: 'string', minLength: 5)]
class TestUser
{
    #[V\Type('string')]
    #[V\MinLength(3)]
    #[V\MaxLength(20)]
    #[V\Required]
    public string $username;

    #[V\Type('string')]
    #[V\Format('email')]
    #[V\Required]
    public string $email;

    #[V\Type('integer')]
    #[V\Minimum(18)]
    #[V\Maximum(120)]
    public int $age;

    #[V\Pattern('/^[a-z]+$/')]
    public string $slug;

    // No validation attributes
    public string $internalField;
}

#[V\Field(name: 'id', type: 'string', pattern: '/^[A-Z]{2}\d{6}$/', required: true)]
#[V\Field(name: 'status', type: 'string', enum: ['active', 'pending', 'disabled'])]
#[V\Field(name: 'tags', type: 'array', minItems: 1, maxItems: 5, uniqueItems: true)]
interface TestInterface {}

class NumericConstraints
{
    #[V\Type('number')]
    #[V\ExclusiveMinimum(0)]
    #[V\ExclusiveMaximum(100)]
    #[V\MultipleOf(0.5)]
    public float $value;
}

class ArrayConstraints
{
    #[V\Type('array')]
    #[V\MinItems(1)]
    #[V\MaxItems(10)]
    #[V\UniqueItems]
    public array $items;
}

class ObjectConstraints
{
    #[V\Type('object')]
    #[V\MinProperties(1)]
    #[V\MaxProperties(5)]
    public array $data;
}

$test = new class extends Test {

    private AttributeValidatorFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new AttributeValidatorFactory();
    }

    // ========================================
    // Basic class validation
    // ========================================

    public function testBuildsValidatorFromClass(): void
    {
        $validator = $this->factory->forClass(TestUser::class);

        $this->assertInstanceOf(Validator::class, $validator);
    }

    public function testValidatorHasObjectType(): void
    {
        $validator = $this->factory->forClass(TestUser::class);
        $schema = $validator->jsonSerialize();

        $this->assertSame('object', $schema['type']);
    }

    // ========================================
    // Property validation
    // ========================================

    public function testBuildsPropertyValidators(): void
    {
        $validator = $this->factory->forClass(TestUser::class);

        $this->assertNotNull($validator->username);
        $this->assertNotNull($validator->email);
        $this->assertNotNull($validator->age);
    }

    public function testPropertyTypeAttribute(): void
    {
        $validator = $this->factory->forClass(TestUser::class);
        $schema = $validator->username->jsonSerialize();

        $this->assertSame('string', $schema['type']);
    }

    public function testPropertyMinLengthAttribute(): void
    {
        $validator = $this->factory->forClass(TestUser::class);
        $schema = $validator->username->jsonSerialize();

        $this->assertSame(3, $schema['minLength']);
    }

    public function testPropertyMaxLengthAttribute(): void
    {
        $validator = $this->factory->forClass(TestUser::class);
        $schema = $validator->username->jsonSerialize();

        $this->assertSame(20, $schema['maxLength']);
    }

    public function testPropertyFormatAttribute(): void
    {
        $validator = $this->factory->forClass(TestUser::class);
        $schema = $validator->email->jsonSerialize();

        $this->assertSame('email', $schema['format']);
    }

    public function testPropertyMinimumAttribute(): void
    {
        $validator = $this->factory->forClass(TestUser::class);
        $schema = $validator->age->jsonSerialize();

        $this->assertSame(18, $schema['minimum']);
    }

    public function testPropertyMaximumAttribute(): void
    {
        $validator = $this->factory->forClass(TestUser::class);
        $schema = $validator->age->jsonSerialize();

        $this->assertSame(120, $schema['maximum']);
    }

    public function testPropertyPatternAttribute(): void
    {
        $validator = $this->factory->forClass(TestUser::class);
        $schema = $validator->slug->jsonSerialize();

        $this->assertSame('/^[a-z]+$/', $schema['pattern']);
    }

    // ========================================
    // Required handling
    // ========================================

    public function testRequiredPropertiesInSchema(): void
    {
        $validator = $this->factory->forClass(TestUser::class);
        $schema = $validator->jsonSerialize();

        $this->assertArrayHasKey('required', $schema);
        $this->assertTrue(in_array('username', $schema['required']));
        $this->assertTrue(in_array('email', $schema['required']));
        $this->assertFalse(in_array('age', $schema['required']));
    }

    // ========================================
    // Properties without validation
    // ========================================

    public function testPropertiesWithoutAttributesAreSkipped(): void
    {
        $validator = $this->factory->forClass(TestUser::class);

        $this->assertNull($validator->internalField);
    }

    // ========================================
    // Field attribute (virtual fields)
    // ========================================

    public function testFieldAttributeCreatesValidator(): void
    {
        $validator = $this->factory->forClass(TestUser::class);

        $this->assertNotNull($validator->virtualField);
    }

    public function testFieldAttributeWithAllConstraints(): void
    {
        $validator = $this->factory->forClass(TestUser::class);
        $schema = $validator->virtualField->jsonSerialize();

        $this->assertSame('string', $schema['type']);
        $this->assertSame(5, $schema['minLength']);
    }

    public function testInterfaceFieldAttributes(): void
    {
        $validator = $this->factory->forClass(TestInterface::class);

        $this->assertNotNull($validator->id);
        $this->assertNotNull($validator->status);
        $this->assertNotNull($validator->tags);
    }

    public function testFieldAttributeWithEnum(): void
    {
        $validator = $this->factory->forClass(TestInterface::class);
        $schema = $validator->status->jsonSerialize();

        $this->assertSame(['active', 'pending', 'disabled'], $schema['enum']);
    }

    public function testFieldAttributeWithArrayConstraints(): void
    {
        $validator = $this->factory->forClass(TestInterface::class);
        $schema = $validator->tags->jsonSerialize();

        $this->assertSame('array', $schema['type']);
        $this->assertSame(1, $schema['minItems']);
        $this->assertSame(5, $schema['maxItems']);
        $this->assertTrue($schema['uniqueItems']);
    }

    // ========================================
    // Numeric constraints
    // ========================================

    public function testExclusiveMinimumAttribute(): void
    {
        $validator = $this->factory->forClass(NumericConstraints::class);
        $schema = $validator->value->jsonSerialize();

        $this->assertSame(0, $schema['exclusiveMinimum']);
    }

    public function testExclusiveMaximumAttribute(): void
    {
        $validator = $this->factory->forClass(NumericConstraints::class);
        $schema = $validator->value->jsonSerialize();

        $this->assertSame(100, $schema['exclusiveMaximum']);
    }

    public function testMultipleOfAttribute(): void
    {
        $validator = $this->factory->forClass(NumericConstraints::class);
        $schema = $validator->value->jsonSerialize();

        $this->assertSame(0.5, $schema['multipleOf']);
    }

    // ========================================
    // Array constraints
    // ========================================

    public function testArrayMinItemsAttribute(): void
    {
        $validator = $this->factory->forClass(ArrayConstraints::class);
        $schema = $validator->items->jsonSerialize();

        $this->assertSame(1, $schema['minItems']);
    }

    public function testArrayMaxItemsAttribute(): void
    {
        $validator = $this->factory->forClass(ArrayConstraints::class);
        $schema = $validator->items->jsonSerialize();

        $this->assertSame(10, $schema['maxItems']);
    }

    public function testArrayUniqueItemsAttribute(): void
    {
        $validator = $this->factory->forClass(ArrayConstraints::class);
        $schema = $validator->items->jsonSerialize();

        $this->assertTrue($schema['uniqueItems']);
    }

    // ========================================
    // Object constraints
    // ========================================

    public function testObjectMinPropertiesAttribute(): void
    {
        $validator = $this->factory->forClass(ObjectConstraints::class);
        $schema = $validator->data->jsonSerialize();

        $this->assertSame(1, $schema['minProperties']);
    }

    public function testObjectMaxPropertiesAttribute(): void
    {
        $validator = $this->factory->forClass(ObjectConstraints::class);
        $schema = $validator->data->jsonSerialize();

        $this->assertSame(5, $schema['maxProperties']);
    }

    // ========================================
    // Actual validation
    // ========================================

    public function testBuiltValidatorActuallyValidates(): void
    {
        $validator = $this->factory->forClass(TestUser::class);

        // Valid data
        $valid = [
            'username' => 'johndoe',
            'email' => 'john@example.com',
            'age' => 25,
            'slug' => 'myslug',
            'virtualField' => 'hello',
        ];
        $this->assertNull($validator->isInvalid($valid));

        // Invalid username (too short)
        $invalid = $valid;
        $invalid['username'] = 'ab';
        $errors = $validator->isInvalid($invalid);
        $this->assertArrayHasKey('username', $errors);

        // Invalid email
        $invalid = $valid;
        $invalid['email'] = 'not-an-email';
        $errors = $validator->isInvalid($invalid);
        $this->assertArrayHasKey('email', $errors);

        // Invalid age (too young)
        $invalid = $valid;
        $invalid['age'] = 15;
        $errors = $validator->isInvalid($invalid);
        $this->assertArrayHasKey('age', $errors);
    }
};

exit($test->run());
