# CLI - Command Line Interface

## Philosophy

Mini provides **optional argument parsing** that respects `$_SERVER['argv']`. We don't hide PHP's native CLI toolsArgManager is a convenience layer for when you need structured argument parsing. For simple scripts, use `$_SERVER['argv']` directly.

**Key Principles:**
- **Optional convenience** - Use `args()` when helpful, `$_SERVER['argv']` when simple
- **Subcommand support** - Parse multi-level commands (e.g., `git commit -m "msg"`)
- **getopt-style syntax** - Short (`-v`) and long (`--verbose`) options
- **Immutable design** - Returns new instances, doesn't modify globals
- **Position tracking** - Clean delegation to external commands

## Setup

No configuration needed! ArgManager is automatically registered:

```php
#!/usr/bin/env php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use function mini\args;

$args = args();
echo "Command: " . $args->getCommand() . "\n";
```

### Custom ArgManager Configuration

```php
<?php
// _config/mini/CLI/ArgManager.php

return (new mini\CLI\ArgManager(0))
    ->withSupportedArgs('v', ['verbose', 'config:', 'help'], 0);
```

## Common Usage Examples

### Basic Argument Parsing

```php
#!/usr/bin/env php
<?php
// bin/greet
// Usage: bin/greet Alice Bob --greeting="Hello"

require_once __DIR__ . '/../vendor/autoload.php';

$args = args()
    ->withSupportedArgs('', ['greeting:'], 1);  // At least 1 positional arg

$greeting = $args->opts['greeting'] ?? 'Hi';

foreach ($args->args as $name) {
    echo "$greeting, $name!\n";
}
```

```bash
$ bin/greet Alice Bob --greeting="Hello"
Hello, Alice!
Hello, Bob!
```

### Short and Long Options

```php
#!/usr/bin/env php
<?php
// bin/example
// Usage: bin/example -v --config=file.ini

$args = args()
    ->withSupportedArgs('v', ['verbose', 'config:', 'help']);

if (isset($args->opts['v']) || isset($args->opts['verbose'])) {
    echo "Verbose mode enabled\n";
}

if (isset($args->opts['config'])) {
    echo "Config: " . $args->opts['config'] . "\n";
}

if (isset($args->opts['help'])) {
    echo "Usage: bin/example [options]\n";
    exit(0);
}
```

### Repeated Options

```php
#!/usr/bin/env php
<?php
// bin/verbose
// Usage: bin/verbose -vvv

$args = args()
    ->withSupportedArgs('v');

$verbosity = is_array($args->opts['v'] ?? null)
    ? count($args->opts['v'])
    : (isset($args->opts['v']) ? 1 : 0);

echo "Verbosity level: $verbosity\n";
```

```bash
$ bin/verbose -vvv
Verbosity level: 3
```

### Subcommands

```php
#!/usr/bin/env php
<?php
// bin/app
// Usage: bin/app user create --name="John"
// Usage: bin/app user list

$root = args();

match ($root->getCommand()) {
    'user' => handleUserCommand($root->nextCommand()),
    'post' => handlePostCommand($root->nextCommand()),
    default => die("Unknown command\n")
};

function handleUserCommand($cmd) {
    match ($cmd->getCommand()) {
        'create' => createUser($cmd),
        'list' => listUsers($cmd),
        default => die("Unknown user command\n")
    };
}

function createUser($cmd) {
    $args = $cmd->withSupportedArgs('', ['name:', 'email:']);

    echo "Creating user: " . $args->opts['name'] . "\n";
    echo "Email: " . $args->opts['email'] . "\n";
}

function listUsers($cmd) {
    $args = $cmd->withSupportedArgs('', ['limit::']);

    $limit = $args->opts['limit'] ?? 10;
    echo "Listing $limit users\n";
}
```

```bash
$ bin/app user create --name="John" --email="john@example.com"
Creating user: John
Email: john@example.com

$ bin/app user list --limit=5
Listing 5 users
```

## Advanced Examples

### Required and Optional Arguments

```php
#!/usr/bin/env php
<?php
// bin/deploy
// Usage: bin/deploy production [branch]

$args = args()
    ->withSupportedArgs(
        '',           // No short options
        ['dry-run'],  // Long options
        1,            // 1 required positional arg (environment)
        1             // 1 optional arg (branch)
    );

$environment = $args->args[0];
$branch = $args->args[1] ?? 'main';
$dryRun = isset($args->opts['dry-run']);

echo "Deploying $branch to $environment" . ($dryRun ? " (dry run)" : "") . "\n";
```

```bash
$ bin/deploy production
Deploying main to production

$ bin/deploy staging feature-123 --dry-run
Deploying feature-123 to staging (dry run)
```

### Mixed Short and Long Options

```php
#!/usr/bin/env php
<?php
// bin/search
// Usage: bin/search query -i --match=pattern

$args = args()
    ->withSupportedArgs(
        'ivwx',                      // Short options
        ['match:', 'limit::'],       // Long options
        1                             // 1 required arg (query)
    );

$query = $args->args[0];
$caseInsensitive = isset($args->opts['i']);
$pattern = $args->opts['match'] ?? null;

echo "Searching for: $query\n";
if ($caseInsensitive) echo "Case insensitive\n";
if ($pattern) echo "Pattern: $pattern\n";
```

