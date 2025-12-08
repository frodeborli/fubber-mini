# Validator - JSON Schema Validation

## Philosophy

Mini provides **composable, type-safe validation** with JSON Schema-compatible rules. Build validation rules with a fluent API, export them as JSON Schema for client-side validation, and customize error messages for perfect UX.

**Key Principles:**
- **JSON Schema compatible** - Export validation rules for client-side reuse
- **Fully immutable** - Chain validators without side effects
- **Custom error messages** - User-friendly validation feedback via `x-error`
- **Context-aware validation** - Access parent objects for relational validation
- **Type-specific rules** - Constraints only apply to matching types
- **Zero dependencies** - Pure PHP validation, no external libraries

## Setup

```php
use function mini\validator;
```

### Basic Usage

Validators work out of the box - just create and use:

```php
// Create a validator
$validator = validator()
    ->type('string')
    ->minLength(3)
    ->maxLength(20);

// Validate a value
$error = $validator->isInvalid('ab');
if ($error) {
    echo "Error: $error"; // "Must be at least 3 characters long."
}
```

### Return Values

`isInvalid($value)` returns `null` if valid, or a `ValidationError` if invalid. The `ValidationError` class implements:
- **Stringable**: Cast to string for the error message
- **ArrayAccess**: Access property errors via `$error['fieldName']`
- **IteratorAggregate**: Iterate over property errors with `foreach`
- **JsonSerializable**: Export for API responses

```php
// Scalar validation
$error = $stringValidator->isInvalid($value);
if ($error) {
    echo $error; // "Must be at least 3 characters."
}

// Object validation - access property errors
$error = $objectValidator->isInvalid($data);
if ($error) {
    echo $error;              // "Validation failed."
    echo $error['username'];  // "Username is required."
    echo $error['email'];     // "Invalid email format."

    // Iterate all errors
    foreach ($error as $field => $fieldError) {
        echo "$field: $fieldError\n";
    }

    // Nested objects
    echo $error['address']['city']; // drills down

    // JSON for APIs
    echo json_encode($error);
}
```

### Common Validation Patterns

**String Validation:**
```php
$usernameValidator = validator()
    ->type('string')
    ->minLength(3, 'Username must be at least 3 characters.')
    ->maxLength(20, 'Username cannot exceed 20 characters.')
    ->pattern('/^[a-zA-Z0-9_]+$/', 'Only letters, numbers, and underscores allowed.')
    ->required('Username is required.');
```

**Numeric Validation:**
```php
$ageValidator = validator()
    ->type('integer')
    ->minimum(18, 'You must be at least 18 years old.')
    ->maximum(120, 'Please enter a valid age.');
```

**Email Validation:**
```php
$emailValidator = validator()
    ->type('string')
    ->format('email')
    ->required('Email address is required.');
```

**Array Validation:**
```php
$tagsValidator = validator()
    ->type('array')
    ->items(validator()->type('string'))
    ->minItems(1, 'Please select at least one tag.')
    ->maxItems(5, 'Maximum 5 tags allowed.')
    ->uniqueItems();
```

## Attribute-Based Validation

Define validation schemas using PHP 8 attributes on classes and interfaces:

```php
use mini\Validator\Attributes as V;

// Define validation on an interface (no properties needed!)
#[V\Field(name: 'username', type: 'string', minLength: 3, maxLength: 20, required: true)]
#[V\Field(name: 'email', type: 'string', format: 'email', required: true)]
#[V\Field(name: 'age', type: 'integer', minimum: 18, maximum: 120)]
interface LoginForm {}

// Get validator from class/interface
$validator = validator(LoginForm::class);
$errors = $validator->isInvalid($_POST);
```

### Property-Based Attributes

Add validation directly to class properties:

```php
use mini\Validator\Attributes as V;

class User {
    #[V\Type('string')]
    #[V\MinLength(3, 'Username must be at least 3 characters.')]
    #[V\MaxLength(20, 'Username cannot exceed 20 characters.')]
    #[V\Pattern('/^[a-zA-Z0-9_]+$/', 'Only letters, numbers, and underscores allowed.')]
    #[V\Required('Username is required.')]
    public string $username;

    #[V\Type('string')]
    #[V\Format('email')]
    #[V\Required('Email is required.')]
    public string $email;

    #[V\Type('integer')]
    #[V\Minimum(18, 'Must be at least 18 years old.')]
    public int $age;
}

$validator = validator(User::class);
$errors = $validator->isInvalid($_POST);
```

### Field Attribute (Property-less)

The `#[Field]` attribute allows defining validation schemas without actual properties. Perfect for:
- **Interfaces** - Define validation contracts
- **DTOs** - Lean data transfer objects
- **Form schemas** - Validation without class structure

