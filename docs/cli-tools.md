# Building CLI Tools with Mini

This tutorial shows how to build robust command-line tools using Mini's CLI features.

## Quick Start

A minimal CLI script:

```php
#!/usr/bin/env php
<?php
require_once __DIR__ . '/../vendor/autoload.php';
use function mini\args;

args(args()
    ->withFlag('v', 'verbose')
    ->withRequiredValue('o', 'output')
);

if (args()->getUnparsedArgs()) {
    fwrite(STDERR, "Unknown: " . implode(', ', args()->getUnparsedArgs()) . "\n");
    exit(1);
}

if (args()->getFlag('verbose')) {
    echo "Verbose mode enabled\n";
}

$output = args()->getOption('output');
echo "Output file: " . ($output ?: 'none') . "\n";
```

Make it executable: `chmod +x bin/mytool`

## Project Structure

For CLI projects, a clean structure separates concerns:

```
myproject/
├── bin/                 # Executable scripts
│   ├── main-command     # Primary entry point
│   └── sub-command      # Additional commands
├── src/                 # PHP classes (PSR-4 autoloaded)
│   └── MyService.php
├── bootstrap.php        # Global setup, helper functions
├── composer.json
└── data/               # Runtime data (state, config, etc.)
```

### Composer Setup

```json
{
    "name": "vendor/myproject",
    "require": {
        "php": "^8.2",
        "fubber/mini": "dev-main"
    },
    "autoload": {
        "psr-4": {
            "MyProject\\": "src/"
        },
        "files": ["bootstrap.php"]
    },
    "minimum-stability": "dev",
    "prefer-stable": true
}
```

### Bootstrap Pattern

The `bootstrap.php` file runs on every autoload. Use it for:
- Constants and paths
- Singleton accessor functions
- Configuration loader

```php
<?php
declare(strict_types=1);

use MyProject\StateManager;
use MyProject\AlertWriter;

define('PROJECT_ROOT', __DIR__);
define('PROJECT_DATA', PROJECT_ROOT . '/data');

// Singleton accessors using static variables
function state_manager(): StateManager
{
    static $instance = null;
    return $instance ??= new StateManager(PROJECT_DATA . '/state.json');
}

function alert_writer(): AlertWriter
{
    static $instance = null;
    return $instance ??= new AlertWriter(PROJECT_DATA . '/alerts');
}

// Configuration loader
function config(): array
{
    static $config = null;
    if ($config === null) {
        $path = PROJECT_DATA . '/config.json';
        $config = file_exists($path) ? json_decode(file_get_contents($path), true) : [];
    }
    return $config;
}
```

### Configuring the Logger

CLI tools typically output to stderr by default, with optional file logging. Configure
this after parsing arguments in your entry point:

```php
use mini\Mini;
use mini\Lifetime;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Configure logger based on verbosity and optional log file
 *
 * @param int $verbosity 0=errors only, 1=warnings, 2=info, 3=debug
 * @param string|null $logFile Optional file path for logging
 */
function configure_logger(int $verbosity, ?string $logFile = null): void
{
    Mini::$mini->addService(LoggerInterface::class, Lifetime::Singleton,
        function() use ($verbosity, $logFile) {
            return new class($verbosity, $logFile) implements LoggerInterface {
                use \Psr\Log\LoggerTrait;

                private const LEVELS = [
                    LogLevel::EMERGENCY => 0,
                    LogLevel::ALERT     => 0,
                    LogLevel::CRITICAL  => 0,
                    LogLevel::ERROR     => 0,
                    LogLevel::WARNING   => 1,
                    LogLevel::NOTICE    => 2,
                    LogLevel::INFO      => 2,
                    LogLevel::DEBUG     => 3,
                ];

                public function __construct(
                    private int $verbosity,
                    private ?string $logFile
                ) {}

                public function log($level, string|\Stringable $message, array $context = []): void
                {
                    $ts = date('Y-m-d H:i:s');
                    $levelStr = strtoupper((string) $level);
                    $line = "[$ts] [$levelStr] $message\n";

                    // Always write to log file if configured
                    if ($this->logFile !== null) {
                        file_put_contents($this->logFile, $line, FILE_APPEND);
                    }

                    // Write to stderr based on verbosity
                    $requiredVerbosity = self::LEVELS[$level] ?? 0;
                    if ($this->verbosity >= $requiredVerbosity) {
                        fwrite(STDERR, $line);
                    }
                }
            };
        }
    );
}
```

## Argument Parsing

### Declaring Options

```php
use function mini\args;

args(args()
    ->withFlag('v', 'verbose')                    // -v or --verbose (countable)
    ->withFlag('h', null)                         // -h only
    ->withFlag(null, 'dry-run')                   // --dry-run only
    ->withRequiredValue('c', 'config')            // -c FILE or --config=FILE
    ->withOptionalValue('l', 'log')               // --log or --log=FILE
    ->withOptionalValue('o', 'out', '/dev/stdout') // with default
    ->withSubcommand('run', 'build', 'test')      // declared subcommands
);
```