### Option Value Types

```php
#!/usr/bin/env php
<?php
// Demonstrate option types

$args = args()
    ->withSupportedArgs(
        'f:v::',                  // -f requires value, -v optional value
        ['file:', 'verbose::']    // --file requires, --verbose optional
    );

// Required value options
// -f value or --file=value or --file value
if (isset($args->opts['f'])) {
    echo "File (short): " . $args->opts['f'] . "\n";
}
if (isset($args->opts['file'])) {
    echo "File (long): " . $args->opts['file'] . "\n";
}

// Optional value options
// -v or -v value
// --verbose or --verbose=value or --verbose value
$verbose = $args->opts['v'] ?? $args->opts['verbose'] ?? null;
if ($verbose !== null) {
    echo "Verbose: " . ($verbose === false ? "on" : $verbose) . "\n";
}
```

### Using Raw $_SERVER['argv']

```php
#!/usr/bin/env php
<?php
// Simple script doesn't need ArgManager

if ($argc < 2) {
    die("Usage: {$argv[0]} <name>\n");
}

$name = $argv[1];
echo "Hello, $name!\n";
```

### Integration with Mini

```php
#!/usr/bin/env php
<?php
// bin/db-query
// Usage: bin/db-query "SELECT * FROM users WHERE id = ?" 123

require_once __DIR__ . '/../vendor/autoload.php';

$args = args()
    ->withSupportedArgs('', ['format:'], 1);  // SQL query required

$sql = $args->args[0];
$params = array_slice($args->args, 1);
$format = $args->opts['format'] ?? 'table';

$results = db()->query($sql, $params);

match ($format) {
    'json' => echo json_encode($results, JSON_PRETTY_PRINT) . "\n",
    'csv' => printCsv($results),
    default => printTable($results)
};
```

### Error Handling

```php
#!/usr/bin/env php
<?php

try {
    $args = args()
        ->withSupportedArgs('', ['config:'], 1);  // Config required

    $query = $args->args[0];
    $config = $args->opts['config'];

    // ... process
} catch (\RuntimeException $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Usage: {$_SERVER['argv'][0]} <query> --config=<file>\n");
    exit(1);
}
```

## Option Syntax

### Short Options

| Pattern | Meaning | Example |
|---------|---------|---------|
| `v` | Boolean flag | `-v` |
| `v:` | Required value | `-v value` |
| `v::` | Optional value | `-v` or `-v value` |

Combinations:
```php
-vvv         // v counted 3 times
-abc         // Three boolean flags
-f file.txt  // f with required value
```

### Long Options

| Pattern | Meaning | Example |
|---------|---------|---------|
| `verbose` | Boolean flag | `--verbose` |
| `file:` | Required value | `--file=path` or `--file path` |
| `limit::` | Optional value | `--limit` or `--limit=10` |

### Positional Arguments

```php
->withSupportedArgs(
    'v',          // Options
    ['verbose'],
    2,            // 2 required positional args
    1             // Plus 1 optional arg
)
```

## Configuration

**Config File:** `_config/mini/CLI/ArgManager.php` (optional)

**Environment Variables:** None - argument parsing is per-invocation

## Overriding the Service

```php
// _config/mini/CLI/ArgManager.php

// Pre-configure global options
return (new mini\CLI\ArgManager(0))
    ->withSupportedArgs('v', ['verbose', 'help', 'version']);
```

## CLI Scope

ArgManager is **Singleton** - one instance shared, but typically you create new instances via `nextCommand()` or `withSupportedArgs()` for each subcommand context.

## Best Practices

### 1. Use Raw $argv for Simple Scripts

```php
// Good: Simple script
if ($argc < 2) die("Usage: script <file>\n");
$file = $argv[1];

// Avoid: Over-engineering
$args = args()->withSupportedArgs('', [], 1);
$file = $args->args[0];
```

### 2. Parse Options Early

```php
// Good: Parse once at start
$args = args()->withSupportedArgs('v', ['config:']);
$verbose = isset($args->opts['v']);

// Avoid: Multiple parsing calls
$args1 = args()->withSupportedArgs('v');
$args2 = args()->withSupportedArgs('', ['config:']);
```

### 3. Provide Help Text

```php
if (isset($args->opts['help'])) {
    echo <<<HELP
Usage: bin/deploy [options] <environment> [branch]

Options:
  --dry-run    Simulate deployment without making changes
  --verbose    Show detailed output
  --help       Show this help message

Arguments:
  environment  Target environment (production, staging, dev)
  branch       Git branch to deploy (default: main)
HELP;
    exit(0);
}
```

### 4. Validate Arguments

```php
$args = args()->withSupportedArgs('', ['env:'], 1);

$validEnvs = ['production', 'staging', 'dev'];
if (!in_array($args->opts['env'], $validEnvs)) {
    die("Invalid environment. Must be: " . implode(', ', $validEnvs) . "\n");
}
```

### 5. Use Exit Codes

```php
try {
    $args = args()->withSupportedArgs('', [], 1);
    // ... process
    exit(0);  // Success
} catch (\Exception $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);  // Failure
}
```
