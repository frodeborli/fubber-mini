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

## Recommended Pattern: Typed Event Properties

The idiomatic way to use dispatchers is to expose them as typed readonly properties on your class, one property per event type:

```php
use mini\Hooks\Event;

// Define event payload as a class
class PageViewEvent {
    public function __construct(
        public readonly string $path,
        public readonly int $userId,
    ) {}
}

class PageTracker {
    /** @var Event<PageViewEvent> */
    public readonly Event $onPageView;

    public function __construct() {
        $this->onPageView = new Event('page-view');
    }

    public function trackView(string $path, int $userId): void {
        // ... tracking logic ...
        $this->onPageView->trigger(new PageViewEvent($path, $userId));
    }
}

// Usage - IDE provides full type inference
$tracker = new PageTracker();
$tracker->onPageView->listen(function(PageViewEvent $event) {
    Analytics::track($event->path, $event->userId);
});
```

This pattern provides:
- **Type safety** - PHPStan/Psalm can verify listener signatures
- **IDE support** - Autocompletion for event properties
- **Discoverability** - Class properties document available events
- **Encapsulation** - Event data grouped in payload objects

For simple cases, you can also use scalar types directly:

```php
/** @var Event<string> */
public readonly Event $onMessage;
```

## Dispatcher Classes

### Event - Multi-fire, Multiple Listeners

Use when an event can happen multiple times and you want all listeners notified.

```php
use mini\Hooks\Event;

class PageViewEvent {
    public function __construct(
        public readonly string $path,
        public readonly int $userId,
    ) {}
}

/** @var Event<PageViewEvent> */
$onPageView = new Event('page-view');

// Subscribe listeners
$onPageView->listen(function(PageViewEvent $event) {
    error_log("Page viewed: {$event->path} by user {$event->userId}");
});

$onPageView->listen(function(PageViewEvent $event) {
    Analytics::track('page_view', ['path' => $event->path, 'user' => $event->userId]);
});

// One-time listener (auto-unsubscribes after first trigger)
$onPageView->once(function(PageViewEvent $event) {
    echo "First page view recorded!";
});

// Trigger the event (all listeners called)
$onPageView->trigger(new PageViewEvent('/dashboard', 42));
$onPageView->trigger(new PageViewEvent('/settings', 42));  // Can trigger multiple times
```

### Trigger - One-time Event with Memory

Use when something happens exactly once and late subscribers should still be notified.

```php
use mini\Hooks\Trigger;

class BootstrapCompleteEvent {
    public function __construct(
        public readonly array $config,
    ) {}
}

/** @var Trigger<BootstrapCompleteEvent> */
$onBootstrapComplete = new Trigger('bootstrap-complete');

// Early subscriber
$onBootstrapComplete->listen(function(BootstrapCompleteEvent $event) {
    echo "Bootstrap done with " . count($event->config) . " config items\n";
});

// Trigger fires once
$onBootstrapComplete->trigger(new BootstrapCompleteEvent(['db' => 'mysql', 'cache' => 'redis']));

// Late subscriber - called immediately with original data!
$onBootstrapComplete->listen(function(BootstrapCompleteEvent $event) {
    echo "Late subscriber still gets the config\n";
});

// Check if triggered
if ($onBootstrapComplete->wasTriggered()) {
    echo "Bootstrap already complete\n";
}

// Second trigger throws LogicException
// $onBootstrapComplete->trigger(...);  // Error!
```

### Handler - First Non-null Response Wins

Use when you want to find a handler that can process something. First handler to return non-null wins.

```php
use mini\Hooks\Handler;

class ErrorResponse {
    public function __construct(
        public readonly int $status,
        public readonly string $message,
        public readonly array $errors = [],
    ) {}
}

/** @var Handler<\Throwable, ErrorResponse> */
$errorHandler = new Handler('error-handler');

// Register handlers (checked in order)
$errorHandler->listen(function(\Throwable $e): ?ErrorResponse {
    if ($e instanceof ValidationException) {
        return new ErrorResponse(400, 'Validation failed', $e->errors);
    }
    return null;  // Can't handle, try next
});

$errorHandler->listen(function(\Throwable $e): ?ErrorResponse {
    if ($e instanceof NotFoundException) {
        return new ErrorResponse(404, $e->getMessage());
    }
    return null;  // Can't handle, try next
});

// Fallback handler
$errorHandler->listen(function(\Throwable $e): ErrorResponse {
    return new ErrorResponse(500, 'Internal error');
});

// Trigger - first non-null response returned
$response = $errorHandler->trigger(new NotFoundException('User not found'));
// $response->status === 404
```

