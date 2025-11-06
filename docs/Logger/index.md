# Logger - PSR-3 Logging

Write logs with `mini\log()` using PSR-3 LoggerInterface.

## Basic Usage

```php
<?php
use Psr\Log\LogLevel;

mini\log()->info('User logged in', ['user_id' => 123]);
mini\log()->warning('Slow query detected', ['duration' => 2.5]);
mini\log()->error('Payment failed', ['order_id' => 456]);
```

## Log Levels

```php
mini\log()->emergency('System is unusable');
mini\log()->alert('Action must be taken immediately');
mini\log()->critical('Critical conditions');
mini\log()->error('Error conditions');
mini\log()->warning('Warning conditions');
mini\log()->notice('Normal but significant');
mini\log()->info('Informational messages');
mini\log()->debug('Debug-level messages');
```

## Context Interpolation

```php
// PSR-3 MessageFormat with placeholders
mini\log()->info('User {username} logged in from {ip}', [
    'username' => 'john',
    'ip' => '192.168.1.1'
]);
```

## Configuration

Override default logger via `_config/Psr/Log/LoggerInterface.php`:

```php
<?php
return new MyCustomLogger();
```

## Default Behavior

By default, Mini logs to PHP's `error_log()` with timestamp and context.

## API Reference

See `Psr\Log\LoggerInterface` for full PSR-3 specification.
