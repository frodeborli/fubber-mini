# CLI - Command Line Interface

For simple scripts, use `$_SERVER['argv']` directly. Use `args()` when you need structured option parsing or subcommands.

## Quick Start

```php
#!/usr/bin/env php
<?php
require_once __DIR__ . '/../vendor/autoload.php';
use function mini\args;

args(args()->withFlag('v', 'verbose')->withRequiredValue('o', 'output'));

if (args()->getUnparsedArgs()) {
    fwrite(STDERR, "Unexpected: " . implode(', ', args()->getUnparsedArgs()) . "\n");
    exit(1);
}

if (args()->getFlag('verbose')) {
    echo "Verbose mode\n";
}
$output = args()->getOption('output');
```

## Declaring Options

```php
args(args()
    ->withFlag('v', 'verbose')                         // -v or --verbose (counting)
    ->withFlag('h', null)                              // -h only
    ->withFlag(null, 'dry-run')                        // --dry-run only
    ->withRequiredValue('c', 'config')                 // -c file or --config=file
    ->withOptionalValue('l', 'log')                    // --log or --log=file
    ->withOptionalValue('o', 'out', '/dev/stdout')    // default when present without value
    ->withSubcommand('run', 'build', 'test')           // declared subcommands
);
```

## Querying Values

| Method | Returns | Description |
|--------|---------|-------------|
| `getFlag('name')` | `int` | Count of times flag appeared (0 if absent) |
| `getOption('name')` | `string\|false\|array\|null` | Option value (see below) |
| `hasOption('name')` | `bool` | True if option was present |
| `getUnparsedArgs()` | `string[]` | Arguments not matched by declared options/subcommands |
| `getCommand()` | `string\|null` | Current command name |
| `nextCommand()` | `ArgManager\|null` | ArgManager for matched subcommand |
| `getRemainingArgs()` | `string[]` | Remaining argv for delegation (strips leading `--`) |

`getOption()` return values:

| CLI input | getOption() result |
|-----------|-------------------|
| *(not present)* | `null` |
| `--log` | `false` (or default if specified) |
| `--log=file` | `"file"` |
| `--log=a --log=b` | `["a", "b"]` |

Flags can be repeated for counting (short and long forms are combined):
```bash
myapp -vvv              # getFlag('verbose') returns 3
myapp -v -v --verbose   # same result
```

Options with values accept multiple formats:
```bash
myapp -cfile.txt        # attached
myapp -c file.txt       # separate
myapp --config=file.txt # long with =
myapp --config file.txt # long separate
```

## Subcommands

```php
#!/usr/bin/env php
<?php
// bin/myapp - Usage: myapp [-v] <command> [options]
use function mini\args;

args(args()
    ->withFlag('v', 'verbose')
    ->withSubcommand('run', 'build')
);

if (args()->getUnparsedArgs()) {
    die("Unknown: " . implode(', ', args()->getUnparsedArgs()));
}

if ($sub = args()->nextCommand()) {
    args($sub);
    match (args()->getCommand()) {
        'run' => require __DIR__ . '/commands/run.php',
        'build' => require __DIR__ . '/commands/build.php',
    };
}
```

Each subcommand file uses the same pattern:
```php
<?php
// commands/run.php
use function mini\args;

args(args()->withFlag(null, 'fast'));

if (args()->getUnparsedArgs()) {
    die("Unexpected: " . implode(', ', args()->getUnparsedArgs()));
}

echo args()->getFlag('fast') ? "Fast mode\n" : "Normal mode\n";
```

## Double-Dash (--)

Everything after `--` goes to `getUnparsedArgs()`:
```bash
myapp -v -- --not-an-option file.txt
```
```php
args()->getUnparsedArgs();  // ['--not-an-option', 'file.txt']
```

## Delegating to External Commands

```php
args(args()->withSubcommand('proxy'));

if ($sub = args()->nextCommand()) {
    args($sub);
    $remaining = args()->getRemainingArgs();  // strips leading -- automatically
    passthru('external-tool ' . implode(' ', array_map('escapeshellarg', $remaining)));
}
```

## Unit Testing

For tests, create ArgManager directly instead of using the global `args()`:

```php
$_SERVER['argv'] = ['myapp', '-v', '--config=test.cfg'];
$args = (new ArgManager())->withFlag('v', 'verbose')->withRequiredValue('c', 'config');
$this->assertSame(1, $args->getFlag('verbose'));
```
