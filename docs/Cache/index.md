# Cache - PSR-16 Simple Cache

Store and retrieve cached data with `mini\cache()` using PSR-16 SimpleCache.

## Basic Usage

```php
<?php
// Set cache
mini\cache()->set('user:123', $userData, ttl: 3600);

// Get cache
$user = mini\cache()->get('user:123');

// Get with default
$user = mini\cache()->get('user:123', default: null);

// Check existence
if (mini\cache()->has('user:123')) {
    // ...
}

// Delete
mini\cache()->delete('user:123');
```

## Multiple Keys

```php
<?php
// Get multiple
$users = mini\cache()->getMultiple(['user:123', 'user:456']);

// Set multiple
mini\cache()->setMultiple([
    'user:123' => $user123,
    'user:456' => $user456
], ttl: 3600);

// Delete multiple
mini\cache()->deleteMultiple(['user:123', 'user:456']);
```

## Clear All

```php
<?php
mini\cache()->clear();
```

## Cache-Aside Pattern

```php
<?php
$user = mini\cache()->get("user:$id");

if ($user === null) {
    $user = db()->query("SELECT * FROM users WHERE id = ?", [$id])->fetch();
    mini\cache()->set("user:$id", $user, ttl: 3600);
}
```

## Configuration

Override cache backend via `_config/Psr/SimpleCache/CacheInterface.php`:

```php
<?php
return new Symfony\Component\Cache\Psr16Cache(
    new Symfony\Component\Cache\Adapter\RedisAdapter(...)
);
```

## Default Backend

By default, Mini uses file-based cache in `_cache/` directory.

## API Reference

See `Psr\SimpleCache\CacheInterface` for full PSR-16 specification.
