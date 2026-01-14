<?php
/**
 * Extensive tests for purpose-based validation attributes
 *
 * Tests the Jakarta/Symfony "groups" pattern implementation where validation
 * attributes can be scoped to specific purposes (Create, Update, custom).
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Test;
use mini\Validator\Validator;
use mini\Validator\ValidatorStore;
use mini\Validator\AttributeValidatorFactory;
use mini\Validator\Purpose;
use mini\Validator\Attributes as V;

// ============================================================================
// Test Entity Classes
// ============================================================================

/**
 * Entity with all attribute types and mixed purposes
 */
class FullEntity
{
    // Core attributes (always apply)
    #[V\Type('string')]
    #[V\Required]
    #[V\MinLength(1)]
    #[V\MaxLength(255)]
    #[V\Format('email')]
    public string $email;

    #[V\Type('string')]
    #[V\Pattern('/^[a-z][a-z0-9_]*$/')]
    public string $username;

    #[V\Type('integer')]
    #[V\Minimum(0)]
    #[V\Maximum(150)]
    public int $age;

    #[V\Type('number')]
    #[V\ExclusiveMinimum(0)]
    #[V\ExclusiveMaximum(1000000)]
    #[V\MultipleOf(0.01)]
    public float $balance;

    #[V\Type('array')]
    #[V\MinItems(0)]
    #[V\MaxItems(10)]
    #[V\UniqueItems]
    public array $tags;

    #[V\Type('object')]
    #[V\MinProperties(0)]
    #[V\MaxProperties(20)]
    public array $metadata;

    #[V\Enum(['active', 'inactive'])]
    public string $defaultStatus;

    #[V\Enum(['low', 'medium', 'high'])]
    public string $priority;

    // Create-only attributes
    #[V\Required(purpose: Purpose::Create)]
    #[V\MinLength(8, purpose: Purpose::Create)]
    #[V\MaxLength(128, purpose: Purpose::Create)]
    #[V\Pattern('/[A-Z]/', message: 'Must contain uppercase', purpose: Purpose::Create)]
    #[V\Pattern('/[0-9]/', message: 'Must contain number', purpose: Purpose::Create)]
    public ?string $password = null;

    #[V\Type('string', purpose: Purpose::Create)]
    #[V\Required(purpose: Purpose::Create)]
    public ?string $passwordConfirm = null;

    // Update-only attributes
    #[V\Required(purpose: Purpose::Update)]
    #[V\Type('integer', purpose: Purpose::Update)]
    #[V\Minimum(1, purpose: Purpose::Update)]
    public ?int $id = null;

    #[V\Type('string', purpose: Purpose::Update)]
    #[V\Format('date-time', purpose: Purpose::Update)]
    public ?string $updatedAt = null;

    // No validation attributes
    public ?string $internalNote = null;
}

/**
 * Entity with repeatable attributes (same attribute, different purposes)
 */
class RepeatableEntity
{
    // Different minLength per purpose
    #[V\MinLength(1)]
    #[V\MinLength(3, purpose: Purpose::Create)]
    #[V\MinLength(1, purpose: Purpose::Update)]
    public string $name;

    // Different required status per purpose
    #[V\Required(purpose: Purpose::Create)]
    public ?string $requiredOnCreate = null;

    #[V\Required(purpose: Purpose::Update)]
    public ?string $requiredOnUpdate = null;

    // Different patterns per purpose
    #[V\Pattern('/^[a-z]+$/', purpose: Purpose::Create)]
    #[V\Pattern('/^[a-z0-9]+$/', purpose: Purpose::Update)]
    public ?string $slug = null;

    // Different ranges per purpose
    #[V\Minimum(0)]
    #[V\Minimum(1, purpose: Purpose::Create)]
    #[V\Minimum(-100, purpose: Purpose::Update)]
    public int $score;

    #[V\Maximum(100)]
    #[V\Maximum(50, purpose: Purpose::Create)]
    #[V\Maximum(200, purpose: Purpose::Update)]
    public int $limit;
}

/**
 * Entity with custom string purposes
 */
class CustomPurposeEntity
{
    // Password reset purpose
    #[V\Required(purpose: 'password-reset')]
    #[V\MinLength(12, purpose: 'password-reset')]
    #[V\Pattern('/[!@#$%^&*]/', message: 'Must contain special char', purpose: 'password-reset')]
    public ?string $newPassword = null;

    // Admin override purpose
    #[V\Required(purpose: 'admin-override')]
    #[V\Format('email', purpose: 'admin-override')]
    public ?string $adminEmail = null;

    // Import purpose (relaxed validation)
    #[V\Type('string', purpose: 'import')]
    #[V\MaxLength(1000, purpose: 'import')]
    public ?string $rawData = null;

    // Export purpose
    #[V\Required(purpose: 'export')]
    #[V\Enum(['csv', 'json', 'xml'], purpose: 'export')]
    public ?string $format = null;
}

/**
 * Entity with all numeric constraints
 */
class NumericEntity
{
    #[V\Minimum(0)]
    #[V\Minimum(10, purpose: Purpose::Create)]
    public int $min;

    #[V\Maximum(100)]
    #[V\Maximum(50, purpose: Purpose::Create)]
    public int $max;

    #[V\ExclusiveMinimum(0)]
    #[V\ExclusiveMinimum(5, purpose: Purpose::Create)]
    public float $exclusiveMin;

    #[V\ExclusiveMaximum(100)]
    #[V\ExclusiveMaximum(95, purpose: Purpose::Create)]
    public float $exclusiveMax;

    #[V\MultipleOf(1)]
    #[V\MultipleOf(5, purpose: Purpose::Create)]
    public int $step;
}

/**
 * Entity with all string constraints
 */
class StringEntity
{
    #[V\MinLength(1)]
    #[V\MinLength(5, purpose: Purpose::Create)]
    public string $minLen;

    #[V\MaxLength(100)]
    #[V\MaxLength(50, purpose: Purpose::Create)]
    public string $maxLen;

    #[V\Pattern('/^[a-z]+$/')]
    #[V\Pattern('/^[a-z]{3,}$/', purpose: Purpose::Create)]
    public string $pattern;

    #[V\Format('email')]
    #[V\Format('uri', purpose: Purpose::Create)]
    public string $format;
}

/**
 * Entity with all array constraints
 */
