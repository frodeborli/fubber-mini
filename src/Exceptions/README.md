# Exceptions - Framework Exception Classes

This namespace contains exception classes thrown by the Mini framework.

## Purpose

Mini uses specific exception types to indicate different failure modes:

- **Container exceptions** - Service container and dependency injection failures (PSR-11 compliant)
- **Configuration exceptions** - Missing or invalid configuration
- **Dependency exceptions** - Missing Composer packages

All exceptions extend standard PHP exception classes, so you can catch them using familiar patterns.

## Exception Handling

Mini uses standard PHP exception handling. You can catch specific exceptions when you know how to handle them, or use generic `\Exception` or `\Throwable`:

```php
try {
    $service = Mini::$mini->get(PaymentGateway::class);
} catch (\mini\Exceptions\NotFoundException $e) {
    // Graceful degradation: use fallback
    $service = new OfflinePaymentGateway();
}
```

## Best Practices

1. **Catch specific exceptions** when you have a recovery strategy
2. **Let exceptions bubble** if you don't know how to handle them
3. **Log before re-throwing** to maintain visibility
4. **Provide context** when throwing exceptions in your code

```php
try {
    processOrder($order);
} catch (\Throwable $e) {
    log()->error('Order processing failed', [
        'order_id' => $order->id,
        'exception' => $e
    ]);
    throw $e;  // Re-throw for higher-level handling
}
```

The framework's error handler will catch uncaught exceptions and display appropriate error pages based on `Mini::$mini->debug` mode.
