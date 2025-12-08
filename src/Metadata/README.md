# Metadata System

The Metadata system provides JSON Schema annotation support for documenting classes, properties, and data structures. It complements the Validator system by adding human-readable documentation, UI hints, and semantic information.

## Overview

Metadata is built on JSON Schema's annotation vocabulary, providing standardized ways to describe your data:

- **title**: Short, human-readable label
- **description**: Detailed explanation of purpose
- **examples**: Sample values that validate against the schema
- **default**: Suggested default value (documentary only)
- **readOnly**: Indicates value should not be modified
- **writeOnly**: Indicates value may be set but remains hidden
- **deprecated**: Marks data as deprecated
- **format**: Semantic hint about string format (e.g., 'email', 'uri')

## Basic Usage

### Using Attributes (Recommended)

The preferred way to define metadata is using PHP attributes:

```php
use mini\Metadata\Attributes as Meta;

#[Meta\Title('User Account')]
#[Meta\Description('Represents a user in the system')]
class User
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
}

// Automatically builds metadata from attributes
$userMeta = mini\metadata(User::class);
echo json_encode($userMeta, JSON_PRETTY_PRINT);
```

### Property-less Metadata (Interfaces/DTOs)

Use the `Property` attribute for interfaces or classes without actual properties:

```php
#[Meta\Property(name: 'username', title: 'Username', description: 'User login', readOnly: true)]
#[Meta\Property(name: 'email', title: 'Email Address', format: 'email')]
interface UserInterface {}

$meta = mini\metadata(UserInterface::class);
```

### Programmatic Metadata (Alternative)

```php
use mini\Metadata\Metadata;

$usernameMeta = mini\metadata()
    ->title(t('Username'))
    ->description(t('Unique identifier for user login'))
    ->examples('johndoe', 'frode1977')
    ->readOnly(true);

echo json_encode($usernameMeta, JSON_PRETTY_PRINT);
```

Output:
```json
{
    "title": "Username",
    "description": "Unique identifier for user login",
    "examples": ["johndoe", "frode1977"],
    "readOnly": true
}
```

### Class/Entity Metadata

```php
use mini\Metadata\MetadataStore;

// Register metadata for a class
$store = mini\Mini::$mini->get(MetadataStore::class);
$store[User::class] = mini\metadata()
    ->title(t('User'))
    ->description(t('Represents a user account in the system'))
    ->properties([
        'username' => mini\metadata()
            ->title(t('Username'))
            ->description(t('Unique login identifier'))
            ->examples('johndoe', 'admin123')
            ->readOnly(true),
        'email' => mini\metadata()
            ->title(t('Email'))
            ->description(t('User email address'))
            ->format('email'),
        'password' => mini\metadata()
            ->title(t('Password'))
            ->writeOnly(true)
    ]);

// Access stored metadata
$userMeta = mini\metadata(User::class);
echo $userMeta->jsonSerialize()['title']; // "User"

// Access property metadata
$usernameMeta = mini\metadata(User::class)->username;
echo $usernameMeta->jsonSerialize()['title']; // "Username"
```

### Array/Collection Metadata

```php
$intArrayMeta = mini\metadata()
    ->title(t('Integer Array'))
    ->description(t('List of integers'))
    ->items(mini\metadata()->title(t('Integer')));

echo json_encode($intArrayMeta, JSON_PRETTY_PRINT);
```

Output:
```json
{
    "title": "Integer Array",
    "description": "List of integers",
    "items": {
        "title": "Integer"
    }
}
```

## Integration with Validator

Metadata and Validator work independently but complement each other:

```php
// Define validation rules
$validator = mini\validator()
    ->type('object')
    ->properties([
        'username' => mini\validator()->type('string')->required()->minLength(3),
        'email' => mini\validator()->type('string')->required()->format('email'),
        'age' => mini\validator()->type('integer')->minimum(18)
    ]);

// Define metadata
$store = mini\Mini::$mini->get(MetadataStore::class);
$store[User::class] = mini\metadata()
    ->title('User Account')
    ->description('System user')
    ->properties([
        'username' => mini\metadata()->title('Username')->readOnly(true),
        'email' => mini\metadata()->title('Email')->format('email'),
        'age' => mini\metadata()->title('Age')
    ]);

// Both serialize to JSON Schema independently
$validatorSchema = json_encode($validator);
$metadataSchema = json_encode(mini\metadata(User::class));

// Application code can merge them as needed
// For example, in a /schema.json endpoint:
// $fullSchema = array_merge_recursive(
//     json_decode($validatorSchema, true),
//     json_decode($metadataSchema, true)
// );
```

This separation keeps framework concerns focused while allowing applications to combine schemas for specific use cases like OpenAPI generation, form builders, or documentation endpoints.

## Attribute Reference

### Available Attributes