```php
// Complex validation on empty class
#[V\Field(name: 'id', type: 'string', pattern: '/^[A-Z]{2}\d{6}$/', required: true)]
#[V\Field(name: 'status', type: 'string', enum: ['active', 'pending', 'disabled'], required: true)]
#[V\Field(name: 'tags', type: 'array', minItems: 1, maxItems: 5, uniqueItems: true)]
class AccountDTO {}
```

All validation rules can be passed as named parameters to `#[Field]`:
- `name` - Field name (required)
- `type` - JSON Schema type
- `minLength`, `maxLength` - String constraints
- `minimum`, `maximum`, `exclusiveMinimum`, `exclusiveMaximum` - Numeric constraints
- `multipleOf` - Number must be multiple of value
- `pattern` - Regex pattern
- `format` - String format (email, uri, date, uuid, etc.)
- `minItems`, `maxItems`, `uniqueItems` - Array constraints
- `minProperties`, `maxProperties` - Object constraints
- `required` - Mark field as required
- `const` - Exact value match
- `enum` - Array of allowed values
- `message` - Custom error message

### Mixed Approach

Combine `#[Field]` attributes with property attributes:

```php
#[V\Field(name: 'metadata', type: 'object', minProperties: 1)]
class Product {
    #[V\Type('string'), V\MinLength(3), V\Required()]
    public string $name;

    #[V\Type('number'), V\Minimum(0)]
    public float $price;
}

// Validates: name, price (properties) + metadata (Field attribute)
$validator = validator(Product::class);
```

### Reusable Validator Library

Define a library of reusable field validators and compose them into form validators:

```php
use mini\Validator\Attributes as V;

// Define reusable validators as class properties
class Validators {
    #[V\Type('string')]
    #[V\MinLength(3, 'Username must be at least 3 characters.')]
    #[V\MaxLength(20, 'Username cannot exceed 20 characters.')]
    #[V\Pattern('/^[a-zA-Z0-9_]+$/', 'Only letters, numbers, and underscores.')]
    #[V\Required('Username is required.')]
    public string $username;

    #[V\Type('string')]
    #[V\Format('email', 'Please enter a valid email.')]
    #[V\Required('Email is required.')]
    public string $email;

    #[V\Type('string')]
    #[V\MinLength(8, 'Password must be at least 8 characters.')]
    #[V\Required('Password is required.')]
    public string $password;
}

// Compose into different forms by picking the validators you need
$loginForm = validator()
    ->type('object')
    ->forProperty('username', validator(Validators::class)->username)
    ->forProperty('password', validator(Validators::class)->password);

$registrationForm = validator()
    ->type('object')
    ->forProperty('username', validator(Validators::class)->username)
    ->forProperty('email', validator(Validators::class)->email)
    ->forProperty('password', validator(Validators::class)->password);

// Each form has its own validation with shared, consistent rules
$errors = $loginForm->isInvalid($_POST);
```

Property validators are accessed via magic `__get`, making composition feel natural.

### Validator Registry & Caching

Validators are automatically cached for performance:

```php
// First call: builds from attributes
$validator = validator(User::class);

// Subsequent calls: returns cached validator
$validator = validator(User::class);
```

Manual registration for custom validators:

```php
use mini\Validator\ValidatorStore;

$store = Mini::$mini->get(ValidatorStore::class);
$store['custom-validator'] = validator()
    ->type('string')
    ->minLength(5);

// Retrieve custom validator
$validator = validator('custom-validator');
```

### Available Attributes

All validator rules have corresponding attributes:

**Type & Required:**
- `#[Type(string $type, ?string $message)]`
- `#[Required(?string $message)]`

**String Constraints:**
- `#[MinLength(int $min, ?string $message)]`
- `#[MaxLength(int $max, ?string $message)]`
- `#[Pattern(string $pattern, ?string $message)]`
- `#[Format(string $format, ?string $message)]`

**Numeric Constraints:**
- `#[Minimum(int|float $min, ?string $message)]`
- `#[Maximum(int|float $max, ?string $message)]`
- `#[ExclusiveMinimum(int|float $min, ?string $message)]`
- `#[ExclusiveMaximum(int|float $max, ?string $message)]`
- `#[MultipleOf(int|float $divisor, ?string $message)]`

**Array Constraints:**
- `#[MinItems(int $min, ?string $message)]`
- `#[MaxItems(int $max, ?string $message)]`
- `#[UniqueItems()]`

**Object Constraints:**
- `#[MinProperties(int $min, ?string $message)]`
- `#[MaxProperties(int $max, ?string $message)]`

**Value Constraints:**
- `#[Const(mixed $value, ?string $message)]`
- `#[Enum(array $values, ?string $message)]`

**Virtual Fields:**
- `#[Field(...)]` - Define field without property (repeatable)

## Object Validation

