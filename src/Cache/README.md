# Cache - PSR-16 SimpleCache

## Philosophy

Mini provides **smart caching with zero configuration**. We auto-detect the best available driver and provide a clean PSR-16 interface. No setup required, but fully customizable when needed.

**Key Principles:**
- **Zero config** - Works out of the box with smart defaults
- **PSR-16 compliant** - Standard interface, works with any PSR-16 library
- **Driver auto-detection** - APCu > SQLite > Filesystem (best available)
- **Namespaced caching** - Isolate cache keys by context
- **Simple API** - get, set, delete, clear - nothing more

## Setup

### Default Configuration (Auto-Detection)

No configuration needed! Mini automatically selects the best available driver:

```php
// Just use it - driver selected automatically
cache()->set('key', 'value', 3600);
$value = cache()->get('key');
```

**Driver Priority:**
1. **APCu** - Fastest, in-memory (if `apcu` extension installed)
2. **SQLite** - Fast, persistent (if `pdo_sqlite` extension available)
3. **Filesystem** - Always available, stores in `/tmp`

### Custom Cache Configuration

To use a custom cache driver, create `_config/Psr/SimpleCache/CacheInterface.php`:

**Redis:**
```php
<?php
// _config/Psr/SimpleCache/CacheInterface.php

use Predis\Client;

$redis = new Client('tcp://127.0.0.1:6379');

return new class($redis) implements \Psr\SimpleCache\CacheInterface {
    public function __construct(private Client $redis) {}

    public function get(string $key, mixed $default = null): mixed {
        $value = $this->redis->get($key);
        return $value !== null ? unserialize($value) : $default;
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool {
        $serialized = serialize($value);
        if ($ttl === null) {
            $this->redis->set($key, $serialized);
        } else {
            $this->redis->setex($key, $ttl, $serialized);
        }
        return true;
    }

    public function delete(string $key): bool {
        $this->redis->del($key);
        return true;
    }

    public function clear(): bool {
        $this->redis->flushdb();
        return true;
    }

    public function has(string $key): bool {
        return $this->redis->exists($key);
    }

    // ... implement remaining PSR-16 methods
};
```

**Memcached:**
```php
<?php
// _config/Psr/SimpleCache/CacheInterface.php

$memcached = new Memcached();
$memcached->addServer('localhost', 11211);

return new class($memcached) implements \Psr\SimpleCache\CacheInterface {
    // ... implement PSR-16 interface
};
```

**Use Database for Caching:**
```php
<?php
// _config/Psr/SimpleCache/CacheInterface.php

return new \mini\Cache\DatabaseCache(db());
```

## Common Usage Examples

### Basic Operations

```php
// Store value for 1 hour
cache()->set('user:123', $userData, 3600);

// Retrieve value
$user = cache()->get('user:123');

// With default value
$settings = cache()->get('settings', ['theme' => 'dark']);

// Check if exists
if (cache()->has('user:123')) {
    echo "Cached!";
}

// Delete
cache()->delete('user:123');

// Clear all cache
cache()->clear();
```

### Caching Database Queries

```php
function getPopularPosts() {
    $cacheKey = 'posts:popular';

    // Try cache first
    $posts = cache()->get($cacheKey);

    if ($posts === null) {
        // Cache miss - fetch from database
        $posts = db()->query("
            SELECT * FROM posts
            WHERE published = 1
            ORDER BY views DESC
            LIMIT 10
        ");

        // Cache for 1 hour
        cache()->set($cacheKey, $posts, 3600);
    }

    return $posts;
}
```

### Namespaced Caching

Isolate cache keys by context:

```php
// User-specific cache
$userCache = cache('user:' . $userId);
$userCache->set('preferences', $prefs);
$userCache->set('recent_activity', $activity);

// Clear only this user's cache
$userCache->clear();

// API cache namespace
$apiCache = cache('api:v1');
$apiCache->set('endpoints', $endpoints, 7200);
```

### Cache Invalidation Patterns

```php
// Tag-based invalidation (manual implementation)
function cacheWithTags($key, $value, $ttl, $tags) {
    cache()->set($key, $value, $ttl);

    // Store key in each tag's list
    foreach ($tags as $tag) {
        $tagKeys = cache()->get("tag:$tag", []);
        $tagKeys[] = $key;
        cache()->set("tag:$tag", array_unique($tagKeys), $ttl);
    }
}

function invalidateTag($tag) {
    $tagKeys = cache()->get("tag:$tag", []);
    foreach ($tagKeys as $key) {
        cache()->delete($key);
    }
    cache()->delete("tag:$tag");
}

// Usage
cacheWithTags('post:123', $post, 3600, ['posts', 'user:456']);
invalidateTag('posts'); // Clears all posts cache
```

