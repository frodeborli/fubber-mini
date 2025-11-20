# Microcache - Ultra-Fast Local Caching

Microcache provides sub-millisecond local caching for data where network round-trip to shared cache servers (Redis, Memcached) would be slower than fetching from local sources.

## When to Use Microcache

**Use microcache for:**
- Parsed configuration files
- Route lookup tables
- Translation files
- Database schema metadata
- Compiled templates
- Frequently accessed small datasets

**Don't use microcache for:**
- Data that must be shared across multiple servers (use Redis/Memcached)
- Large datasets (megabytes+)
- Data requiring cache invalidation across server fleet
- User-specific session data (use session storage)

## Performance Comparison

```
Memcached/Redis:  ~0.5-1ms   (network round-trip)
APCu:            ~0.001ms    (shared memory)
Process memory:  ~0.0001ms   (static array)
```

For data fetched hundreds of times per request, this difference compounds significantly.

## Usage

The microcache is available immediately via the Mini singleton:

```php
use mini\Mini;

// Basic usage with callback
$config = Mini::$mini->fastCache->fetch('app.config', function() {
    return parse_ini_file(__DIR__ . '/config.ini', true);
});

// With TTL (APCu only - VoidMicrocache ignores TTL)
$routes = Mini::$mini->fastCache->fetch('app.routes', function() {
    return require __DIR__ . '/_routes.php';
}, ttl: 300); // 5 minutes

// Generator function only called on cache miss
$schema = Mini::$mini->fastCache->fetch('db.schema', function() {
    // This expensive query only runs once
    return db()->query("SELECT * FROM information_schema.tables")->fetchAll();
});
```

## How It Works

### ApcuMicrocache (Default when APCu available)

Single-tier caching using APCu shared memory:

- **APCu Shared Memory**
  - Sub-millisecond access (~0.001ms)
  - Shared across all PHP-FPM workers on same server
  - Survives process restarts
  - Respects TTL (time-to-live)

**Lookup order:**
1. Check APCu → return if hit
2. Call generator function → store in APCu

### VoidMicrocache (Fallback when APCu unavailable)

No-op implementation that always invokes the generator function. Zero overhead, zero caching.

Automatically used when APCu extension is not loaded or not enabled.

## Installation

### Zero Configuration

Microcache works out of the box with no configuration. If APCu is available, it's used automatically. If not, VoidMicrocache is used as fallback.

### APCu Installation (Recommended)

For production performance, install APCu:

```bash
# Ubuntu/Debian
sudo apt-get install php-apcu

# Enable APCu
echo "apc.enable_cli=1" | sudo tee -a /etc/php/8.3/cli/php.ini
echo "apc.enable=1" | sudo tee -a /etc/php/8.3/fpm/php.ini

# Restart PHP-FPM
sudo systemctl restart php8.3-fpm
```

Verify APCu is enabled:

```bash
php -r "var_dump(extension_loaded('apcu') && apcu_enabled());"
# Should output: bool(true)
```

## Exception Handling

Generator functions can throw exceptions. Exceptions bubble up to the caller and **nothing is cached**:

```php
try {
    $data = Mini::$mini->fastCache->fetch('api.data', function() {
        $response = file_get_contents('https://api.example.com/data');
        if ($response === false) {
            throw new RuntimeException('API request failed');
        }
        return json_decode($response, true);
    });
} catch (RuntimeException $e) {
    // Handle error - cache remains empty for this key
    $data = ['error' => $e->getMessage()];
}
```

This prevents caching errors and avoids thundering herd on transient failures.

## Cache Key Naming

Use descriptive, namespaced keys to avoid collisions:

```php
// Good - clear namespace
Mini::$mini->fastCache->fetch('mini.routes.map', fn() => ...);
Mini::$mini->fastCache->fetch('app.config.database', fn() => ...);
Mini::$mini->fastCache->fetch('i18n.translations.de_DE', fn() => ...);

// Bad - collision risk
Mini::$mini->fastCache->fetch('config', fn() => ...);
Mini::$mini->fastCache->fetch('data', fn() => ...);
```

## TTL Behavior

- **ApcuMicrocache**: TTL applies to APCu cache entries
- **VoidMicrocache**: TTL parameter is ignored (nothing cached)

```php
// TTL of 0 = cache forever (until APCu eviction or manual clear)
Mini::$mini->fastCache->fetch('key', fn() => ..., ttl: 0);

// TTL of 300 = cache for 5 minutes
Mini::$mini->fastCache->fetch('key', fn() => ..., ttl: 300);
```

## Cache Invalidation

```php
// Clear specific key
apcu_delete('app.config');

// Clear all APCu cache
apcu_clear_cache();
```

## Monitoring APCu

Check APCu status and hit rates:

```php
print_r(apcu_cache_info());

// Sample output:
// Array
// (
//     [num_slots] => 4099
//     [ttl] => 0
//     [num_hits] => 123456
//     [num_misses] => 234
//     [start_time] => 1709123456
//     [mem_size] => 67108864
//     ...
// )
```

Calculate hit rate: `num_hits / (num_hits + num_misses) * 100`

## Best Practices

1. **Cache immutable data** - Config files, route tables, translations
2. **Use short TTLs for volatile data** - Database queries that may change
3. **Monitor APCu memory** - Ensure `mem_size` doesn't hit limits
4. **Namespace keys** - Avoid collisions across different features
5. **Don't cache secrets** - Microcache is not encrypted

## Implementation Details

- **Interface**: `mini\Mini\Microcache\MicrocacheInterface`
- **Implementations**:
  - `mini\Mini\Microcache\ApcuMicrocache` - Production (APCu + process memory)
  - `mini\Mini\Microcache\VoidMicrocache` - Fallback (no caching)
- **Automatic selection**: APCu used if `extension_loaded('apcu') && apcu_enabled()`, else VoidMicrocache
- **Access**: `Mini::$mini->fastCache`
- **Available**: Immediately after Mini singleton is created (before bootstrap)
