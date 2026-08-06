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

## No Network in the Default Suite

`mini test` must be deterministic and must pass **offline**. No test may contact
the public internet — not for fixtures, not for "just a quick ping".

When a test needs a real socket (the PSR-18 `HttpClient`, for example), start a
local fixture server on a free loopback port instead:

- Put the server script next to the test with a leading `_` (e.g.
  `tests/Http/Client/_fixture-server.php`) so the runner skips it.
- Spawn it with `proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", …])`, where
  `$port` comes from binding `tcp://127.0.0.1:0` and reading back the assigned
  port.
- Poll a real readiness endpoint — `GET /ping` must answer `200` with the body
  `pong` — not a bare `fsockopen()` connect. Accepting a connection only proves
  that `php -S` is listening; it says nothing about the fixture script, so a
  broken fixture would pass a connect probe and then surface as a pile of
  unrelated assertion failures instead of one clear startup error.
- Give the child `['file', …]` descriptors, never pipes you don't read: point
  stderr at a temp file and append it to the startup failure message, so the
  fixture's own PHP errors reach the developer. An unread pipe both discards
  that diagnostic and can fill its 64 KiB buffer and stall the server.
- Tear it down from a `register_shutdown_function()` handler, so the server dies
  even when a test fails or the process aborts with a fatal error.

`tests/Http/Client/HttpClient.php` is the reference implementation of this
pattern. Unreachable-host behaviour (connection refused, timeouts) is tested
against a closed loopback port and a slow fixture route — never against a
domain name, which would require DNS.

If an external service genuinely cannot be replaced by a fixture, the test must
skip cleanly rather than fail: override `canRun()` / `skipReason()` from
`mini\Test`, so the run reports it as skipped and self-documents *why*. Any env
flag that gates such a test must be documented here. **There are currently no
such flags — the entire suite runs offline with no configuration.**

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

## SQL Logic Test Suite

Mini includes integration with the [SQLLogicTest](https://www.sqlite.org/sqllogictest/doc/trunk/about.wiki) suite for verifying VirtualDatabase SQL compliance. This suite contains ~11,000 queries that test SQL behavior against SQLite as a reference.

### Quick Tests

The standard test suite (`mini test`) includes basic SQL Logic Tests that run without external dependencies:

```bash
vendor/bin/mini test tests/Database/SqlLogicTestSuite.php
```

This runs:
- Built-in tests for DDL, DML, JOINs, aggregates, and subqueries
- A sample from the bundled test file
- Extended tests if the full test data is installed

### Full Test Suite

For comprehensive SQL compliance testing, install the full test data:

```bash
cd tests
git clone https://github.com/dolthub/sqllogictest sqllogictest-data
```

Then run the full suite with the dedicated CLI tool:

```bash
# Run all tests (~11k queries, takes ~1 minute)
bin/sql-logic-test

# Run specific test files
bin/sql-logic-test select1              # Just select1.test
bin/sql-logic-test evidence             # All evidence/ tests

# Debug failures
bin/sql-logic-test --stop-on-error      # Stop on first failure
bin/sql-logic-test --print-errors       # Show exception details
```

### Current Compliance

VirtualDatabase passes ~93% of applicable SQLLogicTest queries. Known limitations:
- Queries with 9+ joined tables are rejected (complexity cap)
- Some edge cases in UPDATE/DELETE with complex conditions

### Test Data Structure

```
tests/
├── sqllogictest/              # Bundled test files (always available)
│   └── slt_good_0.test        # Sample test file
└── sqllogictest-data/         # External test data (git clone)
    └── test/
        ├── select1.test       # Core SELECT tests
        ├── select2.test
        ├── select3.test
        ├── select4.test
        ├── select5.test       # Complex multi-table queries
        └── evidence/          # SQL standard compliance tests
            ├── in1.test
            ├── in2.test
            └── slt_lang_*.test
```
