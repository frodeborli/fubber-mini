# Hooks - Event Dispatcher System

Lightweight event dispatchers for extending framework behavior and building event-driven applications.

## Dispatcher Classes

### Event

Multi-fire dispatcher where all listeners are notified on each trigger.

```php
use mini\Hooks\Event;

/** @var Event<string> */
$onMessage = new Event('message');

$onMessage->listen(function(string $msg) {
    echo "Received: $msg\n";
});

$onMessage->once(function(string $msg) {
    echo "First message only: $msg\n";
});

$onMessage->trigger('Hello');  // Both listeners called
$onMessage->trigger('World');  // Only first listener called
```

**Methods:** `trigger(mixed ...$args)`, `listen(Closure ...$listeners)`, `once(Closure ...$listeners)`, `off(Closure ...$listeners)`

### Trigger

One-time event with memory. Late subscribers receive the original payload immediately.

```php
use mini\Hooks\Trigger;

/** @var Trigger<array> */
$onReady = new Trigger('app-ready');

$onReady->listen(fn($config) => setup($config));  // Waits for trigger

$onReady->trigger(['db' => 'mysql']);  // Fires once

$onReady->listen(fn($config) => lateSetup($config));  // Called immediately!

$onReady->wasTriggered();  // true
```

**Methods:** `trigger(mixed ...$args)`, `listen(Closure ...$listeners)`, `off(Closure ...$listeners)`, `wasTriggered(): bool`

### Handler

Chain of responsibility - first non-null response wins, remaining listeners skipped.

```php
use mini\Hooks\Handler;

/** @var Handler<\Throwable, string> */
$errorHandler = new Handler('error');

$errorHandler->listen(function(\Throwable $e): ?string {
    if ($e instanceof ValidationException) {
        return "Validation failed: {$e->getMessage()}";
    }
    return null;  // Can't handle, try next
});

$errorHandler->listen(fn(\Throwable $e) => "Error: {$e->getMessage()}");  // Fallback

$response = $errorHandler->trigger(new \RuntimeException('Oops'));
```

**Methods:** `trigger(mixed $data, mixed ...$args): mixed`, `listen(Closure ...$listeners)`, `off(Closure ...$listeners)`

### Filter

Transform data through a pipeline. Each listener receives and must return the value.

```php
use mini\Hooks\Filter;

/** @var Filter<string> */
$htmlFilter = new Filter('html-output');

$htmlFilter->listen(fn(string $html) => trim($html));
$htmlFilter->listen(fn(string $html) => "<!-- Generated -->\n$html");

$output = $htmlFilter->filter('<p>Hello</p>');
```

**Methods:** `filter(mixed $value, mixed ...$args): mixed`, `listen(Closure ...$listeners)`, `off(Closure ...$listeners)`

### StateMachine

Managed state transitions with validation and lifecycle hooks.

```php
use mini\Hooks\StateMachine;

enum Status { case Draft; case Published; case Archived; }

/** @var StateMachine<Status> */
$workflow = new StateMachine([
    [Status::Draft, Status::Published],           // Draft → Published
    [Status::Published, Status::Archived],        // Published → Archived
    [Status::Archived],                           // Terminal
]);

$workflow->onEnteringState(Status::Published, fn($from, $to) => validate());
$workflow->onEnteredState(Status::Published, fn($from, $to) => notify());

$workflow->trigger(Status::Published);
$workflow->getCurrentState();  // Status::Published
```

**Transition hooks:** `onEnteringState()`, `onEnteredState()`, `onExitingState()`, `onExitedState()`, `onExitCurrentState()`

**Methods:** `trigger(TState $state)`, `listen(Closure ...$listeners)`, `off(Closure ...$listeners)`, `getCurrentState(): TState`

### PerItemTriggers

One-time triggers per unique source (string or object). Late subscribers for triggered sources are called immediately.

```php
use mini\Hooks\PerItemTriggers;

/** @var PerItemTriggers<string, array> */
$onUserLoaded = new PerItemTriggers('user-loaded');

// Global listener for all users
$onUserLoaded->listen(fn($id, $data) => cache($id, $data));

// Trigger for specific user
$onUserLoaded->triggerFor('user:42', ['name' => 'John']);

// Late subscriber - called immediately since user:42 already triggered
$onUserLoaded->listenFor('user:42', fn($id, $data) => log($data));

$onUserLoaded->wasTriggeredFor('user:42');  // true
```

**Methods:** `triggerFor(string|object $source, mixed ...$data)`, `listen(Closure ...$listeners)`, `listenFor(string|object $source, Closure ...$listeners)`, `off(Closure ...$listeners)`, `wasTriggeredFor(string|object $source): bool`

## Typed Event Properties Pattern

Expose dispatchers as typed readonly properties for IDE support and discoverability:

```php
class UserService {
    /** @var Event<User> */
    public readonly Event $onUserCreated;

    /** @var Event<User> */
    public readonly Event $onUserDeleted;

    public function __construct() {
        $this->onUserCreated = new Event('user-created');
        $this->onUserDeleted = new Event('user-deleted');
    }
}

// Usage with full IDE autocompletion
$service->onUserCreated->listen(fn(User $user) => sendWelcome($user));
```

## Base Class: Dispatcher

All dispatchers extend `Dispatcher` which provides:

- **Exception handling** - Configure via `Dispatcher::configure()` for async event loops
- **Debug info** - `getDescription()`, `getFile()`, `getLine()` for tracing dispatcher origins
- **Deferred execution** - Listeners are queued and run after the trigger call completes
