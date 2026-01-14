<?php
/**
 * Test Purpose enum and purpose-specific validation
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Test;
use mini\Validator\Validator;
use mini\Validator\ValidatorStore;
use mini\Validator\AttributeValidatorFactory;
use mini\Validator\Purpose;
use mini\Validator\Attributes as V;

// Simple entity for testing (code-defined validators)
class TestUser {
    public ?int $id = null;
    public string $email = '';
    public string $name = '';
    public ?string $password = null;
}

// Entity with purpose-scoped attributes
class TestUserWithAttributes {
    // Core validation (always applies)
    #[V\Required]
    #[V\Format('email')]
    public string $email;

    #[V\MinLength(2)]
    public string $name;

    // Create-specific: password required with minimum length
    #[V\Required(purpose: Purpose::Create)]
    #[V\MinLength(8, purpose: Purpose::Create)]
    public ?string $password = null;

    // Update-specific: id required
    #[V\Required(purpose: Purpose::Update)]
    public ?int $id = null;
}

// Entity with repeatable attributes (same attribute, different purposes)
class TestEntityWithRepeatable {
    // Core: min 1 character, Create: min 8 characters
    #[V\MinLength(1)]
    #[V\MinLength(8, purpose: Purpose::Create)]
    public string $username;
}

// Entity with custom string purpose
class TestEntityWithCustomPurpose {
    #[V\Required(purpose: 'password-reset')]
    #[V\MinLength(12, purpose: 'password-reset')]
    public ?string $newPassword = null;
}

// Interface with Field attributes and purpose
#[V\Field(name: 'token', type: 'string', required: true)]
#[V\Field(name: 'refreshToken', type: 'string', required: true, purpose: Purpose::Create)]
interface TestTokenInterface {}

$test = new class extends Test {

    protected function setUp(): void
    {
        \mini\bootstrap();
    }

    // ========================================
    // Purpose enum
    // ========================================

    public function testPurposeEnumValues(): void
    {
        $this->assertSame('create', Purpose::Create->value);
        $this->assertSame('update', Purpose::Update->value);
    }

    public function testPurposeEnumFromString(): void
    {
        $this->assertSame(Purpose::Create, Purpose::from('create'));
        $this->assertSame(Purpose::Update, Purpose::from('update'));
    }

    // ========================================
    // ValidatorStore with purpose
    // ========================================

    public function testStoreSetAndGetWithPurpose(): void
    {
        $store = new ValidatorStore();

        $coreValidator = (new Validator())
            ->forProperty('email', (new Validator())->required());

        $createValidator = (new Validator())
            ->forProperty('id', (new Validator())
                ->custom(fn($v) => empty($v), "ID must be empty"));

        $updateValidator = (new Validator())
            ->forProperty('id', (new Validator())->required());

        // Set validators
        $store->set(TestUser::class, $coreValidator);
        $store->set(TestUser::class, $createValidator, Purpose::Create);
        $store->set(TestUser::class, $updateValidator, Purpose::Update);

        // Get validators
        $this->assertNotNull($store->get(TestUser::class));
        $this->assertNotNull($store->get(TestUser::class, Purpose::Create));
        $this->assertNotNull($store->get(TestUser::class, Purpose::Update));

        // They should be different instances
        $this->assertNotSame(
            $store->get(TestUser::class),
            $store->get(TestUser::class, Purpose::Create)
        );
    }

    public function testStoreHasWithPurpose(): void
    {
        $store = new ValidatorStore();

        $store->set(TestUser::class, new Validator());
        $store->set(TestUser::class, new Validator(), Purpose::Create);

        $this->assertTrue($store->has(TestUser::class));
        $this->assertTrue($store->has(TestUser::class, Purpose::Create));
        $this->assertFalse($store->has(TestUser::class, Purpose::Update));
    }

    public function testStoreCustomPurposeString(): void
    {
        $store = new ValidatorStore();

        $passwordResetValidator = (new Validator())
            ->forProperty('password', (new Validator())->required()->minLength(12));

        $store->set(TestUser::class, $passwordResetValidator, 'password-reset');

        $this->assertTrue($store->has(TestUser::class, 'password-reset'));
        $this->assertNotNull($store->get(TestUser::class, 'password-reset'));
    }

    public function testStoreAutoBuildsFromAttributesForPurpose(): void
    {
        $store = new ValidatorStore();

        // Register core validator only (manually)
        $store->set(TestUser::class, new Validator());

        // Purpose-specific should auto-build from attributes (returns validator, possibly empty)
        // TestUser has no purpose-specific attributes, so auto-built validators have no properties
        $createValidator = $store->get(TestUser::class, Purpose::Create);
        $this->assertInstanceOf(Validator::class, $createValidator);
    }

    public function testStoreReturnsNullForNonClassKey(): void
    {
        $store = new ValidatorStore();

        // Non-class keys cannot be auto-built
        $this->assertNull($store->get('some-custom-identifier', Purpose::Create));
        $this->assertNull($store->get('my-validator-name', 'custom-purpose'));
    }

    // ========================================
    // validator() function with purpose
    // ========================================

    public function testValidatorFunctionWithPurpose(): void
    {
        $store = \mini\Mini::$mini->get(ValidatorStore::class);

        $coreValidator = (new Validator())
            ->forProperty('email', (new Validator())->required());

        $createValidator = (new Validator())
            ->forProperty('id', (new Validator())
                ->custom(fn($v) => empty($v)));

        $store->set(TestUser::class, $coreValidator);
        $store->set(TestUser::class, $createValidator, Purpose::Create);

        // Retrieve via function
        $core = \mini\validator(TestUser::class);
        $create = \mini\validator(TestUser::class, Purpose::Create);

        $this->assertNotNull($core);
        $this->assertNotNull($create);
    }

    public function testValidatorFunctionReturnsEmptyForMissingStandardPurpose(): void
    {
        $store = \mini\Mini::$mini->get(ValidatorStore::class);

        // Register only core validator
        $store->set(TestUser::class, new Validator());

        // Standard purposes (Purpose enum) return empty validator - opt-in behavior
        $v = \mini\validator(TestUser::class, Purpose::Update);
        $this->assertNotNull($v);

        // Empty validator should always succeed
        $user = new TestUser();
        $this->assertNull($v->isInvalid($user));
    }

    public function testValidatorFunctionAutoBuildsForCustomPurpose(): void
    {
        // For classes, auto-build from attributes (returns validator even for custom purposes)
        // TestUser has no 'custom-not-registered' attributes, so returns empty object validator
        $validator = \mini\validator(TestUser::class, 'custom-not-registered');
        $this->assertInstanceOf(Validator::class, $validator);
    }

    public function testValidatorFunctionThrowsForNonClassKey(): void
    {
        // Non-class keys cannot be auto-built
        $threw = false;
        try {
            \mini\validator('non-existent-validator', 'custom-purpose');
        } catch (\InvalidArgumentException $e) {
            $threw = true;
            $this->assertContains('non-existent-validator', $e->getMessage());
        }
        $this->assertTrue($threw);
    }

    public function testValidatorFunctionReturnsClone(): void
    {
        $store = \mini\Mini::$mini->get(ValidatorStore::class);

        $store->set(TestUser::class, (new Validator())
            ->forProperty('email', (new Validator())->required()));

        $v1 = \mini\validator(TestUser::class);
        $v2 = \mini\validator(TestUser::class);

        // Should be different instances (clones)
        $this->assertNotSame($v1, $v2);
    }

    // ========================================
    // Validation flow
    // ========================================

    public function testCreateValidation(): void
    {
        $store = \mini\Mini::$mini->get(ValidatorStore::class);

        // Core validator: email required
        $store->set(TestUser::class, (new Validator())
            ->forProperty('email', (new Validator())->required()->format('email')));

        // Create validator: id must be empty
        $store->set(TestUser::class, (new Validator())
            ->forProperty('id', (new Validator())
                ->custom(fn($v) => empty($v), "ID must be empty on create")),
            Purpose::Create);

        // Valid create: no id, valid email
        $user = new TestUser();
        $user->email = 'test@example.com';

        $createError = \mini\validator(TestUser::class, Purpose::Create)->isInvalid($user);
        $coreError = \mini\validator(TestUser::class)->isInvalid($user);

        $this->assertNull($createError, "Create validation should pass");
        $this->assertNull($coreError, "Core validation should pass");

        // Invalid create: has id
        $user->id = 123;

        $createError = \mini\validator(TestUser::class, Purpose::Create)->isInvalid($user);
        $this->assertNotNull($createError, "Create validation should fail when id is set");
    }

    public function testUpdateValidation(): void
    {
        $store = \mini\Mini::$mini->get(ValidatorStore::class);

        // Core validator: email required
        $store->set(TestUser::class, (new Validator())
            ->forProperty('email', (new Validator())->required()->format('email')));

        // Update validator: id required
        $store->set(TestUser::class, (new Validator())
            ->forProperty('id', (new Validator())->required("ID required on update")),
            Purpose::Update);

        // Valid update: has id and valid email
        $user = new TestUser();
        $user->id = 123;
        $user->email = 'test@example.com';

        $updateError = \mini\validator(TestUser::class, Purpose::Update)->isInvalid($user);
        $coreError = \mini\validator(TestUser::class)->isInvalid($user);

        $this->assertNull($updateError, "Update validation should pass");
        $this->assertNull($coreError, "Core validation should pass");

        // Invalid update: no id
        $user->id = null;

        $updateError = \mini\validator(TestUser::class, Purpose::Update)->isInvalid($user);
        $this->assertNotNull($updateError, "Update validation should fail when id is missing");
    }

    public function testLayeredValidation(): void
    {
        $store = \mini\Mini::$mini->get(ValidatorStore::class);

        // Core: email format
        $store->set(TestUser::class, (new Validator())
            ->forProperty('email', (new Validator())->required()->format('email')));

        // Create: id empty, password required
        $store->set(TestUser::class, (new Validator())
            ->forProperty('id', (new Validator())
                ->custom(fn($v) => empty($v)))
            ->forProperty('password', (new Validator())->required()->minLength(8)),
            Purpose::Create);

        // Test: passes purpose but fails core
        $user = new TestUser();
        $user->email = 'not-an-email';  // Invalid email
        $user->password = 'validpassword123';

        $createError = \mini\validator(TestUser::class, Purpose::Create)->isInvalid($user);
        $coreError = \mini\validator(TestUser::class)->isInvalid($user);

        $this->assertNull($createError, "Create validation should pass (no id, has password)");
        $this->assertNotNull($coreError, "Core validation should fail (invalid email)");
    }

    // ========================================
    // Purpose in attributes (Jakarta/Symfony groups pattern)
    // ========================================

    public function testCoreValidatorExcludesAttributesWithPurpose(): void
    {
        $factory = new AttributeValidatorFactory();
        $validator = $factory->forClass(TestUserWithAttributes::class);

        // Core validator should have email and name validators
        $this->assertNotNull($validator->email);
        $this->assertNotNull($validator->name);

        // Core validator should NOT have password or id validators (purpose-specific)
        $this->assertNull($validator->password);
        $this->assertNull($validator->id);
    }

    public function testPurposeValidatorIncludesOnlyMatchingAttributes(): void
    {
        $factory = new AttributeValidatorFactory();

        // Create validator
        $createValidator = $factory->forClass(TestUserWithAttributes::class, Purpose::Create);

        // Should have password validator (purpose: Create)
        $this->assertNotNull($createValidator->password);

        // Should NOT have id validator (purpose: Update)
        $this->assertNull($createValidator->id);

        // Should NOT have email/name (core only)
        $this->assertNull($createValidator->email);
        $this->assertNull($createValidator->name);
    }

    public function testRepeatableAttributesWithDifferentPurposes(): void
    {
        $factory = new AttributeValidatorFactory();

        // Core validator: minLength 1
        $coreValidator = $factory->forClass(TestEntityWithRepeatable::class);
        $coreSchema = $coreValidator->username->jsonSerialize();
        $this->assertSame(1, $coreSchema['minLength']);

        // Create validator: minLength 8
        $createValidator = $factory->forClass(TestEntityWithRepeatable::class, Purpose::Create);
        $createSchema = $createValidator->username->jsonSerialize();
        $this->assertSame(8, $createSchema['minLength']);
    }

    public function testCustomStringPurposeInAttributes(): void
    {
        $factory = new AttributeValidatorFactory();

        // Core validator: no password validation
        $coreValidator = $factory->forClass(TestEntityWithCustomPurpose::class);
        $this->assertNull($coreValidator->newPassword);

        // Custom purpose validator
        $customValidator = $factory->forClass(TestEntityWithCustomPurpose::class, 'password-reset');
        $this->assertNotNull($customValidator->newPassword);

        $schema = $customValidator->newPassword->jsonSerialize();
        $this->assertSame(12, $schema['minLength']);
    }

    public function testFieldAttributesWithPurpose(): void
    {
        $factory = new AttributeValidatorFactory();

        // Core validator: only token
        $coreValidator = $factory->forClass(TestTokenInterface::class);
        $this->assertNotNull($coreValidator->token);
        $this->assertNull($coreValidator->refreshToken);

        // Create validator: only refreshToken
        $createValidator = $factory->forClass(TestTokenInterface::class, Purpose::Create);
        $this->assertNull($createValidator->token);
        $this->assertNotNull($createValidator->refreshToken);
    }

    public function testAttributeValidationActuallyWorks(): void
    {
        $factory = new AttributeValidatorFactory();

        // Get validators
        $coreValidator = $factory->forClass(TestUserWithAttributes::class);
        $createValidator = $factory->forClass(TestUserWithAttributes::class, Purpose::Create);
        $updateValidator = $factory->forClass(TestUserWithAttributes::class, Purpose::Update);

        // Valid data for core validation
        $validCore = ['email' => 'test@example.com', 'name' => 'John'];
        $this->assertNull($coreValidator->isInvalid($validCore));

        // Invalid email for core validation
        $invalidEmail = ['email' => 'not-an-email', 'name' => 'John'];
        $coreErrors = $coreValidator->isInvalid($invalidEmail);
        $this->assertArrayHasKey('email', $coreErrors);

        // Create validation: password required
        $noPassword = ['password' => null];
        $createErrors = $createValidator->isInvalid($noPassword);
        $this->assertArrayHasKey('password', $createErrors);

        // Create validation: password too short
        $shortPassword = ['password' => 'short'];
        $createErrors = $createValidator->isInvalid($shortPassword);
        $this->assertArrayHasKey('password', $createErrors);

        // Create validation: valid password
        $validPassword = ['password' => 'validpassword123'];
        $this->assertNull($createValidator->isInvalid($validPassword));

        // Update validation: id required
        $noId = ['id' => null];
        $updateErrors = $updateValidator->isInvalid($noId);
        $this->assertArrayHasKey('id', $updateErrors);

        // Update validation: valid id
        $validId = ['id' => 123];
        $this->assertNull($updateValidator->isInvalid($validId));
    }

    public function testValidatorStoreAutoBuildsWithPurpose(): void
    {
        $store = new ValidatorStore();

        // Auto-build should work for all purposes
        $coreValidator = $store->get(TestUserWithAttributes::class);
        $createValidator = $store->get(TestUserWithAttributes::class, Purpose::Create);
        $updateValidator = $store->get(TestUserWithAttributes::class, Purpose::Update);

        // All should be valid Validator instances
        $this->assertInstanceOf(Validator::class, $coreValidator);
        $this->assertInstanceOf(Validator::class, $createValidator);
        $this->assertInstanceOf(Validator::class, $updateValidator);

        // Core should have email, Create should have password, Update should have id
        $this->assertNotNull($coreValidator->email);
        $this->assertNotNull($createValidator->password);
        $this->assertNotNull($updateValidator->id);
    }

    public function testValidatorFunctionWithAttributePurpose(): void
    {
        // Using validator() function with purpose auto-builds from attributes
        $createValidator = \mini\validator(TestUserWithAttributes::class, Purpose::Create);

        // Should include Create-purpose password validation
        $this->assertNotNull($createValidator->password);

        // Validate: short password should fail
        $errors = $createValidator->isInvalid(['password' => 'short']);
        $this->assertArrayHasKey('password', $errors);

        // Valid password should pass
        $this->assertNull($createValidator->isInvalid(['password' => 'longpassword']));
    }
};

$test->run();
