# CLI - Command Line Interface

Parse command-line arguments with `mini\args()` using the ArgManager class.

## Basic Usage

```php
#!/usr/bin/env php
<?php
require 'vendor/autoload.php';

$root = mini\args();
$cmd = $root->nextCommand();
$cmd = $cmd->withSupportedArgs('v', ['verbose', 'help'], 1);

if (isset($cmd->opts['help'])) {
    echo "Usage: myapp <command> [options]\n";
    exit;
}

$verbosity = isset($cmd->opts['v']) ?
    (is_array($cmd->opts['v']) ? count($cmd->opts['v']) : 1) : 0;

echo "Command: {$cmd->getCommand()}\n";
echo "Argument: {$cmd->args[0]}\n";
```

## Global Options

Configure root-level options via `_config/mini/CLI/ArgManager.php`:

```php
<?php
return (new mini\CLI\ArgManager(0))
    ->withSupportedArgs('v', ['verbose', 'config:', 'help'], 0);
```

Now all CLI scripts inherit these global options.

## Subcommands

```php
// argv: ['myapp', '-v', 'deploy', '--env', 'prod', 'web']
$root = mini\args();
$deploy = $root->nextCommand();
$deploy = $deploy->withSupportedArgs('', ['env:'], 1);

echo $deploy->opts['env'];  // "prod"
echo $deploy->args[0];      // "web"
```

## API Reference

See `mini\CLI\ArgManager` for full API documentation.
