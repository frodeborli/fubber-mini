# Converter - Type Conversion System

## Philosophy

Mini provides **automatic type conversion** with reflection-based type matching. Transform route return values, exceptions, and domain objects to HTTP responses without manual serialization code in every route handler.

**Key Principles:**
- **Type-safe** - Uses PHP's type system via reflection
- **Composable** - Register converters for any input→output type combination
- **Union input types** - Single converter handles multiple input types
- **Specificity resolution** - Most specific converter wins (single > union, class > interface > parent)
- **Extensible** - Applications can register custom converters
- **Returns null** - No exceptions when conversion impossible

## Setup

No configuration needed! The converter registry works out of the box:

```php
use mini\Mini;
use mini\Converter\ConverterRegistryInterface;

// Get the registry (automatically created as singleton)
$registry = Mini::$mini->get(ConverterRegistryInterface::class);

// Use convert() helper function
$response = convert($value, ResponseInterface::class);
// Returns null if no converter registered for this type

// Distinguish "no converter" from "converted to null value"
$result = convert($value, 'string', $found);
if (!$found) {
    // No converter was registered for this input→target combination
}
```

### Registering Custom Converters

**Register converters in bootstrap.php, not in config files:**

```php
<?php
// bootstrap.php

use mini\Mini;
use mini\Converter\ConverterRegistryInterface;
use Psr\Http\Message\ResponseInterface;
use mini\Http\Message\Response;

// Get the converter registry
$registry = Mini::$mini->get(ConverterRegistryInterface::class);

// Register application-specific converters
$registry->register(function(MyModel $model): ResponseInterface {
    $json = json_encode($model->toArray());
    return new Response(200, ['Content-Type' => 'application/json'], $json);
});
```

**Config vs Bootstrap:**
- **Config** (`_config/mini/Converter/ConverterRegistryInterface.php`) - Factory for creating registry instance
- **Bootstrap** (`bootstrap.php`) - Where you register application-specific converters

### Custom Registry Implementation

Override the registry implementation in config only if you need a custom registry class:

```php
<?php
// _config/mini/Converter/ConverterRegistryInterface.php

use mini\Converter\ConverterRegistry;

// Custom registry with logging
return new class extends ConverterRegistry {
    public function convert(mixed $input, string $targetType, ?bool &$found = null): mixed {
        error_log("Converting " . get_debug_type($input) . " to $targetType");
        return parent::convert($input, $targetType, $found);
    }
};
```

## Common Usage Examples

### Basic Conversion

```php
// String to response
$response = convert("Hello", ResponseInterface::class);
// → 200 text/plain response with "Hello"

// Array to response
$response = convert(['users' => $users], ResponseInterface::class);
// → 200 application/json response

// Exception to response
$response = convert(new \RuntimeException('Error'), ResponseInterface::class);
// → 500 error page response
```

### Route Return Value Conversion

Routes can return any value - converters transform it to a PSR-7 Response:

```php
// _routes/api/users.php
return ['users' => db()->query("SELECT * FROM users")->toArray()];
// Automatically converted to JSON response

// _routes/ping.php
return "pong";
// Automatically converted to text/plain response

// _routes/profile.php
$user = db()->queryOne("SELECT * FROM users WHERE id = ?", [$_GET['id']]);
return $user ?: throw new NotFoundException('User not found');
// Array converted to JSON, or exception converted to 404 error page
```

### Exception to HTTP Response

Exceptions are automatically converted to appropriate error pages:

```php
throw new \RuntimeException('Something went wrong');
// Converter transforms to 500 error page response

// In debug mode, exception message is shown
// In production, generic "Internal Server Error" message
```

## Registering Converters

**All converter registration happens in bootstrap.php:**

```php
<?php
// bootstrap.php

use mini\Mini;
use mini\Converter\ConverterRegistryInterface;

$registry = Mini::$mini->get(ConverterRegistryInterface::class);

// Now register your converters...
```

### Simple Converter

```php
// bootstrap.php
$registry->register(function(string $text): ResponseInterface {
    return new Response(200, ['Content-Type' => 'text/plain'], $text);
});
```

### Union Input Types

Handle multiple input types with a single converter:

```php
// bootstrap.php
$registry->register(function(string|array $data): ResponseInterface {
    if (is_string($data)) {
        return new Response(200, ['Content-Type' => 'text/plain'], $data);
    }

    $json = json_encode($data);
    return new Response(200, ['Content-Type' => 'application/json'], $json);
});
```

### Content Negotiation

Single converter handles different output formats based on request:

```php
// bootstrap.php
use function mini\request;

$registry->register(function(array $data): ResponseInterface {
    $accept = request()->getHeaderLine('Accept');

    if (str_contains($accept, 'application/json')) {
        $json = json_encode($data);
        return new Response(200, ['Content-Type' => 'application/json'], $json);
    }

    $html = render('data-view', ['data' => $data]);
    return new Response(200, ['Content-Type' => 'text/html'], $html);
});
```

### Custom Domain Objects

