# Breaking Changes Log

Mini framework is in active internal development. We prioritize clean, simple code over backward compatibility. When we find a better approach, we remove the old implementation rather than maintain redundant code.

This log tracks breaking changes for reference when reviewing old code or conversations.

## PSR-7 url() Function with CDN Support

The `url()` function now returns `UriInterface` instead of string and includes proper relative path resolution and CDN support.

### Changed Signature
```php
// Before
function url($path = '', array $query = []): string

// After
function url(string|UriInterface $path = '', array $query = [], bool $cdn = false): UriInterface
```

### New Behavior
- Returns `UriInterface` (PSR-7) instead of string
- Properly resolves relative paths (`.`, `..`)
- Strips scheme/host from input URLs - always resolves against base URL
- Supports CDN via `$cdn` parameter
- UriInterface is stringable - templates still work: `<?= url('/path') ?>`

### New Environment Variable
- `MINI_CDN_URL` - CDN base URL for static assets (optional, defaults to `baseUrl`)

### Migration

**Templates** - No changes needed (UriInterface is stringable):
```php
<a href="<?= url('/users') ?>">Users</a>
```

**Type hints** - Update if you type-hinted the return value:
```php
// Before
$url = url('/path');  // string

// After
$url = url('/path');  // UriInterface (but still works as string)
```

**CDN usage**:
```php
// Static assets via CDN
<link href="<?= url('/css/app.css', cdn: true) ?>" rel="stylesheet">
<img src="<?= url('/images/logo.png', cdn: true) ?>" alt="Logo">
```

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
