# Testing

Mini includes a simple test runner with no third-party dependencies.

## Running Tests

```bash
# Run all tests in tests/
vendor/bin/mini test

# Run tests in a specific directory
vendor/bin/mini test tests/

# Run a single test file
vendor/bin/mini test tests/Auth.php

# Filter tests by name
vendor/bin/mini test tests/ Router

# List available tests
vendor/bin/mini test --list
```

## Writing Tests

There are two ways to write tests: class-based (recommended) or procedural.

### Class-Based Tests (Recommended)

Extend `mini\Test` for structured tests with automatic setup and assertions:

```php
<?php
// tests/MyFeature.php

require __DIR__ . '/../vendor/autoload.php';

use mini\Test;

$test = new class extends Test {

    protected function setUp(): void
    {
        // Optional: Pre-bootstrap setup (register services, set env vars)
        // If you don't call bootstrap(), it's called automatically after setUp()
    }

    public function testSomethingWorks(): void
    {
        $result = myFunction();
        $this->assertSame('expected', $result);
    }

    public function testAnotherThing(): void
    {
        $this->assertTrue(isEnabled());
    }
};

exit($test->run());
```

**Lifecycle:**
1. `setUp()` is called once before all tests
2. `bootstrap()` is called automatically if `setUp()` didn't call it
3. All `test*` methods run in sequence

**Pre-bootstrap setup:**

If you need to register services or configure Mini before bootstrap:

```php
protected function setUp(): void
{
    // Register mock services before bootstrap
    Mini::$mini->set(\PDO::class, $this->createMockPdo());
    Mini::$mini->addService('my.service', Lifetime::Singleton, fn() => new MyService());

    \mini\bootstrap();  // Call when ready
}
```

### Procedural Tests

Simple scripts that exit 0 on success:

```php
<?php
// tests/MyFeature.php

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/assert.php';

\mini\bootstrap();

assert_eq('expected', myFunction());
assert_true(isEnabled());

echo "✓ All assertions passed\n";
```

## Available Assertions

### Class-Based (`mini\Test`)

| Method | Description |
|--------|-------------|
| `$this->assertTrue($value, $msg)` | Value is `true` |
| `$this->assertFalse($value, $msg)` | Value is `false` |
| `$this->assertSame($expected, $actual, $msg)` | Strict equality (`===`) |
| `$this->assertEquals($expected, $actual, $msg)` | Loose equality (`==`) |
| `$this->assertNull($value, $msg)` | Value is `null` |
| `$this->assertNotNull($value, $msg)` | Value is not `null` |
| `$this->assertThrows($fn, $class, $msg)` | Callable throws exception |
| `$this->assertContains($needle, $haystack, $msg)` | String contains substring |
| `$this->assertCount($expected, $array, $msg)` | Array/Countable has count |
| `$this->assertInstanceOf($class, $value, $msg)` | Value is instance of class |
| `$this->fail($msg)` | Force test failure |
| `$this->log($msg)` | Log intermediate output |

### Procedural (`assert.php`)

| Function | Description |
|----------|-------------|
| `assert_eq($expected, $actual)` | Strict equality (`===`) |
| `assert_true($value)` | Value is `true` |
| `assert_false($value)` | Value is `false` |
| `assert_null($value)` | Value is `null` |
| `assert_not_null($value)` | Value is not `null` |
| `assert_throws($fn, $class)` | Callable throws exception |
| `assert_contains($needle, $haystack)` | String contains substring |
| `assert_has_key($key, $array)` | Array has key |
| `assert_count($expected, $array)` | Array/Countable has count |

## Test Isolation

Each test **file** runs in a separate PHP process, providing isolation between files. However, test **methods** within a class share state since `Mini::$mini` is a singleton created at autoload time.

This means:
- Services registered in `setUp()` persist across all test methods
- Once `bootstrap()` is called, the framework is in Ready phase for all methods
- Test methods can depend on state from earlier methods (execution order matters)

For full isolation between test scenarios, use separate test files.

## Test File Conventions

- Test files go in `tests/` directory, organized by feature (e.g., `tests/Mini/`, `tests/I18n/`)
- Files starting with `_`, `debug_`, or `benchmark-` are skipped
- The file `assert.php` is skipped (it's a helper, not a test)
- Each test file runs in a separate PHP process
- Output to stdout is captured; output to stderr appears on failure

## Example Test Structure

```
tests/
├── README.md
├── assert.php           # Procedural assertion helpers
├── Mini/                # Mini kernel tests
│   ├── container.php
│   ├── set.php
│   └── scoped-lifecycle.php
├── I18n/                # Internationalization tests
│   ├── Fmt.php
│   ├── Translator.php
│   ├── Translatable.php
│   └── _translations/   # Test fixtures (skipped)
│       ├── default/
│       ├── de/
│       └── nb/
└── _fixtures/           # Other fixtures (skipped)
```

## Testing with Mock Services

Use `Mini::$mini->set()` in `setUp()` to inject mock services:

```php
protected function setUp(): void
{
    // Create mock PDO
    $mockPdo = $this->createMock(\PDO::class);
    Mini::$mini->set(\PDO::class, $mockPdo);

    // Create custom translator with test translations
    $paths = new PathsRegistry(__DIR__ . '/_translations');
    $translator = new Translator($paths, autoCreateDefaults: false);
    Mini::$mini->set(TranslatorInterface::class, $translator);

    \mini\bootstrap();
}
```

Note: `set()` must be called before `get()` retrieves the service, otherwise it throws an exception to prevent shadowing already-instantiated services.