All attributes are in the `mini\Metadata\Attributes` namespace:

- **`Title(string $title)`** - Short, human-readable label (class or property)
- **`Description(string $description)`** - Detailed explanation (class or property)
- **`Examples(mixed ...$examples)`** - Sample values (property only)
- **`DefaultValue(mixed $default)`** - Suggested default value (property only)
- **`IsReadOnly(bool $value = true)`** - Mark as read-only (property only)
- **`IsWriteOnly(bool $value = true)`** - Mark as write-only (property only)
- **`IsDeprecated(bool $value = true)`** - Mark as deprecated (class or property)
- **`MetaFormat(string $format)`** - Format hint like 'email', 'uri' (property only)
- **`Property(...)`** - Define property metadata without actual property (class only, repeatable)
- **`Ref(string $class)`** - Reference another class's metadata (property only)

### Class References and Auto-Resolution

Properties with class/interface type hints automatically resolve to that class's metadata:

```php
#[Meta\Title('Group')]
class Group {
    #[Meta\Title('Name')]
    public string $name;
}

class User {
    public Group $group;  // Auto-resolves to Group metadata
}

$meta = mini\metadata(User::class);
$meta->group->name;  // Returns Group's name metadata
```

Use `Ref` to override the type hint or specify a reference for untyped properties:

```php
#[Meta\Title('Admin Group')]
class AdminGroup {}

class User {
    #[Meta\Ref(AdminGroup::class)]
    public Group $group;  // Resolves to AdminGroup instead of Group
}
```

### Combining with Validator Attributes

Metadata and Validator attributes work together seamlessly:

```php
use mini\Metadata\Attributes as Meta;
use mini\Validator\Attributes as Valid;

class User
{
    #[Valid\Required]
    #[Valid\MinLength(3)]
    #[Valid\Type('string')]
    #[Meta\Title('Username')]
    #[Meta\Description('User login identifier')]
    #[Meta\IsReadOnly]
    public string $username;
}

// Both systems read the same class
$validator = mini\validator(User::class);
$metadata = mini\metadata(User::class);
```

## API Reference

### Metadata Class

#### Annotation Methods

All annotation methods return a cloned instance (immutable):

- `title(Stringable|string|null $title): static`
- `description(Stringable|string|null $description): static`
- `default(mixed $default): static`
- `examples(mixed ...$examples): static`
- `readOnly(bool $readOnly = true): static`
- `writeOnly(bool $writeOnly = true): static`
- `deprecated(bool $deprecated = true): static`
- `format(?string $format): static`

#### Structure Methods

- `properties(array<string, Metadata> $properties): static` - Define metadata for object properties
- `items(Metadata $items): static` - Define metadata for array items

#### Magic Methods

- `__get(string $property): ?Metadata` - Access property metadata via magic properties

### Helper Function

```php
function mini\metadata(?string $classOrName = null): Metadata
```

- Without arguments: Returns a new Metadata instance
- With class/identifier: Returns cached metadata from MetadataStore, or empty Metadata if not found

### MetadataStore Class

Extends `InstanceStore<Metadata>` and provides:

- Array access: `$store[User::class] = $metadata`
- Property access: `$store->User`
- Methods: `has()`, `get()`, `set()`, `delete()`

## Translation Support (i18n)

String values in attributes are automatically wrapped in `Translatable` for i18n support:

```php
#[Meta\Title('Username')]  // Automatically translatable
#[Meta\Description('The unique login identifier')]
class User {}

// The AttributeMetadataFactory wraps strings in Translatable with the
// correct source file, enabling translation extraction tools to find them.
$meta = mini\metadata(User::class);
// $meta's title is a Translatable with sourceFile = 'src/Entity/User.php'
```

For programmatic metadata, you can use `t()` directly:

```php
$meta = mini\metadata()
    ->title(t('Username'))
    ->description(t('The unique login identifier'));
```

Both approaches preserve translation context until JSON serialization, when strings are resolved to the current locale.

## Design Philosophy

1. **Non-validating**: Metadata provides documentation, not validation
2. **Composable**: Can be nested for complex structures
3. **Translatable**: Strings in attributes are auto-wrapped for i18n; accepts `Translatable` instances
4. **Permissive**: All fields optional, add only what's needed
5. **Immutable**: Methods return cloned instances
6. **JSON Schema compliant**: Maps directly to JSON Schema annotation keywords

## Use Cases

- **API Documentation**: Generate OpenAPI/Swagger documentation
- **Form Generation**: Build UI forms from metadata
- **Code Documentation**: Self-documenting data structures
- **Validation Messages**: Provide context for validation errors
- **IDE Support**: Enable better autocomplete and hints
- **Database Schemas**: Document table and column purposes

## Examples

See `test-metadata.php` in the project root for comprehensive examples.