Validate complex nested objects with property-specific validators:

```php
$userValidator = validator()
    ->type('object')
    ->forProperty('username',
        validator()
            ->type('string')
            ->minLength(3)
            ->required()
    )
    ->forProperty('email',
        validator()
            ->type('string')
            ->format('email')
            ->required()
    )
    ->forProperty('age',
        validator()
            ->type('integer')
            ->minimum(18)
    );

// Validate user data
$userData = ['username' => 'ab', 'email' => 'invalid'];
$errors = $userValidator->isInvalid($userData);

if ($errors) {
    // $errors = ['username' => 'Must be at least 3 characters long.',
    //            'email' => 'Invalid email format.']
}
```

## Context-Aware Validation

Custom validators receive the field value and parent object/array:

```php
->custom(function (mixed $value, mixed $parent): bool {
    // Return true if valid, false if invalid
})
```

The callback returns `true` for valid, `false` for invalid (uses default "Validation failed." message). Custom validators are server-side only and not exported to JSON Schema.

**Password confirmation example:**

```php
$registrationValidator = validator()
    ->type('object')
    ->forProperty('password',
        validator()->type('string')->minLength(8)->required()
    )
    ->forProperty('password_confirmation',
        validator()
            ->type('string')
            ->required('Please confirm your password.')
            ->custom(fn($confirmation, $data) =>
                isset($data['password']) && $confirmation === $data['password']
            )
    );

$data = [
    'password' => 'secret123',
    'password_confirmation' => 'different'
];

$errors = $registrationValidator->isInvalid($data);
// $errors = ['password_confirmation' => 'Validation failed.']
```

**Use Cases:**
- Password confirmation
- Conditional required fields (e.g., state required if country is US)
- Database relationship validation
- Cross-field validation

## JSON Schema Export

Export validators as JSON Schema for client-side validation:

```php
$validator = validator()
    ->type('string')
    ->minLength(5, 'Too short!')
    ->maxLength(100, 'Too long!')
    ->format('email');

$schema = json_encode($validator, JSON_PRETTY_PRINT);
```

Output:
```json
{
    "type": "string",
    "minLength": 5,
    "maxLength": 100,
    "format": "email",
    "x-error": {
        "minLength": "Too short!",
        "maxLength": "Too long!"
    }
}
```

The `x-error` extension maps JSON Schema keyword names (`minLength`, `pattern`, etc.) to their custom error messages. Keywords without custom messages are omitted. Note that `custom()` validators are server-side only and not included in the exported schema.

## Available Validators

### Type Validators

```php
->type('string')           // String type
->type('integer')          // Integer type
->type('number')           // Number (int or float)
->type('boolean')          // Boolean type
->type('array')            // Array type (sequential)
->type('object')           // Object type (associative array or PHP object)
->type('null')             // Null type
->type(['string', 'null']) // Multiple types (string OR null)
```

### String Constraints

```php
->minLength(int $min, ?string $message)
->maxLength(int $max, ?string $message)
->pattern(string $regex, ?string $message)
->format(string $format, ?string $message) // 'email', 'uri', 'date', 'uuid', etc.
```

### Numeric Constraints

```php
->minimum(int|float $min, ?string $message)
->maximum(int|float $max, ?string $message)
->exclusiveMinimum(int|float $min, ?string $message)
->exclusiveMaximum(int|float $max, ?string $message)
->multipleOf(int|float $divisor, ?string $message)
```

### Array Constraints

```php
->minItems(int $min, ?string $message)
->maxItems(int $max, ?string $message)
->uniqueItems()
->items(Validator $validator)              // All items match validator
->items([Validator, Validator, ...])       // Tuple validation
->additionalItems(Validator|bool)          // Validate items beyond tuple
->minContains(int $min, Validator $validator)
->maxContains(int $max, Validator $validator)
```

### Object Constraints

```php
->minProperties(int $min, ?string $message)
->maxProperties(int $max, ?string $message)
->forProperty(string $property, Validator $validator)
->properties(array $validators)            // ['prop' => Validator, ...]
->patternProperties(string $pattern, Validator $validator)
->additionalProperties(Validator|bool)
->dependentRequired(string $property, array $requiredProps)
->withFields(array $fields)                // Clone with only these properties
->withoutFields(array $fields)             // Clone excluding these properties
```

### Other Validators

```php
->const(mixed $value, ?string $message)    // Exact value match
->enum(array $values, ?string $message)    // Value in allowed list
->required(?string $message)               // Mark field as required
->custom(Closure $callback)                // Custom validation logic
```

### Combinators

```php
->allOf([Validator, ...])  // Must match all validators
->anyOf([Validator, ...])  // Must match at least one validator
->oneOf([Validator, ...])  // Must match exactly one validator
->not(Validator)           // Must NOT match validator
```

