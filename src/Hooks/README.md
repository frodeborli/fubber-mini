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

## Event Types

The system provides different dispatcher types for different use cases:

- **Event** - Triggers multiple times, supports multiple listeners
- **Trigger** - One-time event that remembers if it fired
- **Handler** - Single handler that can be replaced
- **Filter** - Transform data through a pipeline of filters

See `PATTERNS.md` for detailed examples of using hooks for middleware-like patterns and output buffering.

## Implementation

The hooks system is intentionally simple - just closures registered in arrays. No event objects, no complex dispatching logic. This keeps it fast and transparent.
