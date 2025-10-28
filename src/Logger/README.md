# Mini Logger

PSR-3 compatible logging system for the Mini framework. Provides a simple, standardized way to log messages with different severity levels.

## Features

- **PSR-3 Compatible** - Implements the standard PHP logging interface
- **Built-in Logger** - Logs to PHP's error_log by default
- **Configurable** - Override with any PSR-3 compatible logger
- **MessageFormatter Integration** - Uses ICU MessageFormatter for context interpolation
- **Application Locale** - Uses app default locale, not per-user locale
- **Exception Support** - Automatically formats exceptions in context

## Basic Usage

```php
use function mini\log;

// Basic logging
log()->info("User logged in");
log()->warning("Cache miss for key: users");
log()->error("Failed to connect to database");

// With context variables
log()->info("User {username} logged in from {ip}", [
    'username' => 'john',
    'ip' => '192.168.1.1'
]);

// With exceptions
try {
    // Some code that throws
} catch (\Exception $e) {
    log()->error("Operation failed: {message}", [
        'message' => $e->getMessage(),
        'exception' => $e  // Automatically formats stack trace
    ]);
}
```

## Log Levels (PSR-3)

```php
log()->emergency($message, $context);  // System is unusable
log()->alert($message, $context);      // Action must be taken immediately
log()->critical($message, $context);   // Critical conditions
log()->error($message, $context);      // Runtime errors
log()->warning($message, $context);    // Warning messages
log()->notice($message, $context);     // Normal but significant
log()->info($message, $context);       // Informational messages
log()->debug($message, $context);      // Debug-level messages
```

## Context Interpolation

The logger uses ICU MessageFormatter with the application's default locale for context interpolation:

```php
// Simple placeholders
log()->info("Processing {count} items", ['count' => 42]);
// Output: [2025-10-27 13:38:53] [INFO] Processing 42 items

// Multiple variables
log()->info("User {user} performed {action} on {resource}", [
    'user' => 'admin',
    'action' => 'delete',
    'resource' => 'database'
]);

// Arrays are JSON-encoded
log()->info("User data: {user}", [
    'user' => ['id' => 123, 'name' => 'John']
]);
// Output: [2025-10-27 13:38:53] [INFO] User data: {"id":123,"name":"John"}
```

## Exception Logging

The `exception` key in context has special handling:

```php
try {
    throw new \RuntimeException("Something went wrong");
} catch (\Exception $e) {
    log()->error("Error occurred", ['exception' => $e]);
}

// Output:
// [2025-10-27 13:38:53] [ERROR] Error occurred
// Exception: RuntimeException
// Message: Something went wrong
// File: /path/to/file.php:42
// Trace:
// #0 /path/to/file.php(42): functionName()
// #1 {main}
```

## Custom Logger Configuration

Override the default logger by creating `config/logger.php`:

```php
<?php
// config/logger.php

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\FirePHPHandler;

// Example: Monolog logger with multiple handlers
$logger = new Logger('mini');
$logger->pushHandler(new StreamHandler(__DIR__ . '/../storage/logs/app.log', Logger::DEBUG));
$logger->pushHandler(new FirePHPHandler());

return $logger;
```

Any PSR-3 compatible logger works:

```php
<?php
// config/logger.php

// Example: Custom logger implementation
class DatabaseLogger implements \Psr\Log\LoggerInterface
{
    use \Psr\Log\LoggerTrait;

    public function log($level, $message, array $context = []): void
    {
        // Log to database
        db()->insert('logs', [
            'level' => $level,
            'message' => $message,
            'context' => json_encode($context),
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }
}

return new DatabaseLogger();
```

## Built-in Logger Format

Default format: `[timestamp] [LEVEL] message`

```
[2025-10-27 13:38:53] [INFO] User john logged in
[2025-10-27 13:38:53] [WARNING] Cache miss
[2025-10-27 13:38:53] [ERROR] Database connection failed
```

## Best Practices

### 1. Use Appropriate Log Levels

```php
// Emergency - System is down, immediate attention needed
log()->emergency("Database server is unreachable");

// Error - Something failed but app can continue
log()->error("Payment processing failed for order {id}", ['id' => $orderId]);

// Warning - Something unexpected but not an error
log()->warning("API rate limit approaching threshold");

// Info - Normal operational messages
log()->info("User {username} logged in", ['username' => $user]);

// Debug - Detailed information for debugging
log()->debug("SQL Query: {query}", ['query' => $sql]);
```

### 2. Include Relevant Context

```php
// Good: Includes actionable context
log()->error("Failed to send email to {recipient}", [
    'recipient' => $email,
    'smtp_error' => $error,
    'attempt' => $retryCount
]);

// Bad: Missing context
log()->error("Failed to send email");
```

### 3. Log Exceptions Properly

```php
// Good: Includes exception in context
try {
    // ...
} catch (\Exception $e) {
    log()->error("Payment processing failed: {message}", [
        'message' => $e->getMessage(),
        'exception' => $e,  // Includes full stack trace
        'order_id' => $orderId
    ]);
}

// Avoid: Losing stack trace
catch (\Exception $e) {
    log()->error("Payment failed: " . $e->getMessage());  // No stack trace
}
```

### 4. Don't Log Sensitive Data

```php
// Bad: Logs password
log()->info("User login", ['username' => $user, 'password' => $pass]);

// Good: Omit sensitive data
log()->info("User {username} logged in", ['username' => $user]);
```

### 5. Use Lazy Evaluation for Debug Logs

```php
// If expensive to compute, check log level first (if using custom logger)
if ($logger->isDebugEnabled()) {
    log()->debug("Complex data: {data}", ['data' => expensiveOperation()]);
}
```

## Integration with Other Loggers

The mini logger is PSR-3 compatible, so it works with popular logging libraries:

- **Monolog** - Full-featured logging library
- **KLogger** - Simple file-based logger
- **Analog** - Lightweight logger
- **Any PSR-3 logger** - Just return it from config/logger.php

## Architecture

```
mini\Logger\
├── Logger.php           # Built-in PSR-3 logger implementation
├── functions.php        # Global log() function
└── README.md           # This file
```

The logger is lazily initialized on first use via the `mini\log()` function.

## License

MIT License - see [LICENSE](../../LICENSE)