class ArrayEntity
{
    #[V\MinItems(0)]
    #[V\MinItems(1, purpose: Purpose::Create)]
    public array $minItems;

    #[V\MaxItems(100)]
    #[V\MaxItems(10, purpose: Purpose::Create)]
    public array $maxItems;

    #[V\UniqueItems]
    public array $coreUnique;

    #[V\UniqueItems(purpose: Purpose::Create)]
    public array $createUnique;
}

/**
 * Entity with all object constraints
 */
class ObjectEntity
{
    #[V\MinProperties(0)]
    #[V\MinProperties(1, purpose: Purpose::Create)]
    public array $minProps;

    #[V\MaxProperties(100)]
    #[V\MaxProperties(5, purpose: Purpose::Create)]
    public array $maxProps;
}

/**
 * Entity with enum per purpose
 */
class EnumEntity
{
    #[V\Enum(['pending', 'active', 'disabled'])]
    #[V\Enum(['pending'], purpose: Purpose::Create)]
    #[V\Enum(['active', 'disabled'], purpose: Purpose::Update)]
    public string $status;

    #[V\Enum(['a', 'b', 'c'])]
    #[V\Enum(['x', 'y'], purpose: Purpose::Create)]
    #[V\Enum(['p', 'q', 'r', 's'], purpose: Purpose::Update)]
    public string $type;
}

/**
 * Interface with Field attributes and purposes
 */
#[V\Field(name: 'coreField', type: 'string', required: true, minLength: 1)]
#[V\Field(name: 'createField', type: 'string', required: true, minLength: 5, purpose: Purpose::Create)]
#[V\Field(name: 'updateField', type: 'integer', required: true, minimum: 1, purpose: Purpose::Update)]
#[V\Field(name: 'customField', type: 'string', format: 'email', purpose: 'custom-purpose')]
interface FieldInterface {}

/**
 * Entity mixing property and virtual field validation
 */
#[V\Field(name: 'virtualCore', type: 'string', required: true)]
#[V\Field(name: 'virtualCreate', type: 'string', minLength: 10, purpose: Purpose::Create)]
class MixedEntity
{
    #[V\Required]
    #[V\Type('string')]
    public string $realProperty;

    #[V\Required(purpose: Purpose::Create)]
    public ?string $createOnly = null;
}

/**
 * Empty entity (no validation attributes)
 */
class EmptyEntity
{
    public string $name;
    public int $age;
}

/**
 * Entity with only purpose-specific attributes (no core)
 */
class PurposeOnlyEntity
{
    #[V\Required(purpose: Purpose::Create)]
    #[V\MinLength(5, purpose: Purpose::Create)]
    public ?string $createField = null;

    #[V\Required(purpose: Purpose::Update)]
    #[V\Minimum(1, purpose: Purpose::Update)]
    public ?int $updateField = null;
}

// ============================================================================
// Adversarial Test Classes
// ============================================================================

/**
 * Entity with conflicting constraints (stricter create than core)
 */
class ConflictingConstraintsEntity
{
    // Core allows 1-100, Create requires 50-75 (subset)
    #[V\Minimum(1)]
    #[V\Maximum(100)]
    #[V\Minimum(50, purpose: Purpose::Create)]
    #[V\Maximum(75, purpose: Purpose::Create)]
    public int $value;
}

/**
 * Entity with many purposes on same property
 */
class ManyPurposesEntity
{
    #[V\MinLength(1)]
    #[V\MinLength(5, purpose: Purpose::Create)]
    #[V\MinLength(3, purpose: Purpose::Update)]
    #[V\MinLength(10, purpose: 'strict')]
    #[V\MinLength(2, purpose: 'relaxed')]
    public string $field;
}

/**
 * Entity with special characters in purpose strings
 */
class SpecialPurposeEntity
{
    #[V\Required(purpose: 'with-hyphen')]
    public ?string $hyphen = null;

    #[V\Required(purpose: 'with_underscore')]
    public ?string $underscore = null;

    #[V\Required(purpose: 'CamelCase')]
    public ?string $camel = null;

    #[V\Required(purpose: 'with.dot')]
    public ?string $dot = null;

    #[V\Required(purpose: 'with:colon')]
    public ?string $colon = null;
}

/**
 * Entity with empty/whitespace edge cases
 */
class WhitespaceEntity
{
    #[V\MinLength(0)]
    #[V\MinLength(1, purpose: Purpose::Create)]
    public string $allowEmpty;

    #[V\Pattern('/\S+/')]
    public string $noWhitespaceOnly;
}

// ============================================================================
// Tests
// ============================================================================