### Filter - Transform Data Through Pipeline

Use when you want to pass data through a chain of transformers.

```php
use mini\Hooks\Filter;

/** @var Filter<string> */
$responseFilter = new Filter('response-filter');

// Add filters (each receives and must return the value)
$responseFilter->listen(function(string $html): string {
    // Add security headers comment
    return "<!-- Security headers applied -->\n" . $html;
});

$responseFilter->listen(function(string $html): string {
    // Minify in production
    if ($_ENV['APP_ENV'] === 'production') {
        return preg_replace('/\s+/', ' ', $html);
    }
    return $html;
});

$responseFilter->listen(function(string $html): string {
    // Add timing footer
    $time = microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'];
    return $html . "\n<!-- Generated in {$time}s -->";
});

// Filter the value through all listeners
$html = '<html><body>Hello</body></html>';
$filtered = $responseFilter->filter($html);
```

### StateMachine - Managed State Transitions

Use when you need to enforce valid state transitions with hooks at each stage.

```php
use mini\Hooks\StateMachine;

enum OrderStatus {
    case Pending;
    case Confirmed;
    case Shipped;
    case Delivered;
    case Cancelled;
}

/** @var StateMachine<OrderStatus> */
$order = new StateMachine([
    [OrderStatus::Pending, OrderStatus::Confirmed, OrderStatus::Cancelled],
    [OrderStatus::Confirmed, OrderStatus::Shipped, OrderStatus::Cancelled],
    [OrderStatus::Shipped, OrderStatus::Delivered],
    [OrderStatus::Delivered],   // terminal
    [OrderStatus::Cancelled],   // terminal
]);

// Hook into transitions
$order->onExitingState(OrderStatus::Pending, function(OrderStatus $from, OrderStatus $to) {
    echo "Leaving pending state, going to {$to->name}\n";
});

$order->onEnteringState(OrderStatus::Confirmed, function(OrderStatus $from, OrderStatus $to) {
    echo "About to confirm order\n";
    // Could throw here to prevent transition
});

$order->onEnteredState(OrderStatus::Confirmed, function(OrderStatus $from, OrderStatus $to) {
    echo "Order confirmed! Sending email...\n";
});

$order->onEnteredState([OrderStatus::Shipped, OrderStatus::Delivered], function(OrderStatus $from, OrderStatus $to) {
    echo "Order is now {$to->name} - updating tracking\n";
});

// Trigger transitions
$order->trigger(OrderStatus::Confirmed);  // Pending → Confirmed
$order->trigger(OrderStatus::Shipped);    // Confirmed → Shipped

// Invalid transitions throw LogicException
// $order->trigger(OrderStatus::Pending);  // Error: can't go back to Pending
```

### PerItemTriggers - One-time Per Source

Use when you need a trigger that fires once per unique source (string key or object).

```php
use mini\Hooks\PerItemTriggers;

class ModelLoadedEvent {
    public function __construct(
        public readonly string $name,
    ) {}
}

/** @var PerItemTriggers<string, ModelLoadedEvent> */
$onModelLoaded = new PerItemTriggers('model-loaded');

// Global listener (receives all)
$onModelLoaded->listen(function(string $source, ModelLoadedEvent $event) {
    echo "Model loaded: $source with name {$event->name}\n";
});

// Trigger for specific sources
$onModelLoaded->triggerFor('User:42', new ModelLoadedEvent('John'));
$onModelLoaded->triggerFor('User:43', new ModelLoadedEvent('Jane'));

// Listen for specific source - called immediately if already triggered
$onModelLoaded->listenFor('User:42', function(string $source, ModelLoadedEvent $event) {
    echo "User 42 loaded with name: {$event->name}\n";  // Called immediately!
});

// Future subscriber for not-yet-triggered source
$onModelLoaded->listenFor('User:99', function(string $source, ModelLoadedEvent $event) {
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