### Batch Operations

```php
// Set multiple values
cache()->setMultiple([
    'user:1' => $user1,
    'user:2' => $user2,
    'user:3' => $user3,
], 3600);

// Get multiple values
$users = cache()->getMultiple(['user:1', 'user:2', 'user:3']);

// Delete multiple
cache()->deleteMultiple(['user:1', 'user:2', 'user:3']);
```

### Rate Limiting with Cache

```php
function checkRateLimit($userId, $limit = 100, $window = 60) {
    $key = "ratelimit:$userId";

    $count = cache()->get($key, 0);

    if ($count >= $limit) {
        throw new Exception("Rate limit exceeded");
    }

    cache()->set($key, $count + 1, $window);
}

// Usage
checkRateLimit($userId); // Allows 100 requests per minute
```

### Caching with TTL Variations

```php
// Cache for 5 minutes
cache()->set('short-term', $data, 300);

// Cache for 1 hour
cache()->set('medium-term', $data, 3600);

// Cache for 1 day
cache()->set('long-term', $data, 86400);

// Cache indefinitely (until manually cleared)
cache()->set('permanent', $data);

// Using DateInterval
cache()->set('key', $value, new DateInterval('PT1H')); // 1 hour
```

## Advanced Examples

### Cache-Aside Pattern

```php
class UserRepository {
    public function find($id) {
        $key = "user:$id";

        return cache()->get($key) ?? $this->loadAndCache($id);
    }

    private function loadAndCache($id) {
        $user = db()->queryOne("SELECT * FROM users WHERE id = ?", [$id]);

        if ($user) {
            cache()->set("user:$id", $user, 3600);
        }

        return $user;
    }

    public function update($id, $data) {
        db()->exec("UPDATE users SET ... WHERE id = ?", [..., $id]);

        // Invalidate cache
        cache()->delete("user:$id");
    }
}
```

### Fragment Caching in Templates

```php
<?php
// _views/homepage.php

$html = cache()->get('homepage:hero');

if ($html === null) {
    ob_start();
    ?>
    <div class="hero">
        <?php foreach (getHeroSlides() as $slide): ?>
            <div class="slide"><?= h($slide['title']) ?></div>
        <?php endforeach ?>
    </div>
    <?php
    $html = ob_get_clean();
    cache()->set('homepage:hero', $html, 3600);
}

echo $html;
```

### Stampede Prevention

```php
function getCachedValue($key, $callback, $ttl = 3600) {
    $value = cache()->get($key);

    if ($value === null) {
        // Use lock to prevent stampede
        $lockKey = "$key:lock";

        if (cache()->get($lockKey) === null) {
            cache()->set($lockKey, true, 10); // 10 second lock

            $value = $callback();
            cache()->set($key, $value, $ttl);

            cache()->delete($lockKey);
        } else {
            // Wait and retry
            sleep(1);
            return getCachedValue($key, $callback, $ttl);
        }
    }

    return $value;
}
```

## Available Drivers

### APCu Cache
- **Speed:** Fastest (in-memory)
- **Persistence:** No (cleared on server restart)
- **Shared:** Yes (across requests)
- **Best for:** Session storage, temporary data

### SQLite Cache
- **Speed:** Fast
- **Persistence:** Yes (survives restarts)
- **Shared:** Yes (file-based)
- **Best for:** Development, small applications

### Filesystem Cache
- **Speed:** Moderate
- **Persistence:** Yes
- **Shared:** Yes (if shared filesystem)
- **Best for:** Fallback, always available

### Database Cache
- **Speed:** Moderate (depends on DB)
- **Persistence:** Yes
- **Shared:** Yes
- **Best for:** Consistency with database

## Configuration

**Config File:** `_config/Psr/SimpleCache/CacheInterface.php` (optional)

**Environment Variables:** None - cache is auto-configured

## Overriding the Service

```php
// _config/Psr/SimpleCache/CacheInterface.php

// Use Redis
return new \Your\Redis\CacheAdapter($redisClient);

// Use Memcached
return new \Your\Memcached\CacheAdapter($memcached);

// Use Symfony Cache component
return new \Symfony\Component\Cache\Psr16Cache(
    new \Symfony\Component\Cache\Adapter\RedisAdapter($redis)
);
```

## Cache Scope

Cache is **Singleton** - one instance shared across the entire application lifecycle. This is appropriate because:
- Cache state should be consistent across the request
- PSR-16 implementations are typically stateless
- Multiple instances would create unnecessary overhead