$test = new class extends Test {

    private AttributeValidatorFactory $factory;

    protected function setUp(): void
    {
        \mini\bootstrap();
        $this->factory = new AttributeValidatorFactory();
    }

    /**
     * Helper to check if property is in required array
     */
    private function isRequired(Validator $v, string $prop): bool
    {
        $schema = $v->jsonSerialize();
        return isset($schema['required']) && in_array($prop, $schema['required']);
    }

    // ========================================
    // Core validator tests
    // ========================================

    public function testCoreValidatorIncludesCoreAttributes(): void
    {
        $v = $this->factory->forClass(FullEntity::class);

        // All core properties should have validators
        $this->assertNotNull($v->email);
        $this->assertNotNull($v->username);
        $this->assertNotNull($v->age);
        $this->assertNotNull($v->balance);
        $this->assertNotNull($v->tags);
        $this->assertNotNull($v->metadata);
        $this->assertNotNull($v->defaultStatus);
        $this->assertNotNull($v->priority);
    }

    public function testCoreValidatorExcludesPurposeAttributes(): void
    {
        $v = $this->factory->forClass(FullEntity::class);

        // Purpose-specific properties should NOT have validators in core
        $this->assertNull($v->password);
        $this->assertNull($v->passwordConfirm);
        $this->assertNull($v->id);
        $this->assertNull($v->updatedAt);
    }

    public function testCoreValidatorExcludesPropertiesWithoutAttributes(): void
    {
        $v = $this->factory->forClass(FullEntity::class);

        $this->assertNull($v->internalNote);
    }

    public function testCoreValidatorHasCorrectConstraints(): void
    {
        $v = $this->factory->forClass(FullEntity::class);

        // Check email constraints
        $emailSchema = $v->email->jsonSerialize();
        $this->assertSame('string', $emailSchema['type']);
        $this->assertSame(1, $emailSchema['minLength']);
        $this->assertSame(255, $emailSchema['maxLength']);
        $this->assertSame('email', $emailSchema['format']);
        $this->assertTrue($this->isRequired($v, 'email'));

        // Check age constraints
        $ageSchema = $v->age->jsonSerialize();
        $this->assertSame('integer', $ageSchema['type']);
        $this->assertSame(0, $ageSchema['minimum']);
        $this->assertSame(150, $ageSchema['maximum']);

        // Check balance constraints
        $balanceSchema = $v->balance->jsonSerialize();
        $this->assertSame('number', $balanceSchema['type']);
        $this->assertSame(0, $balanceSchema['exclusiveMinimum']);
        $this->assertSame(1000000, $balanceSchema['exclusiveMaximum']);
        $this->assertSame(0.01, $balanceSchema['multipleOf']);
    }

    // ========================================
    // Create purpose validator tests
    // ========================================

    public function testCreateValidatorIncludesCreateAttributes(): void
    {
        $v = $this->factory->forClass(FullEntity::class, Purpose::Create);

        // Create-specific properties should have validators
        $this->assertNotNull($v->password);
        $this->assertNotNull($v->passwordConfirm);
    }

    public function testCreateValidatorExcludesCoreAttributes(): void
    {
        $v = $this->factory->forClass(FullEntity::class, Purpose::Create);

        // Core properties should NOT have validators
        $this->assertNull($v->email);
        $this->assertNull($v->username);
        $this->assertNull($v->age);
    }

    public function testCreateValidatorExcludesUpdateAttributes(): void
    {
        $v = $this->factory->forClass(FullEntity::class, Purpose::Create);

        // Update-specific should NOT have validators
        $this->assertNull($v->id);
        $this->assertNull($v->updatedAt);
    }

    public function testCreateValidatorHasCorrectConstraints(): void
    {
        $v = $this->factory->forClass(FullEntity::class, Purpose::Create);

        $pwSchema = $v->password->jsonSerialize();
        $this->assertSame(8, $pwSchema['minLength']);
        $this->assertSame(128, $pwSchema['maxLength']);
        $this->assertTrue($this->isRequired($v, 'password'));
        $this->assertTrue($this->isRequired($v, 'passwordConfirm'));
    }

    // ========================================
    // Update purpose validator tests
    // ========================================

    public function testUpdateValidatorIncludesUpdateAttributes(): void
    {
        $v = $this->factory->forClass(FullEntity::class, Purpose::Update);

        $this->assertNotNull($v->id);
        $this->assertNotNull($v->updatedAt);
    }

    public function testUpdateValidatorExcludesCoreAndCreateAttributes(): void
    {
        $v = $this->factory->forClass(FullEntity::class, Purpose::Update);

        $this->assertNull($v->email);
        $this->assertNull($v->password);
    }

    public function testUpdateValidatorHasCorrectConstraints(): void
    {
        $v = $this->factory->forClass(FullEntity::class, Purpose::Update);

        $idSchema = $v->id->jsonSerialize();
        $this->assertSame('integer', $idSchema['type']);
        $this->assertSame(1, $idSchema['minimum']);
        $this->assertTrue($this->isRequired($v, 'id'));

        $updatedSchema = $v->updatedAt->jsonSerialize();
        $this->assertSame('string', $updatedSchema['type']);
        $this->assertSame('date-time', $updatedSchema['format']);
    }

    // ========================================
    // Repeatable attributes tests
    // ========================================

    public function testRepeatableMinLengthPerPurpose(): void
    {
        $core = $this->factory->forClass(RepeatableEntity::class);
        $create = $this->factory->forClass(RepeatableEntity::class, Purpose::Create);
        $update = $this->factory->forClass(RepeatableEntity::class, Purpose::Update);

        $this->assertSame(1, $core->name->jsonSerialize()['minLength']);
        $this->assertSame(3, $create->name->jsonSerialize()['minLength']);
        $this->assertSame(1, $update->name->jsonSerialize()['minLength']);
    }

    public function testRepeatableRequiredPerPurpose(): void
    {
        $core = $this->factory->forClass(RepeatableEntity::class);
        $create = $this->factory->forClass(RepeatableEntity::class, Purpose::Create);
        $update = $this->factory->forClass(RepeatableEntity::class, Purpose::Update);

        // Core has neither required
        $this->assertNull($core->requiredOnCreate);
        $this->assertNull($core->requiredOnUpdate);

        // Create has requiredOnCreate
        $this->assertNotNull($create->requiredOnCreate);
        $this->assertTrue($this->isRequired($create, 'requiredOnCreate'));
        $this->assertNull($create->requiredOnUpdate);

        // Update has requiredOnUpdate
        $this->assertNull($update->requiredOnCreate);
        $this->assertNotNull($update->requiredOnUpdate);
        $this->assertTrue($this->isRequired($update, 'requiredOnUpdate'));
    }

    public function testRepeatablePatternPerPurpose(): void
    {
        $create = $this->factory->forClass(RepeatableEntity::class, Purpose::Create);
        $update = $this->factory->forClass(RepeatableEntity::class, Purpose::Update);

        $this->assertSame('/^[a-z]+$/', $create->slug->jsonSerialize()['pattern']);
        $this->assertSame('/^[a-z0-9]+$/', $update->slug->jsonSerialize()['pattern']);
    }

    public function testRepeatableRangesPerPurpose(): void
    {
        $core = $this->factory->forClass(RepeatableEntity::class);
        $create = $this->factory->forClass(RepeatableEntity::class, Purpose::Create);
        $update = $this->factory->forClass(RepeatableEntity::class, Purpose::Update);

        // Score minimum
        $this->assertSame(0, $core->score->jsonSerialize()['minimum']);
        $this->assertSame(1, $create->score->jsonSerialize()['minimum']);
        $this->assertSame(-100, $update->score->jsonSerialize()['minimum']);

        // Limit maximum
        $this->assertSame(100, $core->limit->jsonSerialize()['maximum']);
        $this->assertSame(50, $create->limit->jsonSerialize()['maximum']);
        $this->assertSame(200, $update->limit->jsonSerialize()['maximum']);
    }

    // ========================================
    // Custom string purpose tests
    // ========================================

    public function testCustomPurposePasswordReset(): void
    {
        $v = $this->factory->forClass(CustomPurposeEntity::class, 'password-reset');

        $this->assertNotNull($v->newPassword);
        $this->assertTrue($this->isRequired($v, 'newPassword'));
        $this->assertSame(12, $v->newPassword->jsonSerialize()['minLength']);

        // Other custom purpose fields should not be present
        $this->assertNull($v->adminEmail);
        $this->assertNull($v->rawData);
        $this->assertNull($v->format);
    }

    public function testCustomPurposeAdminOverride(): void
    {
        $v = $this->factory->forClass(CustomPurposeEntity::class, 'admin-override');

        $this->assertNotNull($v->adminEmail);
        $this->assertTrue($this->isRequired($v, 'adminEmail'));
        $this->assertSame('email', $v->adminEmail->jsonSerialize()['format']);

        $this->assertNull($v->newPassword);
    }

    public function testCustomPurposeImport(): void
    {
        $v = $this->factory->forClass(CustomPurposeEntity::class, 'import');

        $this->assertNotNull($v->rawData);
        $this->assertSame('string', $v->rawData->jsonSerialize()['type']);
        $this->assertSame(1000, $v->rawData->jsonSerialize()['maxLength']);
    }

    public function testCustomPurposeExport(): void
    {
        $v = $this->factory->forClass(CustomPurposeEntity::class, 'export');

        $this->assertNotNull($v->format);
        $this->assertTrue($this->isRequired($v, 'format'));
        $this->assertSame(['csv', 'json', 'xml'], $v->format->jsonSerialize()['enum']);
    }

    public function testCoreExcludesAllCustomPurposes(): void
    {
        $v = $this->factory->forClass(CustomPurposeEntity::class);

        $this->assertNull($v->newPassword);
        $this->assertNull($v->adminEmail);
        $this->assertNull($v->rawData);
        $this->assertNull($v->format);
    }

    // ========================================
    // Numeric constraints per purpose
    // ========================================

    public function testNumericConstraintsCore(): void
    {
        $v = $this->factory->forClass(NumericEntity::class);

        $this->assertSame(0, $v->min->jsonSerialize()['minimum']);
        $this->assertSame(100, $v->max->jsonSerialize()['maximum']);
        $this->assertSame(0, $v->exclusiveMin->jsonSerialize()['exclusiveMinimum']);
        $this->assertSame(100, $v->exclusiveMax->jsonSerialize()['exclusiveMaximum']);
        $this->assertSame(1, $v->step->jsonSerialize()['multipleOf']);
    }

    public function testNumericConstraintsCreate(): void
    {
        $v = $this->factory->forClass(NumericEntity::class, Purpose::Create);

        $this->assertSame(10, $v->min->jsonSerialize()['minimum']);
        $this->assertSame(50, $v->max->jsonSerialize()['maximum']);
        $this->assertSame(5, $v->exclusiveMin->jsonSerialize()['exclusiveMinimum']);
        $this->assertSame(95, $v->exclusiveMax->jsonSerialize()['exclusiveMaximum']);
        $this->assertSame(5, $v->step->jsonSerialize()['multipleOf']);
    }

    // ========================================
    // String constraints per purpose
    // ========================================

    public function testStringConstraintsCore(): void
    {
        $v = $this->factory->forClass(StringEntity::class);

        $this->assertSame(1, $v->minLen->jsonSerialize()['minLength']);
        $this->assertSame(100, $v->maxLen->jsonSerialize()['maxLength']);
        $this->assertSame('/^[a-z]+$/', $v->pattern->jsonSerialize()['pattern']);
        $this->assertSame('email', $v->format->jsonSerialize()['format']);
    }

    public function testStringConstraintsCreate(): void
    {
        $v = $this->factory->forClass(StringEntity::class, Purpose::Create);

        $this->assertSame(5, $v->minLen->jsonSerialize()['minLength']);
        $this->assertSame(50, $v->maxLen->jsonSerialize()['maxLength']);
        $this->assertSame('/^[a-z]{3,}$/', $v->pattern->jsonSerialize()['pattern']);
        $this->assertSame('uri', $v->format->jsonSerialize()['format']);
    }

    // ========================================
    // Array constraints per purpose
    // ========================================

    public function testArrayConstraintsCore(): void
    {
        $v = $this->factory->forClass(ArrayEntity::class);

        $this->assertSame(0, $v->minItems->jsonSerialize()['minItems']);
        $this->assertSame(100, $v->maxItems->jsonSerialize()['maxItems']);
        $this->assertTrue($v->coreUnique->jsonSerialize()['uniqueItems']);
        $this->assertNull($v->createUnique); // purpose-specific
    }

    public function testArrayConstraintsCreate(): void
    {
        $v = $this->factory->forClass(ArrayEntity::class, Purpose::Create);

        $this->assertSame(1, $v->minItems->jsonSerialize()['minItems']);
        $this->assertSame(10, $v->maxItems->jsonSerialize()['maxItems']);
        $this->assertNull($v->coreUnique); // core only
        $this->assertTrue($v->createUnique->jsonSerialize()['uniqueItems']);
    }

    // ========================================
    // Object constraints per purpose
    // ========================================

    public function testObjectConstraintsCore(): void
    {
        $v = $this->factory->forClass(ObjectEntity::class);

        $this->assertSame(0, $v->minProps->jsonSerialize()['minProperties']);
        $this->assertSame(100, $v->maxProps->jsonSerialize()['maxProperties']);
    }

    public function testObjectConstraintsCreate(): void
    {
        $v = $this->factory->forClass(ObjectEntity::class, Purpose::Create);

        $this->assertSame(1, $v->minProps->jsonSerialize()['minProperties']);
        $this->assertSame(5, $v->maxProps->jsonSerialize()['maxProperties']);
    }

    // ========================================
    // Enum per purpose
    // ========================================

    public function testEnumStatusPerPurpose(): void
    {
        $core = $this->factory->forClass(EnumEntity::class);
        $create = $this->factory->forClass(EnumEntity::class, Purpose::Create);
        $update = $this->factory->forClass(EnumEntity::class, Purpose::Update);

        $this->assertSame(['pending', 'active', 'disabled'], $core->status->jsonSerialize()['enum']);
        $this->assertSame(['pending'], $create->status->jsonSerialize()['enum']);
        $this->assertSame(['active', 'disabled'], $update->status->jsonSerialize()['enum']);
    }

    public function testEnumTypePerPurpose(): void
    {
        $core = $this->factory->forClass(EnumEntity::class);
        $create = $this->factory->forClass(EnumEntity::class, Purpose::Create);
        $update = $this->factory->forClass(EnumEntity::class, Purpose::Update);

        $this->assertSame(['a', 'b', 'c'], $core->type->jsonSerialize()['enum']);
        $this->assertSame(['x', 'y'], $create->type->jsonSerialize()['enum']);
        $this->assertSame(['p', 'q', 'r', 's'], $update->type->jsonSerialize()['enum']);
    }

    // ========================================
    // Field attribute tests
    // ========================================

    public function testFieldAttributeCore(): void
    {
        $v = $this->factory->forClass(FieldInterface::class);

        $this->assertNotNull($v->coreField);
        $this->assertSame('string', $v->coreField->jsonSerialize()['type']);
        $this->assertSame(1, $v->coreField->jsonSerialize()['minLength']);
        $this->assertTrue($this->isRequired($v, 'coreField'));

        $this->assertNull($v->createField);
        $this->assertNull($v->updateField);
        $this->assertNull($v->customField);
    }

    public function testFieldAttributeCreate(): void
    {
        $v = $this->factory->forClass(FieldInterface::class, Purpose::Create);

        $this->assertNull($v->coreField);
        $this->assertNotNull($v->createField);
        $this->assertSame('string', $v->createField->jsonSerialize()['type']);
        $this->assertSame(5, $v->createField->jsonSerialize()['minLength']);
        $this->assertTrue($this->isRequired($v, 'createField'));
    }

    public function testFieldAttributeUpdate(): void
    {
        $v = $this->factory->forClass(FieldInterface::class, Purpose::Update);

        $this->assertNull($v->coreField);
        $this->assertNull($v->createField);
        $this->assertNotNull($v->updateField);
        $this->assertSame('integer', $v->updateField->jsonSerialize()['type']);
        $this->assertSame(1, $v->updateField->jsonSerialize()['minimum']);
    }

    public function testFieldAttributeCustomPurpose(): void
    {
        $v = $this->factory->forClass(FieldInterface::class, 'custom-purpose');

        $this->assertNull($v->coreField);
        $this->assertNotNull($v->customField);
        $this->assertSame('email', $v->customField->jsonSerialize()['format']);
    }

    // ========================================
    // Mixed entity tests (properties + Field attributes)
    // ========================================

    public function testMixedEntityCore(): void
    {
        $v = $this->factory->forClass(MixedEntity::class);

        // Real property
        $this->assertNotNull($v->realProperty);
        $this->assertTrue($this->isRequired($v, 'realProperty'));

        // Virtual field
        $this->assertNotNull($v->virtualCore);
        $this->assertTrue($this->isRequired($v, 'virtualCore'));

        // Purpose-specific should not be present
        $this->assertNull($v->createOnly);
        $this->assertNull($v->virtualCreate);
    }

    public function testMixedEntityCreate(): void
    {
        $v = $this->factory->forClass(MixedEntity::class, Purpose::Create);

        // Core should not be present
        $this->assertNull($v->realProperty);
        $this->assertNull($v->virtualCore);

        // Create-specific should be present
        $this->assertNotNull($v->createOnly);
        $this->assertTrue($this->isRequired($v, 'createOnly'));
        $this->assertNotNull($v->virtualCreate);
        $this->assertSame(10, $v->virtualCreate->jsonSerialize()['minLength']);
    }

    // ========================================
    // Empty entity tests
    // ========================================

    public function testEmptyEntityReturnsEmptyValidator(): void
    {
        $core = $this->factory->forClass(EmptyEntity::class);
        $create = $this->factory->forClass(EmptyEntity::class, Purpose::Create);

        // Should return object validators with no properties
        $this->assertSame('object', $core->jsonSerialize()['type']);
        $this->assertSame('object', $create->jsonSerialize()['type']);

        $this->assertNull($core->name);
        $this->assertNull($core->age);
    }

    // ========================================
    // Purpose-only entity tests
    // ========================================

    public function testPurposeOnlyEntityCore(): void
    {
        $v = $this->factory->forClass(PurposeOnlyEntity::class);

        // Core should have no property validators
        $this->assertNull($v->createField);
        $this->assertNull($v->updateField);
    }

    public function testPurposeOnlyEntityCreate(): void
    {
        $v = $this->factory->forClass(PurposeOnlyEntity::class, Purpose::Create);

        $this->assertNotNull($v->createField);
        $this->assertTrue($this->isRequired($v, 'createField'));
        $this->assertSame(5, $v->createField->jsonSerialize()['minLength']);

        $this->assertNull($v->updateField);
    }

    public function testPurposeOnlyEntityUpdate(): void
    {
        $v = $this->factory->forClass(PurposeOnlyEntity::class, Purpose::Update);

        $this->assertNull($v->createField);

        $this->assertNotNull($v->updateField);
        $this->assertTrue($this->isRequired($v, 'updateField'));
        $this->assertSame(1, $v->updateField->jsonSerialize()['minimum']);
    }

    // ========================================
    // Actual validation tests
    // ========================================

    public function testCoreValidationEmailRequired(): void
    {
        $v = $this->factory->forClass(FullEntity::class);

        // Missing email should fail
        $errors = $v->isInvalid(['email' => null]);
        $this->assertArrayHasKey('email', $errors);

        // Valid email should pass email validation
        $errors = $v->isInvalid(['email' => 'test@example.com']);
        $this->assertNull($errors['email'] ?? null);
    }

    public function testCoreValidationFails(): void
    {
        $v = $this->factory->forClass(FullEntity::class);

        // Invalid email
        $invalid = [
            'email' => 'not-an-email',
            'username' => 'valid',
            'age' => 25,
            'balance' => 100.50,
            'tags' => [],
            'metadata' => [],
            'defaultStatus' => 'active',
            'priority' => 'high',
        ];

        $errors = $v->isInvalid($invalid);
        $this->assertArrayHasKey('email', $errors);
    }

    public function testCreateValidationPasswordRequired(): void
    {
        $v = $this->factory->forClass(FullEntity::class, Purpose::Create);

        // Missing password
        $errors = $v->isInvalid(['password' => null, 'passwordConfirm' => null]);
        $this->assertArrayHasKey('password', $errors);
        $this->assertArrayHasKey('passwordConfirm', $errors);
    }

    public function testCreateValidationPasswordTooShort(): void
    {
        $v = $this->factory->forClass(FullEntity::class, Purpose::Create);

        $errors = $v->isInvalid(['password' => 'short', 'passwordConfirm' => 'test']);
        $this->assertArrayHasKey('password', $errors);
    }

    public function testCreateValidationPasswordValid(): void
    {
        $v = $this->factory->forClass(FullEntity::class, Purpose::Create);

        $valid = ['password' => 'ValidPass123', 'passwordConfirm' => 'test'];
        $this->assertNull($v->isInvalid($valid));
    }

    public function testUpdateValidationIdRequired(): void
    {
        $v = $this->factory->forClass(FullEntity::class, Purpose::Update);

        $errors = $v->isInvalid(['id' => null]);
        $this->assertArrayHasKey('id', $errors);
    }

    public function testUpdateValidationIdMustBePositive(): void
    {
        $v = $this->factory->forClass(FullEntity::class, Purpose::Update);

        $errors = $v->isInvalid(['id' => 0]);
        $this->assertArrayHasKey('id', $errors);
    }

    public function testUpdateValidationValid(): void
    {
        $v = $this->factory->forClass(FullEntity::class, Purpose::Update);

        $valid = ['id' => 123, 'updatedAt' => '2024-01-15T10:30:00Z'];
        $this->assertNull($v->isInvalid($valid));
    }

    public function testRepeatableValidationDifferentRules(): void
    {
        $core = $this->factory->forClass(RepeatableEntity::class);
        $create = $this->factory->forClass(RepeatableEntity::class, Purpose::Create);

        // "ab" passes core (min 1) but fails create (min 3)
        $this->assertNull($core->isInvalid(['name' => 'ab', 'score' => 0, 'limit' => 100]));

        $errors = $create->isInvalid(['name' => 'ab', 'score' => 1, 'limit' => 50]);
        $this->assertArrayHasKey('name', $errors);
    }

    public function testCustomPurposeValidation(): void
    {
        $v = $this->factory->forClass(CustomPurposeEntity::class, 'password-reset');

        // Too short
        $errors = $v->isInvalid(['newPassword' => 'short']);
        $this->assertArrayHasKey('newPassword', $errors);

        // No special char
        $errors = $v->isInvalid(['newPassword' => 'LongPassword123']);
        $this->assertArrayHasKey('newPassword', $errors);

        // Valid
        $this->assertNull($v->isInvalid(['newPassword' => 'LongPassword123!']));
    }

    public function testEnumStatusValidation(): void
    {
        $core = $this->factory->forClass(EnumEntity::class);
        $create = $this->factory->forClass(EnumEntity::class, Purpose::Create);
        $update = $this->factory->forClass(EnumEntity::class, Purpose::Update);

        // Core allows pending, active, disabled
        $this->assertNull($core->isInvalid(['status' => 'active', 'type' => 'a']));
        $errors = $core->isInvalid(['status' => 'invalid', 'type' => 'a']);
        $this->assertArrayHasKey('status', $errors);

        // Create only allows 'pending'
        $this->assertNull($create->isInvalid(['status' => 'pending', 'type' => 'x']));
        $errors = $create->isInvalid(['status' => 'active', 'type' => 'x']);
        $this->assertArrayHasKey('status', $errors);

        // Update allows active, disabled (not pending)
        $this->assertNull($update->isInvalid(['status' => 'active', 'type' => 'p']));
        $errors = $update->isInvalid(['status' => 'pending', 'type' => 'p']);
        $this->assertArrayHasKey('status', $errors);
    }

    public function testEnumTypeValidation(): void
    {
        $core = $this->factory->forClass(EnumEntity::class);
        $create = $this->factory->forClass(EnumEntity::class, Purpose::Create);

        // Core allows a,b,c
        $this->assertNull($core->isInvalid(['status' => 'active', 'type' => 'b']));
        $errors = $core->isInvalid(['status' => 'active', 'type' => 'x']);
        $this->assertArrayHasKey('type', $errors);

        // Create allows x,y only
        $this->assertNull($create->isInvalid(['status' => 'pending', 'type' => 'y']));
        $errors = $create->isInvalid(['status' => 'pending', 'type' => 'a']);
        $this->assertArrayHasKey('type', $errors);
    }

    // ========================================
    // ValidatorStore integration tests
    // ========================================

    public function testStoreAutoBuildsAllPurposes(): void
    {
        $store = new ValidatorStore();

        $core = $store->get(FullEntity::class);
        $create = $store->get(FullEntity::class, Purpose::Create);
        $update = $store->get(FullEntity::class, Purpose::Update);

        $this->assertInstanceOf(Validator::class, $core);
        $this->assertInstanceOf(Validator::class, $create);
        $this->assertInstanceOf(Validator::class, $update);

        // Verify correct validators were built
        $this->assertNotNull($core->email);
        $this->assertNotNull($create->password);
        $this->assertNotNull($update->id);
    }

    public function testStoreCachesPurposeValidators(): void
    {
        $store = new ValidatorStore();

        // First access triggers build
        $v1 = $store->get(FullEntity::class, Purpose::Create);

        // Second access returns cached
        $v2 = $store->get(FullEntity::class, Purpose::Create);

        $this->assertSame($v1, $v2);
    }

    public function testStoreCachesPerPurpose(): void
    {
        $store = new ValidatorStore();

        $core = $store->get(FullEntity::class);
        $create = $store->get(FullEntity::class, Purpose::Create);
        $update = $store->get(FullEntity::class, Purpose::Update);

        // Each purpose should have its own cached instance
        $this->assertNotSame($core, $create);
        $this->assertNotSame($core, $update);
        $this->assertNotSame($create, $update);
    }

    public function testStoreCustomPurposeAutoBuild(): void
    {
        $store = new ValidatorStore();

        $v = $store->get(CustomPurposeEntity::class, 'password-reset');

        $this->assertInstanceOf(Validator::class, $v);
        $this->assertNotNull($v->newPassword);
    }

    public function testStoreManualOverridesAutoBuild(): void
    {
        $store = new ValidatorStore();

        // Manually set a validator
        $manual = (new Validator())->forProperty('custom', (new Validator())->required());
        $store->set(FullEntity::class, $manual, Purpose::Create);

        // Should return manual validator, not auto-built
        $v = $store->get(FullEntity::class, Purpose::Create);

        $this->assertNotNull($v->custom);
        $this->assertNull($v->password); // Auto-built would have password
    }

    // ========================================
    // validator() function integration tests
    // ========================================

    public function testValidatorFunctionCore(): void
    {
        $v = \mini\validator(FullEntity::class);

        $this->assertNotNull($v->email);
        $this->assertNull($v->password);
    }

    public function testValidatorFunctionCreate(): void
    {
        $v = \mini\validator(FullEntity::class, Purpose::Create);

        $this->assertNull($v->email);
        $this->assertNotNull($v->password);
    }

    public function testValidatorFunctionUpdate(): void
    {
        $v = \mini\validator(FullEntity::class, Purpose::Update);

        $this->assertNull($v->email);
        $this->assertNull($v->password);
        $this->assertNotNull($v->id);
    }

    public function testValidatorFunctionCustomPurpose(): void
    {
        $v = \mini\validator(CustomPurposeEntity::class, 'export');

        $this->assertNotNull($v->format);
        $this->assertSame(['csv', 'json', 'xml'], $v->format->jsonSerialize()['enum']);
    }

    public function testValidatorFunctionReturnsClones(): void
    {
        $v1 = \mini\validator(FullEntity::class, Purpose::Create);
        $v2 = \mini\validator(FullEntity::class, Purpose::Create);

        $this->assertNotSame($v1, $v2);
    }

    // ========================================
    // JSON Schema output tests
    // ========================================

    public function testJsonSchemaCoreType(): void
    {
        $v = $this->factory->forClass(FullEntity::class);
        $schema = $v->jsonSerialize();

        $this->assertSame('object', $schema['type']);
    }

    public function testJsonSchemaCreateType(): void
    {
        $v = $this->factory->forClass(FullEntity::class, Purpose::Create);
        $schema = $v->jsonSerialize();

        $this->assertSame('object', $schema['type']);
    }

    public function testJsonSchemaHasPropertiesKey(): void
    {
        $v = $this->factory->forClass(FullEntity::class);
        $schema = $v->jsonSerialize();

        // Should have properties
        $this->assertArrayHasKey('properties', $schema);
    }

    public function testJsonSchemaEmailConstraints(): void
    {
        $v = $this->factory->forClass(FullEntity::class);

        // Get email property schema directly
        $emailSchema = $v->email->jsonSerialize();

        $this->assertSame('string', $emailSchema['type']);
        $this->assertSame('email', $emailSchema['format']);
        $this->assertSame(1, $emailSchema['minLength']);
        $this->assertSame(255, $emailSchema['maxLength']);
    }

    // ========================================
    // Adversarial tests
    // ========================================

    public function testPurposeEnumFromString(): void
    {
        // Using Purpose::from() to convert string to enum
        $purpose = Purpose::from('create');
        $v = $this->factory->forClass(FullEntity::class, $purpose);

        $this->assertNotNull($v->password);
    }

    public function testPurposeAsStringMatchesEnum(): void
    {
        // String 'create' should match Purpose::Create
        $vEnum = $this->factory->forClass(RepeatableEntity::class, Purpose::Create);
        $vString = $this->factory->forClass(RepeatableEntity::class, 'create');

        // Both should have same validators
        $this->assertSame(
            $vEnum->name->jsonSerialize()['minLength'],
            $vString->name->jsonSerialize()['minLength']
        );
    }

    public function testNonExistentPurposeReturnsEmptyValidator(): void
    {
        // Purpose that no attribute uses
        $v = $this->factory->forClass(FullEntity::class, 'non-existent-purpose');

        // Should return validator with no properties
        $this->assertSame('object', $v->jsonSerialize()['type']);
        $this->assertNull($v->email);
        $this->assertNull($v->password);
    }

    public function testMultiplePatternsSamePurpose(): void
    {
        // FullEntity has multiple patterns on password for Purpose::Create
        $v = $this->factory->forClass(FullEntity::class, Purpose::Create);

        // Should have validator (patterns are applied)
        $this->assertNotNull($v->password);

        // Test validation works (needs uppercase and number)
        $this->assertNotNull($v->isInvalid(['password' => 'alllowercase', 'passwordConfirm' => 'x']));
        $this->assertNotNull($v->isInvalid(['password' => 'ALLUPPERCASE', 'passwordConfirm' => 'x']));
        $this->assertNull($v->isInvalid(['password' => 'ValidPass1', 'passwordConfirm' => 'x']));
    }

    public function testConflictingConstraintsBothApply(): void
    {
        // Test that both core and purpose validators can have different ranges
        $core = $this->factory->forClass(ConflictingConstraintsEntity::class);
        $create = $this->factory->forClass(ConflictingConstraintsEntity::class, Purpose::Create);

        // Core allows 1-100
        $this->assertNull($core->isInvalid(['value' => 1]));
        $this->assertNull($core->isInvalid(['value' => 100]));
        $this->assertNotNull($core->isInvalid(['value' => 0]));
        $this->assertNotNull($core->isInvalid(['value' => 101]));

        // Create allows 50-75 only
        $this->assertNull($create->isInvalid(['value' => 50]));
        $this->assertNull($create->isInvalid(['value' => 75]));
        $this->assertNotNull($create->isInvalid(['value' => 49]));
        $this->assertNotNull($create->isInvalid(['value' => 76]));
    }

    public function testManyPurposesOnSameProperty(): void
    {
        $core = $this->factory->forClass(ManyPurposesEntity::class);
        $create = $this->factory->forClass(ManyPurposesEntity::class, Purpose::Create);
        $update = $this->factory->forClass(ManyPurposesEntity::class, Purpose::Update);
        $strict = $this->factory->forClass(ManyPurposesEntity::class, 'strict');
        $relaxed = $this->factory->forClass(ManyPurposesEntity::class, 'relaxed');

        $this->assertSame(1, $core->field->jsonSerialize()['minLength']);
        $this->assertSame(5, $create->field->jsonSerialize()['minLength']);
        $this->assertSame(3, $update->field->jsonSerialize()['minLength']);
        $this->assertSame(10, $strict->field->jsonSerialize()['minLength']);
        $this->assertSame(2, $relaxed->field->jsonSerialize()['minLength']);
    }

    public function testSpecialCharactersInPurposeStrings(): void
    {
        // Test various special character purposes work correctly
        $hyphen = $this->factory->forClass(SpecialPurposeEntity::class, 'with-hyphen');
        $underscore = $this->factory->forClass(SpecialPurposeEntity::class, 'with_underscore');
        $camel = $this->factory->forClass(SpecialPurposeEntity::class, 'CamelCase');
        $dot = $this->factory->forClass(SpecialPurposeEntity::class, 'with.dot');
        $colon = $this->factory->forClass(SpecialPurposeEntity::class, 'with:colon');

        $this->assertNotNull($hyphen->hyphen);
        $this->assertNotNull($underscore->underscore);
        $this->assertNotNull($camel->camel);
        $this->assertNotNull($dot->dot);
        $this->assertNotNull($colon->colon);

        // Core should have none
        $core = $this->factory->forClass(SpecialPurposeEntity::class);
        $this->assertNull($core->hyphen);
        $this->assertNull($core->underscore);
    }

    public function testEmptyStringPurpose(): void
    {
        // Empty string is different from null
        $v = $this->factory->forClass(FullEntity::class, '');

        // Should return validator with no properties (no attributes have empty string purpose)
        $this->assertNull($v->email);
        $this->assertNull($v->password);
    }

    public function testWhitespaceHandling(): void
    {
        $core = $this->factory->forClass(WhitespaceEntity::class);
        $create = $this->factory->forClass(WhitespaceEntity::class, Purpose::Create);

        // Core has minLength 0, Create has minLength 1
        $coreSchema = $core->allowEmpty->jsonSerialize();
        $createSchema = $create->allowEmpty->jsonSerialize();

        $this->assertSame(0, $coreSchema['minLength']);
        $this->assertSame(1, $createSchema['minLength']);
    }

    public function testNullPurposeVsNoPurpose(): void
    {
        // Explicitly passing null should behave same as not passing purpose
        $v1 = $this->factory->forClass(FullEntity::class);
        $v2 = $this->factory->forClass(FullEntity::class, null);

        // Both should have core validators
        $this->assertNotNull($v1->email);
        $this->assertNotNull($v2->email);
        $this->assertNull($v1->password);
        $this->assertNull($v2->password);
    }

    public function testPurposeCaseSensitivity(): void
    {
        // Purpose strings should be case-sensitive
        $lower = $this->factory->forClass(SpecialPurposeEntity::class, 'camelcase');
        $proper = $this->factory->forClass(SpecialPurposeEntity::class, 'CamelCase');

        // 'camelcase' (lowercase) should not match 'CamelCase'
        $this->assertNull($lower->camel);
        $this->assertNotNull($proper->camel);
    }

    public function testValidatorStoreWithSameKeyDifferentPurpose(): void
    {
        $store = new ValidatorStore();

        // Set same class with different purposes
        $store->set('MyValidator', (new Validator())->forProperty('a', new Validator()));
        $store->set('MyValidator', (new Validator())->forProperty('b', new Validator()), Purpose::Create);
        $store->set('MyValidator', (new Validator())->forProperty('c', new Validator()), 'custom');

        // Each should be independent
        $this->assertNotNull($store->get('MyValidator')->a);
        $this->assertNull($store->get('MyValidator')->b);

        $this->assertNull($store->get('MyValidator', Purpose::Create)->a);
        $this->assertNotNull($store->get('MyValidator', Purpose::Create)->b);

        $this->assertNull($store->get('MyValidator', 'custom')->a);
        $this->assertNotNull($store->get('MyValidator', 'custom')->c);
    }

    public function testValidationWithMissingFields(): void
    {
        $v = $this->factory->forClass(FullEntity::class);

        // Completely empty data - should fail on required email
        $errors = $v->isInvalid([]);
        $this->assertArrayHasKey('email', $errors);
    }

    public function testCoreAndCreateHaveDifferentValidators(): void
    {
        // Core and Create should have completely different property validators
        $core = $this->factory->forClass(FullEntity::class);
        $create = $this->factory->forClass(FullEntity::class, Purpose::Create);

        // Core has email, Create does not
        $this->assertNotNull($core->email);
        $this->assertNull($create->email);

        // Create has password, Core does not
        $this->assertNotNull($create->password);
        $this->assertNull($core->password);
    }

    public function testAgeConstraintsInCore(): void
    {
        $v = $this->factory->forClass(FullEntity::class);

        // Age should have correct constraints
        $ageSchema = $v->age->jsonSerialize();
        $this->assertSame('integer', $ageSchema['type']);
        $this->assertSame(0, $ageSchema['minimum']);
        $this->assertSame(150, $ageSchema['maximum']);
    }

    public function testBalanceExclusiveConstraints(): void
    {
        $v = $this->factory->forClass(FullEntity::class);

        // Balance should have exclusive constraints
        $balanceSchema = $v->balance->jsonSerialize();
        $this->assertSame(0, $balanceSchema['exclusiveMinimum']);
        $this->assertSame(1000000, $balanceSchema['exclusiveMaximum']);
    }

    public function testPurposeInheritanceNotSupported(): void
    {
        // Verify that Purpose::Create doesn't include core attributes
        // (i.e., purposes are independent, not inherited)
        $create = $this->factory->forClass(FullEntity::class, Purpose::Create);

        // Create should NOT have core validators
        $this->assertNull($create->email);
        $this->assertNull($create->username);

        // Should only have Create-specific
        $this->assertNotNull($create->password);
    }

    public function testEmptyValidatorBehavior(): void
    {
        // Empty validator (no constraints) should pass anything
        $v = $this->factory->forClass(EmptyEntity::class);

        $this->assertNull($v->isInvalid([]));
        $this->assertNull($v->isInvalid(['anything' => 'goes']));
        $this->assertNull($v->isInvalid(['nested' => ['data' => true]]));
    }

    public function testUniqueItemsAttributePerPurpose(): void
    {
        $core = $this->factory->forClass(ArrayEntity::class);
        $create = $this->factory->forClass(ArrayEntity::class, Purpose::Create);

        // Core has coreUnique with uniqueItems
        $this->assertNotNull($core->coreUnique);
        $this->assertTrue($core->coreUnique->jsonSerialize()['uniqueItems']);
        $this->assertNull($core->createUnique);

        // Create has createUnique with uniqueItems
        $this->assertNotNull($create->createUnique);
        $this->assertTrue($create->createUnique->jsonSerialize()['uniqueItems']);
        $this->assertNull($create->coreUnique);
    }
};

exit($test->run());