### Reading Values

```php
// Flags return count (0 if not present)
$verbose = args()->getFlag('verbose');      // 0, 1, 2, etc.
$veryVerbose = args()->getFlag('verbose') >= 2;

// Options return string|array|false|null
$config = args()->getOption('config');
// null = not present
// false = --config without value (if optional)
// "value" = --config=value
// ["a", "b"] = --config=a --config=b

// Check presence
if (args()->hasOption('config')) {
    // --config was passed (with or without value)
}

// Unparsed args (positional or unknown)
$files = args()->getUnparsedArgs();  // ['file1.txt', 'file2.txt']
```

### Error Handling

Always check for unexpected arguments:

```php
if (args()->getUnparsedArgs()) {
    fwrite(STDERR, "Unexpected: " . implode(', ', args()->getUnparsedArgs()) . "\n");
    exit(1);
}
```

## Subcommands

ArgManager handles subcommands by stopping at the first declared subcommand it encounters.
The pattern is recursive: each level declares its own flags/options and valid subcommands.

**How it works:**
1. Configure the root ArgManager with global flags and declare valid subcommands
2. Check `getUnparsedArgs()` - any unknown arguments indicate a syntax error
3. Call `nextCommand()` to get a fresh ArgManager positioned at the subcommand
4. Configure the new ArgManager with subcommand-specific options
5. Repeat check for unparsed args

### Main Command

```php
#!/usr/bin/env php
<?php
// bin/myapp
require_once __DIR__ . '/../vendor/autoload.php';
use function mini\args;

// Step 1: Configure root with global flags and valid subcommands
args(args()
    ->withFlag('v', 'verbose')
    ->withOptionalValue('l', 'log')
    ->withSubcommand('check', 'list', 'dismiss')
);

// Step 2: Check for unknown options/arguments at this level
if (args()->getUnparsedArgs()) {
    fwrite(STDERR, "Unknown: " . implode(', ', args()->getUnparsedArgs()) . "\n");
    exit(1);
}

// Extract global options before handing off
$verbosity = args()->getFlag('verbose');
$logFile = args()->getOption('log') ?: null;
configure_logger($verbosity, $logFile);

// Step 3: Get subcommand and hand off
if ($sub = args()->nextCommand()) {
    args($sub);  // Replace global args() with subcommand context
    match (args()->getCommand()) {
        'check' => require __DIR__ . '/commands/check.php',
        'list' => require __DIR__ . '/commands/list.php',
        'dismiss' => require __DIR__ . '/commands/dismiss.php',
    };
} else {
    echo "Usage: myapp [-v] [--log=FILE] <check|list|dismiss> [options]\n";
}
```

### Subcommand File

The subcommand file receives a fresh ArgManager context. It declares its own options
and validates them independently:

```php
<?php
// bin/commands/check.php
use function mini\args;

// Step 4: Configure subcommand-specific options
args(args()
    ->withFlag(null, 'dry-run')
    ->withRequiredValue('r', 'repo')
);

// Step 5: Check for unknown options at subcommand level
if (args()->getUnparsedArgs()) {
    fwrite(STDERR, "Unknown: " . implode(', ', args()->getUnparsedArgs()) . "\n");
    exit(1);
}

$dryRun = args()->getFlag('dry-run') > 0;
$repo = args()->getOption('repo');

if (!$repo) {
    fwrite(STDERR, "Error: --repo is required\n");
    exit(1);
}

\mini\log()->info("Checking repo: {repo}", ['repo' => $repo]);
echo "Checking repo: $repo\n";
if ($dryRun) {
    echo "[dry-run mode]\n";
}
```

**Example invocations:**
```bash
myapp check --repo=foo          # verbosity=0, no log file
myapp -v check --repo=foo       # verbosity=1, warnings to stderr
myapp -vv check --repo=foo      # verbosity=2, info to stderr
myapp -vvv --log=app.log check  # verbosity=3, debug to stderr + file
```

## Output Formatting

### TTY Table Output

Mini includes a TTY class for formatted output:

```php
use mini\CLI\TTY;

$data = [
    ['name' => 'Alice', 'score' => 95, 'grade' => 'A'],
    ['name' => 'Bob', 'score' => 87, 'grade' => 'B'],
    ['name' => 'Carol', 'score' => 92, 'grade' => 'A'],
];

// Automatic table formatting
echo TTY::table($data);

// JSON output
echo TTY::json($data, pretty: true);

// CSV output
echo TTY::csv($data);
```

### ANSI Colors

For simple coloring without dependencies:

```php
$red = "\033[31m";
$green = "\033[32m";
$yellow = "\033[33m";
$reset = "\033[0m";

echo "{$red}Error:{$reset} Something went wrong\n";
echo "{$green}Success:{$reset} Operation completed\n";
echo "{$yellow}[warning]{$reset} Check your config\n";
```