```php
// bootstrap.php
interface Jsonable {
    public function toJson(): string;
}

// Any Jsonable object → JSON response
$registry->register(function(Jsonable $obj): ResponseInterface {
    return new Response(
        200,
        ['Content-Type' => 'application/json'],
        $obj->toJson()
    );
});

// Usage in routes
class User implements Jsonable {
    public function toJson(): string {
        return json_encode(['id' => $this->id, 'name' => $this->name]);
    }
}

// _routes/api/user.php
$user = new User();
return $user;  // Automatically converted to JSON response
```

## Advanced Features

### Converter Resolution Order

The registry finds the most specific converter:

1. **Direct single-type converter** (most specific)
2. **Union type converter** (less specific)
3. **Parent class converters** (inheritance)
4. **Interface converters** (implemented interfaces)

```php
// Register converters
$registry->register(function(\Exception $e): ResponseInterface { /* ... */ });
$registry->register(function(\Throwable $e): ResponseInterface { /* ... */ });

// Convert RuntimeException (extends Exception implements Throwable)
$response = convert(new \RuntimeException(), ResponseInterface::class);
// Uses Exception converter (most specific class match)
```

### Union Type Specificity

Single-type converters override union members:

```php
// Register union converter
$registry->register(function(string|int $data): ResponseInterface {
    return new Response(200, [], (string)$data);
});

// Register more specific single-type converter (overrides union member)
$registry->register(function(string $text): ResponseInterface {
    return new Response(200, ['Content-Type' => 'text/plain'], $text);
});

// Convert string
$response = convert("hello", ResponseInterface::class);
// Uses string converter (more specific than union)

// Convert int
$response = convert(42, ResponseInterface::class);
// Uses union converter (no int-specific converter)
```

### Conflict Detection

The registry prevents ambiguous registrations:

```php
// Register union converter
$registry->register(function(string|int $data): ResponseInterface { /* ... */ });

// This will throw InvalidArgumentException (conflict)
$registry->register(function(int|bool $data): ResponseInterface { /* ... */ });
// Error: int already part of another union

// This is OK (single-type overrides union member)
$registry->register(function(string $text): ResponseInterface { /* ... */ });
```

### Replacing Converters

Use `replace()` to override existing converters without throwing conflicts:

```php
// Override the default string→Response converter
$registry->replace(function(string $text): ResponseInterface {
    return new Response(200, ['Content-Type' => 'text/html'], "<p>$text</p>");
});
```

### Named Targets

Register converters with custom target names (bypasses return type validation):

```php
// Convert BackedEnum to SQL value
$registry->register(fn(\BackedEnum $e) => $e->value, 'sql-value');

// Later, use with the named target
$value = $registry->convert($myEnum, 'sql-value');
```

## Interface & Implementation

### ConverterInterface

```php
interface ConverterInterface
{
    /**
     * Get the input type this converter accepts
     *
     * May be a single type or union type string (e.g., "string|array|int").
     */
    public function getInputType(): string;

    /**
     * Get the output type this converter produces
     */
    public function getOutputType(): string;

    /**
     * Check if this converter can handle the given input for target type
     */
    public function supports(mixed $input, string $targetType): bool;

    /**
     * Convert the input to the target type
     */
    public function convert(mixed $input, string $targetType): mixed;
}
```

### ClosureConverter

Wraps typed closures and uses reflection to extract types:

```php
$converter = new ClosureConverter(
    function(string $text): ResponseInterface {
        return new Response(200, [], $text);
    }
);

$converter->getInputType();  // "string"
$converter->getOutputType(); // "Psr\Http\Message\ResponseInterface"
```

**Requirements:**
- Exactly one typed parameter
- Typed return value (non-nullable)
- No nullable input types (`?string`, `mixed` not allowed)
- Union input types allowed (`string|int`)
- Union output types not allowed

### ConverterRegistry API

```php
$registry = new ConverterRegistry();

// Register converter (throws if duplicate)
$registry->register($converter);  // ConverterInterface
$registry->register($closure);    // \Closure (wrapped in ClosureConverter)

// Replace existing converter (allows override)
$registry->replace($closure);     // Overwrites existing without conflict

// Named target (bypasses return type validation)
$registry->register(fn(\BackedEnum $e) => $e->value, 'sql-value');

// Check if converter exists
$has = $registry->has($input, ResponseInterface::class);

// Get converter
$converter = $registry->get($input, ResponseInterface::class);

// Convert value
$response = $registry->convert($input, ResponseInterface::class);
// Returns null if no converter found
```

## Practical Examples

### API Error Handling

