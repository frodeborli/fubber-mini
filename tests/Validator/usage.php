<?php
/**
 * Test Validator system from a developer's perspective
 *
 * These tests mirror how developers would actually use the validation API
 * in real applications.
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Test;
use mini\Validator\Validator;
use mini\Validator\Attributes as V;

// ============================================================================
// Test entities - realistic domain models
// ============================================================================

#[V\Field(name: 'username', type: 'string', minLength: 3, maxLength: 20, required: true)]
#[V\Field(name: 'email', type: 'string', format: 'email', required: true)]
#[V\Field(name: 'age', type: 'integer', minimum: 18, maximum: 120)]
interface UserFormInterface {}

class User
{
    #[V\Type('string')]
    #[V\MinLength(3, 'Username must be at least 3 characters.')]
    #[V\MaxLength(20, 'Username cannot exceed 20 characters.')]
    #[V\Pattern('/^[a-zA-Z0-9_]+$/', 'Only letters, numbers, and underscores allowed.')]
    #[V\Required('Username is required.')]
    public string $username;

    #[V\Type('string')]
    #[V\Format('email', 'Please enter a valid email address.')]
    #[V\Required('Email is required.')]
    public string $email;

    #[V\Type('string')]
    #[V\MinLength(8, 'Password must be at least 8 characters.')]
    #[V\Required('Password is required.')]
    public string $password;
}

#[V\Field(name: 'product_id', type: 'integer', minimum: 1, required: true)]
#[V\Field(name: 'quantity', type: 'integer', minimum: 1, maximum: 100, required: true)]
class OrderItem {}

$test = new class extends Test {

    protected function setUp(): void
    {
        \mini\bootstrap();
    }

    // ========================================
    // Form validation patterns
    // ========================================

    public function testValidateLoginForm(): void
    {
        $validator = \mini\validator(UserFormInterface::class);

        // Valid login
        $validData = [
            'username' => 'johndoe',
            'email' => 'john@example.com',
            'age' => 25,
        ];
        $this->assertNull($validator->isInvalid($validData));

        // Missing required fields
        $errors = $validator->isInvalid([]);
        $this->assertArrayHasKey('username', $errors);
        $this->assertArrayHasKey('email', $errors);
    }

    public function testValidateWithCustomMessages(): void
    {
        $validator = \mini\validator(User::class);

        // Too short username
        $errors = $validator->isInvalid([
            'username' => 'ab',
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $this->assertArrayHasKey('username', $errors);
        $this->assertSame('Username must be at least 3 characters.', (string)$errors['username']);
    }

    public function testValidateInvalidEmail(): void
    {
        $validator = \mini\validator(User::class);

        $errors = $validator->isInvalid([
            'username' => 'johndoe',
            'email' => 'not-an-email',
            'password' => 'password123',
        ]);

        $this->assertArrayHasKey('email', $errors);
        $this->assertSame('Please enter a valid email address.', (string)$errors['email']);
    }

    // ========================================
    // Programmatic validator building
    // ========================================

    public function testBuildValidatorProgrammatically(): void
    {
        $validator = \mini\validator()
            ->type('object')
            ->forProperty('name',
                \mini\validator()
                    ->type('string')
                    ->minLength(1, 'Name is required')
                    ->required()
            )
            ->forProperty('email',
                \mini\validator()
                    ->type('string')
                    ->format('email')
                    ->required()
            );

        // Valid
        $this->assertNull($validator->isInvalid([
            'name' => 'John',
            'email' => 'john@example.com',
        ]));

        // Invalid
        $errors = $validator->isInvalid([
            'name' => '',
            'email' => 'invalid',
        ]);
        $this->assertArrayHasKey('name', $errors);
        $this->assertArrayHasKey('email', $errors);
    }

    // ========================================
    // Partial validation (withFields/withoutFields)
    // ========================================

    public function testPartialValidationForUpdate(): void
    {
        $fullValidator = \mini\validator(User::class);

        // For update, exclude password
        $updateValidator = $fullValidator->withoutFields(['password']);

        // Should pass without password
        $this->assertNull($updateValidator->isInvalid([
            'username' => 'johndoe',
            'email' => 'john@example.com',
        ]));
    }

    public function testPartialValidationWithSelectedFields(): void
    {
        $fullValidator = \mini\validator(User::class);

        // Only validate username
        $usernameValidator = $fullValidator->withFields(['username']);

        $this->assertNull($usernameValidator->isInvalid([
            'username' => 'validname',
        ]));

        $errors = $usernameValidator->isInvalid([
            'username' => 'ab',
        ]);
        $this->assertArrayHasKey('username', $errors);
    }

    // ========================================
    // Nested object validation
    // ========================================

    public function testNestedObjectValidation(): void
    {
        $addressValidator = \mini\validator()
            ->type('object')
            ->forProperty('street', \mini\validator()->type('string')->required())
            ->forProperty('city', \mini\validator()->type('string')->required())
            ->forProperty('zip', \mini\validator()->type('string')->pattern('/^\d{5}$/'));

        $userValidator = \mini\validator()
            ->type('object')
            ->forProperty('name', \mini\validator()->type('string')->required())
            ->forProperty('address', $addressValidator);

        // Valid nested data
        $validData = [
            'name' => 'John',
            'address' => [
                'street' => '123 Main St',
                'city' => 'Springfield',
                'zip' => '12345',
            ],
        ];
        $this->assertNull($userValidator->isInvalid($validData));

        // Invalid nested data
        $invalidData = [
            'name' => 'John',
            'address' => [
                'street' => '123 Main St',
                // missing city
                'zip' => 'invalid',
            ],
        ];
        $errors = $userValidator->isInvalid($invalidData);
        $this->assertArrayHasKey('address', $errors);
    }

    // ========================================
    // Array validation
    // ========================================

    public function testArrayItemsValidation(): void
    {
        $validator = \mini\validator()
            ->type('array')
            ->items(\mini\validator(OrderItem::class))
            ->minItems(1, 'At least one item required.');

        // Valid order items
        $validItems = [
            ['product_id' => 1, 'quantity' => 2],
            ['product_id' => 2, 'quantity' => 1],
        ];
        $this->assertNull($validator->isInvalid($validItems));

        // Empty array
        $error = $validator->isInvalid([]);
        $this->assertNotNull($error);
    }

    // ========================================
    // Conditional validation with custom
    // ========================================

    public function testPasswordConfirmation(): void
    {
        $validator = \mini\validator()
            ->type('object')
            ->forProperty('password',
                \mini\validator()->type('string')->minLength(8)->required()
            )
            ->forProperty('password_confirmation',
                \mini\validator()
                    ->type('string')
                    ->required('Please confirm your password.')
                    ->custom(fn($val, $ctx) => $val === ($ctx['password'] ?? null))
            );

        // Matching passwords
        $this->assertNull($validator->isInvalid([
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]));

        // Non-matching passwords
        $errors = $validator->isInvalid([
            'password' => 'secret123',
            'password_confirmation' => 'different',
        ]);
        $this->assertArrayHasKey('password_confirmation', $errors);
    }

    // ========================================
    // JSON Schema export
    // ========================================

    public function testExportAsJsonSchema(): void
    {
        $validator = \mini\validator(UserFormInterface::class);

        $json = json_encode($validator, JSON_PRETTY_PRINT);
        $this->assertJson($json);

        $schema = json_decode($json, true);
        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('username', $schema['properties']);
    }

    // ========================================
    // Invokable pattern
    // ========================================

    public function testInvokablePattern(): void
    {
        $validateEmail = \mini\validator()
            ->type('string')
            ->format('email')
            ->required();

        // Can be called as function
        $this->assertNull($validateEmail('test@example.com'));
        $this->assertNotNull($validateEmail('invalid'));
    }

    // ========================================
    // Enum validation
    // ========================================

    public function testEnumValidation(): void
    {
        $validator = \mini\validator()
            ->type('string')
            ->enum(['draft', 'published', 'archived']);

        $this->assertNull($validator->isInvalid('draft'));
        $this->assertNull($validator->isInvalid('published'));
        $this->assertNotNull($validator->isInvalid('invalid'));
    }

    // ========================================
    // Combining validators (anyOf, allOf)
    // ========================================

    public function testAnyOfForMultipleFormats(): void
    {
        // Accept either email or phone
        $contactValidator = \mini\validator()->anyOf([
            \mini\validator()->type('string')->format('email'),
            \mini\validator()->type('string')->pattern('/^\+?[\d\s-]+$/'),
        ]);

        $this->assertNull($contactValidator->isInvalid('test@example.com'));
        $this->assertNull($contactValidator->isInvalid('+1 555-1234'));
        $this->assertNotNull($contactValidator->isInvalid('not valid'));
    }

    // ========================================
    // Additional properties control
    // ========================================

    public function testDisallowAdditionalProperties(): void
    {
        $validator = \mini\validator()
            ->type('object')
            ->forProperty('name', \mini\validator()->type('string'))
            ->additionalProperties(false);

        // Only allowed property
        $this->assertNull($validator->isInvalid(['name' => 'John']));

        // Extra property
        $errors = $validator->isInvalid(['name' => 'John', 'extra' => 'field']);
        $this->assertArrayHasKey('extra', $errors);
    }

    // ========================================
    // Immutability
    // ========================================

    public function testValidatorImmutability(): void
    {
        $base = \mini\validator()->type('string');
        $withMin = $base->minLength(5);
        $withMax = $base->maxLength(10);

        // Base should be unchanged
        $baseSchema = $base->jsonSerialize();
        $this->assertFalse(isset($baseSchema['minLength']));
        $this->assertFalse(isset($baseSchema['maxLength']));

        // Derived validators have their rules
        $this->assertSame(5, $withMin->jsonSerialize()['minLength']);
        $this->assertSame(10, $withMax->jsonSerialize()['maxLength']);
    }

    // ========================================
    // Real-world scenario: API request validation
    // ========================================

    public function testApiRequestValidation(): void
    {
        $createUserRequest = \mini\validator()
            ->type('object')
            ->forProperty('user',
                \mini\validator()
                    ->type('object')
                    ->forProperty('username',
                        \mini\validator()->type('string')->minLength(3)->required()
                    )
                    ->forProperty('email',
                        \mini\validator()->type('string')->format('email')->required()
                    )
            )
            ->forProperty('metadata',
                \mini\validator()
                    ->type('object')
                    ->forProperty('source', \mini\validator()->type('string'))
                    ->forProperty('ip', \mini\validator()->type('string')->format('ipv4'))
            );

        $validRequest = [
            'user' => [
                'username' => 'newuser',
                'email' => 'new@example.com',
            ],
            'metadata' => [
                'source' => 'web',
                'ip' => '192.168.1.1',
            ],
        ];

        $this->assertNull($createUserRequest->isInvalid($validRequest));
    }
};

exit($test->run());
