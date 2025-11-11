# Converter System Concept

## Overview

A type-based conversion system that allows registering handlers to transform values from one type to another. The system uses reflection to match input/output types and finds the most appropriate converter.

## Core API

```php
// Register a converter using a typed closure
$registry->register(function(string $text): ResponseInterface {
    return new Response(200, $text);
});

// Convert a value to target type
$response = mini\convert($someValue, ResponseInterface::class);
// Returns ResponseInterface|null (null if no converter found)
```

## Primary Use Cases

### 1. Route Return Value Handling
Routes can return any value - the converter system transforms it to a PSR-7 Response:

```php
// _routes/api/users.php
return ['users' => $users]; // Converter transforms to JSON/HTML based on Accept header

// _routes/ping.php
return "pong"; // Converter transforms to text/plain response

// _routes/profile.php
return $user; // Converter serializes object to appropriate format
```

### 2. Exception to HTTP Response
Exceptions are automatically converted to appropriate error pages:

```php
throw new NotFoundException('User not found');
// Converter transforms to 404 error page response

throw new ValidationException($errors);
// Converter transforms to 400 error page with validation messages
```

### 3. Content Negotiation
Single converter handles different output formats based on Accept header:

```php
$registry->register(function(array $data): ResponseInterface {
    $accept = request()->getHeaderLine('Accept');
    if (str_contains($accept, 'json')) {
        return jsonResponse($data);
    }
    return htmlResponse(render('data-view.php', ['data' => $data]));
});
```

## Technical Details

- Converters are closures with typed parameters: `function(InputType $in): OutputType`
- System uses reflection to extract input/output types
- Supports union types: `function(string|array $data): ResponseInterface`
- Null inputs/outputs are explicitly rejected
- Most recently registered converter wins (allows overrides)
- Returns `null` if no suitable converter found

## Benefits

1. **Cleaner route handlers** - Return domain values, not HTTP concerns
2. **Automatic content negotiation** - Single code path for JSON/HTML/XML
3. **Consistent error handling** - All exceptions convert to appropriate responses
4. **Testable** - Route logic returns values, not side effects
5. **Extensible** - Applications can register custom converters for their types
6. **Type-safe** - Leverages PHP's type system via reflection

## Implementation Structure

```
src/Converter/
  ├── ConverterInterface.php       # Interface for converter implementations
  ├── ClosureConverter.php         # Wraps closures, uses reflection for types
  ├── ConverterRegistry.php        # Manages converters, performs lookups
  └── functions.php                # mini\convert() helper function
```

Built-in converters registered by framework:
- `Throwable → ResponseInterface` (exception handling)
- `string → ResponseInterface` (text responses)
- `array → ResponseInterface` (JSON/HTML based on Accept)
- `ResponseInterface → ResponseInterface` (passthrough)

## Future Possibilities

Beyond HTTP, the system could handle other conversions:
- `Throwable → CliResponse` (CLI exception handling)
- `DomainModel → array` (serialization)
- `array → DomainModel` (deserialization)
- `MarkdownString → HtmlString` (content transformation)