```php
// bootstrap.php

// Define custom exception classes
class ValidationException extends \Exception {
    public function __construct(public array $errors) {
        parent::__construct('Validation failed');
    }
}

class NotFoundException extends \Exception {
    public function __construct(string $message = 'Not found') {
        parent::__construct($message);
    }
}

// Get registry and register converters for specific exceptions
$registry = Mini::$mini->get(ConverterRegistryInterface::class);

$registry->register(function(ValidationException $e): ResponseInterface {
    $json = json_encode(['errors' => $e->errors]);
    return new Response(400, ['Content-Type' => 'application/json'], $json);
});

$registry->register(function(NotFoundException $e): ResponseInterface {
    $body = render('404', ['message' => $e->getMessage()]);
    return new Response(404, ['Content-Type' => 'text/html'], $body);
});

// Usage in routes
// _routes/api/users.php
$user = db()->queryOne("SELECT * FROM users WHERE id = ?", [$_GET['id']]);
if (!$user) {
    throw new NotFoundException('User not found');
}
return $user;
```

### Domain Model Serialization

```php
// bootstrap.php

// Domain models
class Product {
    public function __construct(
        public int $id,
        public string $name,
        public float $price
    ) {}
}

class ProductCollection {
    public function __construct(public array $products) {}
}

// Get registry and register converters
$registry = Mini::$mini->get(ConverterRegistryInterface::class);

$registry->register(function(Product $product): ResponseInterface {
    $json = json_encode([
        'id' => $product->id,
        'name' => $product->name,
        'price' => $product->price,
    ]);
    return new Response(200, ['Content-Type' => 'application/json'], $json);
});

$registry->register(function(ProductCollection $collection): ResponseInterface {
    $json = json_encode([
        'products' => array_map(
            fn($p) => ['id' => $p->id, 'name' => $p->name, 'price' => $p->price],
            $collection->products
        )
    ]);
    return new Response(200, ['Content-Type' => 'application/json'], $json);
});

// Routes return domain objects
// _routes/products.php
$products = array_map(
    fn($row) => new Product($row['id'], $row['name'], $row['price']),
    db()->query("SELECT * FROM products")->toArray()
);
return new ProductCollection($products);
```

### Multi-Format API

```php
// bootstrap.php

$registry = Mini::$mini->get(ConverterRegistryInterface::class);

// Accept header-based content negotiation
$registry->register(function(array $data): ResponseInterface {
    $accept = request()->getHeaderLine('Accept');

    // JSON (API clients)
    if (str_contains($accept, 'application/json')) {
        $json = json_encode($data);
        return new Response(200, ['Content-Type' => 'application/json'], $json);
    }

    // XML (legacy clients)
    if (str_contains($accept, 'application/xml')) {
        $xml = arrayToXml($data);
        return new Response(200, ['Content-Type' => 'application/xml'], $xml);
    }

    // HTML (browsers)
    $html = render('data-view', ['data' => $data]);
    return new Response(200, ['Content-Type' => 'text/html'], $html);
});

// Single route handler supports all formats
// _routes/api/users.php
return ['users' => db()->query("SELECT * FROM users")->toArray()];
// Returns JSON, XML, or HTML based on Accept header
```

## Best Practices

1. **Register converters in bootstrap.php** - Not in config files
2. **Config is for factory only** - Use `_config/mini/Converter/ConverterRegistryInterface.php` only to customize the registry implementation
3. **Single responsibility** - One converter per input→output type combination
4. **Use union types wisely** - Only when conversion logic is truly shared
5. **Leverage specificity** - Register general converters, override with specific ones
6. **Handle null gracefully** - Remember `convert()` returns null when no converter found
7. **Type-first design** - Let type system guide converter selection
8. **Content negotiation** - Use Accept header for format selection

## Performance

- **Fast lookup** - O(1) for direct type match, O(n) for type hierarchy walk
- **No overhead** - Reflection only at registration time, not during conversion
- **Efficient storage** - Converters indexed by target type, then input type
- **Lazy evaluation** - Only walks type hierarchy until first match found

## Configuration

**Config File (Factory):**
- `_config/mini/Converter/ConverterRegistryInterface.php` (optional) - Creates the registry instance
- Framework provides default `ConverterRegistry` implementation
- Override only if you need a custom registry class

**Bootstrap File (Registration):**
- `bootstrap.php` (recommended) - Where you register application-specific converters

**Service Registration:**
- `ConverterRegistryInterface` - Singleton (shared converter registry across application)

## Overriding the Registry Implementation

Only override the config file if you need to customize the registry class itself:

```php
<?php
// _config/mini/Converter/ConverterRegistryInterface.php

use mini\Converter\ConverterRegistry;

// Create custom registry with additional logging
return new class extends ConverterRegistry {
    public function convert(mixed $input, string $targetType): mixed
    {
        error_log("Converting " . get_debug_type($input) . " to $targetType");
        return parent::convert($input, $targetType);
    }
};
```

For normal converter registration, use bootstrap.php instead.

## Future Possibilities

Beyond HTTP responses, the converter system can handle other transformations:

- **CLI responses** - `Throwable → CliResponse`
- **Serialization** - `DomainModel → array`
- **Deserialization** - `array → DomainModel`
- **Content transformation** - `MarkdownString → HtmlString`
- **Format conversion** - `Image → Thumbnail`

The type-based approach works for any input→output conversion scenario.
