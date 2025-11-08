# Breaking Changes Log

Mini framework is in active internal development. We prioritize clean, simple code over backward compatibility. When we find a better approach, we remove the old implementation rather than maintain redundant code.

This log tracks breaking changes for reference when reviewing old code or conversations.

## Phase System Introduction

The phase system replaces individual lifecycle hooks with a comprehensive state machine.

### Removed Methods
- `Mini::enterBootstrapPhase()` - use `Mini::$mini->phase->trigger(Phase::Bootstrap)`
- `Mini::enterReadyPhase()` - use `Mini::$mini->phase->trigger(Phase::Ready)`
- `Mini::enterFailedPhase()` - use `Mini::$mini->phase->trigger(Phase::Failed)`
- `Mini::enterShutdownPhase()` - use `Mini::$mini->phase->trigger(Phase::Shutdown)`
- `Mini::getCurrentPhase()` - use `Mini::$mini->phase->getCurrentState()`
- `Mini::enterRequestContext()` - framework now uses phase transitions
- `Mini::exitRequestContext()` - framework now uses phase transitions

### Removed Hooks
- `Mini::$onRequestReceived` - use `Mini::$mini->phase->onEnteringState(Phase::Ready, fn() => ...)`
- `Mini::$onAfterBootstrap` - use `Mini::$mini->phase->onEnteredState(Phase::Ready, fn() => ...)`

### Migration Examples

**Before:**
```php
Mini::$mini->onRequestReceived->listen(function() {
    // Authentication logic
});

Mini::$mini->onAfterBootstrap->listen(function() {
    // Output buffering setup
});
```

**After:**
```php
// Fires when entering Ready phase (before phase change completes)
Mini::$mini->phase->onEnteringState(Phase::Ready, function() {
    // Authentication logic
});

// Fires after Ready phase entered (after phase change completes)
Mini::$mini->phase->onEnteredState(Phase::Ready, function() {
    // Output buffering setup
});
```
