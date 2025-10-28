# mini\Codecs

Codecs are classes that convert between PHP types and backend storage formats. They enable seamless translation between rich PHP objects (like `DateTime`) and the limited data types supported by storage backends (strings, integers, JSON, etc.).

## Architecture Overview

The codec system uses **interface-per-backend-type** design for maximum type safety and clarity:

- **`CodecInterface`** - Base marker interface for all codecs
- **Backend Type Interfaces** - Define conversion methods for specific storage formats:
  - `StringCodecInterface` - Convert to/from string backends (CSV, text files)
  - `IntegerCodecInterface` - Convert to/from integer backends (Unix timestamps)
  - `FloatCodecInterface` - Convert to/from float backends (numeric precision)
  - `BooleanCodecInterface` - Convert to/from boolean backends (native bool support)
  - `DateTimeCodecInterface` - Convert to/from datetime backends (native datetime)
  - `ArrayCodecInterface` - Convert to/from array backends (native arrays)
  - `JsonCodecInterface` - Convert to/from JSON backends (MongoDB, PostgreSQL JSONB)

## Example Codec Implementation

```php
// DateTime codec supporting both string and integer backends
$dateTimeCodec = new class implements StringCodecInterface, IntegerCodecInterface {
    public function fromBackendString(string $value): DateTime {
        return new DateTime($value);
    }

    public function toBackendString(mixed $value): string {
        return $value->format('Y-m-d H:i:s');
    }

    public function fromBackendInteger(int $value): DateTime {
        return (new DateTime())->setTimestamp($value);
    }

    public function toBackendInteger(mixed $value): int {
        return $value->getTimestamp();
    }
};

// Register the codec for DateTime types
use mini\Tables\CodecRegistry;
CodecRegistry::register(DateTime::class, $dateTimeCodec);
```

## Capability Detection

Codec capabilities are detected using PHP's native `instanceof` operator:

```php
// Check if codec supports different backend types
if ($codec instanceof StringCodecInterface) {
    // Can convert to/from strings
    $wrapper = new StringCodecWrapper($codec, $fieldName);
}

if ($codec instanceof JsonCodecInterface) {
    // Can convert to/from JSON
    $wrapper = new JsonCodecWrapper($codec, $fieldName);
}
```

## Registration System

Codecs are managed through the `CodecRegistry` static class:

```php
use mini\Tables\CodecRegistry;

// Register custom codec for your type
CodecRegistry::register(MyCustomClass::class, $myCodec);

// Get registered codec (used internally by Tables)
$codec = CodecRegistry::get(DateTime::class);

// Check if codec exists
if (CodecRegistry::has(MyCustomClass::class)) {
    // Handle custom type...
}

// Built-in codecs (DateTime, DateTimeImmutable) registered automatically
```

## Codec Strategies

Strategies determine which backend type to use for a given field and property:

### ScalarCodecStrategy
Prefers string backends since scalar sources (CSV, JSON files) store everything as strings:

```php
// Property: public DateTime $createdAt;
// Column: datetime
// Result: Uses StringCodecWrapper with DateTime codec (ISO 8601 strings)
```

### DatabaseCodecStrategy (Future)
Could prefer native backend types when available:

```php
// PostgreSQL: prefers DateTimeCodecInterface (native timestamp support)
// SQLite: prefers StringCodecInterface (limited native types)
```

## Wrapper System

Wrappers bridge the new codec interfaces to the existing `FieldCodecInterface`:

- **`StringCodecWrapper`** - Adapts `StringCodecInterface` to `FieldCodecInterface`
- **`IntegerCodecWrapper`** - Adapts `IntegerCodecInterface` to `FieldCodecInterface`
- **`JsonCodecWrapper`** - Adapts `JsonCodecInterface` to `FieldCodecInterface` (future)

## Key Benefits

1. **Type Safety** - Compile-time guarantees through interface implementation
2. **Extensibility** - Register codecs for any PHP type without framework changes
3. **Backend Agnostic** - Same codec works with multiple storage backends
4. **Performance** - `instanceof` checks are faster than method calls
5. **Simplicity** - No complex inheritance, just implement required interfaces
6. **IDE Support** - Full autocomplete and type checking

## Built-in Codec Support

The framework automatically registers codecs for common types:

- `DateTime` - Supports string (ISO 8601) and integer (Unix timestamp) backends
- `DateTimeImmutable` - Supports string (ISO 8601) and integer (Unix timestamp) backends

More built-in codecs can be added by implementing the interfaces and registering them in `CodecRegistry::registerBuiltins()`.