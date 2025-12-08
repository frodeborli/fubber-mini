# Hooks - Event System

This namespace contains Mini's lightweight event/hook system for extending framework behavior at key lifecycle points.

## Purpose

The hooks system provides event dispatchers and a state machine for lifecycle management:

- **Phase State Machine** - Manages application lifecycle (Initializing → Bootstrap → Ready → Shutdown)
- **Event Dispatchers** - Custom events defined by features or your application
- **Phase Transition Hooks** - Subscribe to state changes (`onEnteringState`, `onEnteredState`, etc.)

## Phase Lifecycle Hooks

The recommended way to hook into the request lifecycle is via phase transitions:

```php
use mini\Mini;
use mini\Phase;

// Before Ready phase (before error handlers, output buffering)
Mini::$mini->phase->onEnteringState(Phase::Ready, function() {
    // Authentication, CORS headers, rate limiting
});

// After Ready phase entered (after bootstrap completes)
Mini::$mini->phase->onEnteredState(Phase::Ready, function() {
    // Output buffering, response processing
});
```

## Dispatcher Classes

### Event - Multi-fire, Multiple Listeners

Use when an event can happen multiple times and you want all listeners notified.

```php
use mini\Hooks\Event;

// Create a page view event
$onPageView = new Event('page-view');

// Subscribe listeners
$onPageView->listen(function(string $path, int $userId) {
    error_log("Page viewed: $path by user $userId");
});

$onPageView->listen(function(string $path, int $userId) {
    Analytics::track('page_view', ['path' => $path, 'user' => $userId]);
});

// One-time listener (auto-unsubscribes after first trigger)
$onPageView->once(function(string $path, int $userId) {
    echo "First page view recorded!";
});

// Trigger the event (all listeners called)
$onPageView->trigger('/dashboard', 42);
$onPageView->trigger('/settings', 42);  // Can trigger multiple times
```

### Trigger - One-time Event with Memory

Use when something happens exactly once and late subscribers should still be notified.

```php
use mini\Hooks\Trigger;

// Bootstrap completion trigger
$onBootstrapComplete = new Trigger('bootstrap-complete');

// Early subscriber
$onBootstrapComplete->listen(function(array $config) {
    echo "Bootstrap done with " . count($config) . " config items\n";
});

// Trigger fires once
$onBootstrapComplete->trigger(['db' => 'mysql', 'cache' => 'redis']);

// Late subscriber - called immediately with original data!
$onBootstrapComplete->listen(function(array $config) {
    echo "Late subscriber still gets the config\n";
});

// Check if triggered
if ($onBootstrapComplete->wasTriggered()) {
    echo "Bootstrap already complete\n";
}

// Second trigger throws LogicException
// $onBootstrapComplete->trigger([]);  // Error!
```

### Handler - First Non-null Response Wins

Use when you want to find a handler that can process something. First handler to return non-null wins.

```php
use mini\Hooks\Handler;

// Error handler chain
$errorHandler = new Handler('error-handler');

// Register handlers (checked in order)
$errorHandler->listen(function(\Throwable $e, array $context) {
    if ($e instanceof ValidationException) {
        return ['status' => 400, 'errors' => $e->errors];
    }
    return null;  // Can't handle, try next
});

$errorHandler->listen(function(\Throwable $e, array $context) {
    if ($e instanceof NotFoundException) {
        return ['status' => 404, 'message' => $e->getMessage()];
    }
    return null;  // Can't handle, try next
});

// Fallback handler
$errorHandler->listen(function(\Throwable $e, array $context) {
    return ['status' => 500, 'message' => 'Internal error'];
});

// Trigger - first non-null response returned
$response = $errorHandler->trigger(new NotFoundException('User not found'), []);
// $response = ['status' => 404, 'message' => 'User not found']
```

### Filter - Transform Data Through Pipeline

Use when you want to pass data through a chain of transformers.

```php
use mini\Hooks\Filter;

// Response filter
$responseFilter = new Filter('response-filter');

// Add filters (each receives and must return the value)
$responseFilter->listen(function(string $html, array $context): string {
    // Add security headers comment
    return "<!-- Security headers applied -->\n" . $html;
});

$responseFilter->listen(function(string $html, array $context): string {
    // Minify if in production
    if ($context['env'] === 'production') {
        return preg_replace('/\s+/', ' ', $html);
    }
    return $html;
});

$responseFilter->listen(function(string $html, array $context): string {
    // Add timing footer
    $time = microtime(true) - $context['start'];
    return $html . "\n<!-- Generated in {$time}s -->";
});

// Filter the value through all listeners
$html = '<html><body>Hello</body></html>';
$filtered = $responseFilter->filter($html, ['env' => 'dev', 'start' => $_SERVER['REQUEST_TIME_FLOAT']]);
```

### StateMachine - Managed State Transitions

Use when you need to enforce valid state transitions with hooks at each stage.

```php
use mini\Hooks\StateMachine;

// Define states and valid transitions
$order = new StateMachine([
    ['pending', 'confirmed', 'cancelled'],     // pending → confirmed OR cancelled
    ['confirmed', 'shipped', 'cancelled'],     // confirmed → shipped OR cancelled
    ['shipped', 'delivered'],                  // shipped → delivered
    ['delivered'],                             // delivered is terminal
    ['cancelled'],                             // cancelled is terminal
]);

// Hook into transitions
$order->onExitingState('pending', function($from, $to) {
    echo "Leaving pending state, going to $to\n";
});

$order->onEnteringState('confirmed', function($from, $to) {
    echo "About to confirm order\n";
    // Could throw here to prevent transition
});

$order->onEnteredState('confirmed', function($from, $to) {
    echo "Order confirmed! Sending email...\n";
});

$order->onEnteredState(['shipped', 'delivered'], function($from, $to) {
    echo "Order is now $to - updating tracking\n";
});

// Trigger transitions
$order->trigger('confirmed');  // pending → confirmed
$order->trigger('shipped');    // confirmed → shipped

// Invalid transitions throw LogicException
// $order->trigger('pending');  // Error: can't go back to pending
```

### PerItemTriggers - One-time Per Source

Use when you need a trigger that fires once per unique source (string key or object).

```php
use mini\Hooks\PerItemTriggers;

// Model loaded event - fires once per model instance
$onModelLoaded = new PerItemTriggers('model-loaded');

// Global listener (receives all)
$onModelLoaded->listen(function(string|object $source, array $data) {
    echo "Model loaded: " . (is_string($source) ? $source : get_class($source)) . "\n";
});

// Trigger for specific sources
$onModelLoaded->triggerFor('User:42', ['name' => 'John']);
$onModelLoaded->triggerFor('User:43', ['name' => 'Jane']);

// Listen for specific source - called immediately if already triggered
$onModelLoaded->listenFor('User:42', function($source, $data) {
    echo "User 42 loaded with name: {$data['name']}\n";  // Called immediately!
});

// Future subscriber for not-yet-triggered source
$onModelLoaded->listenFor('User:99', function($source, $data) {
    echo "User 99 loaded\n";  // Called when User:99 triggers
});

// Check if triggered
if ($onModelLoaded->wasTriggeredFor('User:42')) {
    echo "User 42 was already loaded\n";
}
```

## Unsubscribing

All dispatchers support unsubscribing via `off()`:

```php
$listener = function($data) { echo $data; };

$event->listen($listener);
$event->off($listener);  // Remove this listener
```

## Implementation

The hooks system is intentionally simple - just closures registered in arrays. No event objects, no complex dispatching logic. This keeps it fast and transparent.