## Complete Example

Here's a monitoring tool that demonstrates verbosity levels and optional file logging:

```php
#!/usr/bin/env php
<?php
/**
 * Repository Monitor
 *
 * Usage: ./bin/monitor [-v|-vv|-vvv] [--log=FILE] [--dry-run] [--repo=OWNER/REPO]
 *
 * Verbosity:
 *   (none)  Show errors only
 *   -v      Also show warnings
 *   -vv     Also show info messages
 *   -vvv    Also show debug output
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
use function mini\args;

// Parse arguments
args(args()
    ->withFlag('v', 'verbose')
    ->withFlag(null, 'dry-run')
    ->withOptionalValue('l', 'log')
    ->withOptionalValue('r', 'repo')
);

if (args()->getUnparsedArgs()) {
    fwrite(STDERR, "Unknown: " . implode(', ', args()->getUnparsedArgs()) . "\n");
    exit(1);
}

// Extract options
$verbosity = args()->getFlag('verbose');  // 0, 1, 2, or 3
$dryRun = args()->getFlag('dry-run') > 0;
$logFile = args()->getOption('log') ?: null;
$targetRepo = args()->getOption('repo') ?: null;

// Configure logger based on verbosity
configure_logger($verbosity, $logFile);

// Log start - only visible at verbosity >= 2
\mini\log()->info('Monitor started' . ($dryRun ? ' (dry-run)' : ''));

// Get configured repos
$repos = config()['repos'] ?? [];

if (empty($repos)) {
    \mini\log()->error("No repos configured");
    fwrite(STDERR, "No repos configured in data/config.json\n");
    exit(1);
}

// Filter to specific repo if requested
if ($targetRepo) {
    $repos = array_filter($repos, fn($r) => $r['name'] === $targetRepo);
    if (empty($repos)) {
        \mini\log()->error("Repo not found: {repo}", ['repo' => $targetRepo]);
        fwrite(STDERR, "Repo not found: $targetRepo\n");
        exit(1);
    }
}

$total = 0;
$alerts = 0;

foreach ($repos as $repo) {
    \mini\log()->debug("Processing repo: {name}", ['name' => $repo['name']]);
    echo "Checking {$repo['name']}...\n";

    // ... check logic here ...
    $found = 5;
    $needsAttention = 2;

    \mini\log()->info("Found {found} items, {attention} need attention", [
        'found' => $found,
        'attention' => $needsAttention
    ]);

    if ($needsAttention > 0) {
        \mini\log()->warning("Repo {name} has items needing attention", [
            'name' => $repo['name']
        ]);
    }

    if (!$dryRun) {
        // ... create alerts, update state ...
        \mini\log()->debug("Created alerts for {name}", ['name' => $repo['name']]);
    }

    $total += $found;
    $alerts += $needsAttention;
}

echo "\nDone. Checked $total items, created $alerts alerts.\n";
\mini\log()->info("Monitor complete: {total} checked, {alerts} alerts", [
    'total' => $total,
    'alerts' => $alerts
]);
```

**Example output at different verbosity levels:**

```bash
# Silent except errors
$ ./bin/monitor
Checking repo-a...
Checking repo-b...
Done. Checked 10 items, created 4 alerts.

# With warnings (-v)
$ ./bin/monitor -v
Checking repo-a...
[2025-01-15 10:30:00] [WARNING] Repo repo-a has items needing attention
Checking repo-b...
[2025-01-15 10:30:00] [WARNING] Repo repo-b has items needing attention
Done. Checked 10 items, created 4 alerts.

# With info (-vv) and log file
$ ./bin/monitor -vv --log=monitor.log
[2025-01-15 10:30:00] [INFO] Monitor started
Checking repo-a...
[2025-01-15 10:30:00] [INFO] Found 5 items, 2 need attention
[2025-01-15 10:30:00] [WARNING] Repo repo-a has items needing attention
...
```

## Best Practices

1. **Always validate unparsed args** - Catch typos and unknown options early. An unknown option is a syntax error.

2. **Use dry-run for destructive operations** - Let users preview what will happen.

3. **Support verbosity levels** - Use `-v`/`-vv`/`-vvv` to control log output to stderr. Errors always show; warnings at `-v`; info at `-vv`; debug at `-vvv`.

4. **Make file logging opt-in** - Use `--log=FILE` for persistent logs. By default, CLI tools should only output to stderr.

5. **Exit with proper codes** - `exit(0)` for success, `exit(1)` for errors.

6. **Write to stderr for errors and logs** - Keep stdout clean for data output that can be piped.

7. **Use `mini\log()` with levels** - `error()` for failures, `warning()` for issues, `info()` for operations, `debug()` for troubleshooting. The verbosity flag controls which levels appear on stderr.

8. **Use singleton accessors** - The static variable pattern avoids global state while maintaining single instances.

9. **Keep bin scripts thin** - Put logic in src/ classes, keep bin/ as glue code.