## Advanced Examples

### Conditional Validation

```php
$addressValidator = validator()
    ->type('object')
    ->forProperty('country', validator()->type('string'))
    ->forProperty('state',
        validator()
            ->type('string')
            ->custom(fn($state, $address) =>
                // State required only for US addresses
                ($address['country'] ?? null) !== 'US' || !empty($state)
            )
    );
```

### Tuple Validation

```php
// Validate [string, integer, boolean] tuples
$tupleValidator = validator()
    ->type('array')
    ->items([
        validator()->type('string'),
        validator()->type('integer'),
        validator()->type('boolean'),
    ])
    ->additionalItems(false); // No additional items allowed
```

### Multiple Types with Type-Specific Constraints

```php
// Accept string OR integer, with type-specific rules
$idValidator = validator()
    ->type(['string', 'integer'])
    ->minLength(5)     // Only applies to strings
    ->multipleOf(2);   // Only applies to integers

$idValidator->isInvalid('abc');   // Invalid (string too short)
$idValidator->isInvalid('hello'); // Valid (string meets minLength)
$idValidator->isInvalid(3);       // Invalid (integer not multiple of 2)
$idValidator->isInvalid(10);      // Valid (integer is multiple of 2)
```

### Nested Object Validation

```php
$orderValidator = validator()
    ->type('object')
    ->forProperty('customer',
        validator()
            ->type('object')
            ->forProperty('name', validator()->type('string')->required())
            ->forProperty('email', validator()->type('string')->format('email'))
    )
    ->forProperty('items',
        validator()
            ->type('array')
            ->items(
                validator()
                    ->type('object')
                    ->forProperty('product_id', validator()->type('integer'))
                    ->forProperty('quantity', validator()->type('integer')->minimum(1))
            )
    );
```

### Partial Validation

Create validators for update operations by excluding or selecting specific fields:

```php
// Full user validator from attributes
$userValidator = validator(User::class);

// For update: exclude password (not required for profile updates)
$profileUpdateValidator = $userValidator->withoutFields(['password']);

// For password change: only validate password fields
$passwordChangeValidator = $userValidator->withFields(['password', 'password_confirmation']);
```

## Immutability

All validator methods return new instances - the original is never modified:

```php
$baseValidator = validator()->type('string');
$emailValidator = $baseValidator->format('email');
$usernameValidator = $baseValidator->minLength(3);

// $baseValidator is unchanged
// $emailValidator and $usernameValidator are independent
```

This makes validators safe to reuse and compose:

```php
$stringValidator = validator()->type('string');

$userSchema = validator()
    ->type('object')
    ->forProperty('username', $stringValidator->minLength(3))
    ->forProperty('bio', $stringValidator->maxLength(500));

// $stringValidator remains unchanged
```

## Error Messages

Custom error messages are stored in the `x-error` extension:

```php
$validator = validator()
    ->type('string')
    ->minLength(8, 'Password must be at least 8 characters.')
    ->pattern('/[A-Z]/', 'Password must contain at least one uppercase letter.')
    ->pattern('/[0-9]/', 'Password must contain at least one number.');

$error = $validator->isInvalid('short');
// Returns: "Password must be at least 8 characters."

$schema = json_encode($validator);
// Includes: "x-error": {"minLength": "Password must be...", "pattern": "..."}
```

## Integration

### Form Validation

```php
// Define schema
$userSchema = validator()
    ->type('object')
    ->forProperty('username', validator()->type('string')->minLength(3)->required())
    ->forProperty('email', validator()->type('string')->format('email')->required());

// Validate POST data
$errors = $userSchema->isInvalid($_POST);

if ($errors) {
    // Display errors in template
    foreach ($errors as $field => $message) {
        echo "<p class='error'>$field: $message</p>";
    }
} else {
    // Process valid data
    createUser($_POST);
}
```

### API Response Validation

```php
$responseValidator = validator()
    ->type('object')
    ->forProperty('status', validator()->enum(['success', 'error']))
    ->forProperty('data', validator()->type('object'));

$response = json_decode($apiResponse, true);
$error = $responseValidator->isInvalid($response);

if ($error) {
    throw new \RuntimeException("Invalid API response: $error");
}
```

## Best Practices

1. **Reuse validators** - Create base validators and compose them
2. **Use custom messages** - Provide user-friendly error messages
3. **Export schemas** - Share validation rules with client-side code
4. **Type-first** - Always specify type before constraints
5. **Immutability** - Take advantage of safe validator composition

## Performance

- **Cached validators** - Validators built from attributes are cached; subsequent calls return clones instantly
- **Fail-fast** - Validation stops at first error for simple values
- **Efficient** - Shallow clones for immutability (no deep copying)
- **Zero overhead** - JSON Schema export via simple array serialization
