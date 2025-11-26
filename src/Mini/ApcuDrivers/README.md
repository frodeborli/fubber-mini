# APCu Polyfill Drivers

This directory contains the polyfill implementation for APCu functions when the native APCu extension is not available.

## Overview

Mini provides zero-configuration APCu polyfills through a driver-based architecture. The system automatically selects the best available driver based on installed extensions, providing APCu-like functionality across different environments.

## Architecture

### Driver Selection Priority

When native APCu is not available, drivers are selected in this order:

1. **SwooleTableApcuDriver** - Uses Swoole\Table for coroutine-safe shared memory
2. **PDOSqlite3ApcuDriver** - Uses SQLite in `/dev/shm` (tmpfs) for persistence
3. **ArrayApcuDriver** - Process-scoped fallback (no persistence)

Selection happens automatically via `ApcuDriverFactory::getDriver()`.

### Components

- **ApcuDriverInterface** - Contract all drivers must implement
- **ApcuDriverTrait** - Shared logic for serialization, TTL handling, and high-level operations
- **ApcuDriverFactory** - Automatic driver selection and instantiation
- **Individual Drivers** - Backend-specific implementations

## Drivers

### SwooleTableApcuDriver

**Backend:** Swoole\Table (shared memory table)

**Characteristics:**
- Coroutine-safe (works with Swoole coroutines)
- Shared across workers in same process
- Fixed memory allocation (configurable size)
- No persistence across process restarts
- Very fast (~microseconds)

**Configuration:**
```bash
# .env
MINI_APCU_SWOOLE_SIZE=4096          # Number of rows
MINI_APCU_SWOOLE_VALUE_SIZE=4096    # Max value size (bytes)
```

**Limitations:**
- Fixed table size (entries evicted when full)
- Fixed max value size
- Requires Swoole extension

### PDOSqlite3ApcuDriver

**Backend:** SQLite database file

**Characteristics:**
- Persists across requests and process restarts
- Shared across all PHP processes
- Uses `/dev/shm` on Linux (tmpfs = RAM speed)
- Falls back to `sys_get_temp_dir()` on other systems
- Fast (~microseconds to low milliseconds)

**Configuration:**
```bash
# .env
MINI_APCU_SQLITE_PATH=/custom/path/cache.sqlite  # Optional custom path
```

**Default Paths:**
- Linux with `/dev/shm`: `/dev/shm/apcu_mini_{hash}.sqlite`
- Other systems: `{temp_dir}/apcu_mini_{hash}.sqlite`

**Optimizations:**
- WAL journal mode (concurrent reads/writes)
- `synchronous = OFF` (speed over durability)
- `temp_store = MEMORY` (temp tables in RAM)
- `busy_timeout = 5000ms` (retry on lock contention)

**Limitations:**
- Requires `pdo_sqlite` extension
- File locking overhead (mitigated by WAL mode)
- Slightly slower than native APCu

### ArrayApcuDriver

**Backend:** PHP array in process memory

**Characteristics:**
- No persistence (cleared after each request)
- Process-scoped only
- Instant access (no serialization)
- Always available (no dependencies)

**Use Cases:**
- Development without APCu/Swoole/SQLite
- Single-request caching scenarios
- Testing without external dependencies

**Limitations:**
- No cross-request persistence
- No cross-process sharing
- Loses all data after request ends

## Implementation Details

### ApcuDriverTrait

Provides common functionality for all drivers:

**High-level operations:**
- `add()` - Store if not exists (SETNX)
- `store()` - Store/overwrite (SET)
- `fetch()` - Retrieve value(s)
- `exists()` - Check existence
- `entry()` - Atomic fetch-or-compute
- `cas()` - Compare-and-swap
- `inc()` / `dec()` - Atomic increment/decrement

**TTL handling:**
- Logical expiry stored in serialized payload
- Backend TTL used for coarse garbage collection
- Expired entries automatically filtered on fetch

**Garbage collection:**
- Probabilistic GC (1% chance per `store()`/`entry()`)
- Removes expired entries from backend
- Configurable via `shouldGarbageCollect()` method

### Serialization Format

Values are serialized as:
```php
[
    'v' => $actualValue,
    'expires_at' => $timestamp  // Unix timestamp or null
]
```

This allows:
- Storage of any PHP type
- Precise TTL enforcement independent of backend
- Version-safe upgrades (can add fields without breaking)

### Backend Primitives

Each driver implements four low-level methods:

```php
// Fetch raw payload
protected function _fetch(string $key, bool &$found = null): ?string;

// Store only if key doesn't exist (SETNX)
protected function _add(string $key, string $payload, int $ttl): bool;

// Store/overwrite key (SET)
protected function _store(string $key, string $payload, int $ttl): bool;

// Delete key
protected function _delete(string $key): bool;
```

The trait handles all serialization, TTL logic, and high-level operations.

## Usage for Framework Developers

### Using the Polyfill

```php
// Automatic driver selection
use mini\Mini\ApcuDrivers\ApcuDriverFactory;

$driver = ApcuDriverFactory::getDriver();

// Store value
$driver->store('key', 'value', ttl: 60);

// Fetch value
$value = $driver->fetch('key', $success);

// Atomic fetch-or-compute
$config = $driver->entry('app:config', function() {
    return loadExpensiveConfig();
}, ttl: 300);
```

### Direct Driver Instantiation

```php
use mini\Mini\ApcuDrivers\PDOSqlite3ApcuDriver;

$driver = new PDOSqlite3ApcuDriver('/dev/shm/custom_cache.sqlite');
$driver->store('key', 'value', 60);
```

## Performance Characteristics

| Operation | Native APCu | Swoole\Table | SQLite | Array |
|-----------|-------------|--------------|--------|-------|
| `store()` | ~1μs | ~2-5μs | ~50-200μs | ~1μs |
| `fetch()` | ~1μs | ~2-5μs | ~20-100μs | ~1μs |
| `entry()` | ~1μs | ~2-5μs | ~50-200μs | ~1μs |
| Shared | Process | Workers | Process | None |
| Persist | RAM | RAM | Disk/tmpfs | None |

**Note:** SQLite performance dramatically improves when using `/dev/shm` (tmpfs) on Linux.

## Native APCu Support

When the native APCu extension is installed:

1. Polyfill skips driver initialization
2. Native functions are used directly
3. Only `apcu_entry()` is polyfilled if APCu < 5.1.0

Check in `src/apcu-polyfill.php`:
```php
if (extension_loaded('apcu')) {
    // Use native APCu
    if (!function_exists('apcu_entry')) {
        // Polyfill only apcu_entry for old APCu versions
    }
    return;
}

// Load full polyfill with driver
```

## Adding New Drivers

To add a new driver:

1. Implement `ApcuDriverInterface`
2. Use `ApcuDriverTrait` for common logic
3. Implement four backend primitives (`_fetch`, `_add`, `_store`, `_delete`)
4. Implement info methods (`info()`, `sma_info()`, etc.)
5. Add to `ApcuDriverFactory::getDriver()` selection logic

Example skeleton:
```php
<?php
namespace mini\Mini\ApcuDrivers;

class CustomApcuDriver implements ApcuDriverInterface
{
    use ApcuDriverTrait;

    protected function _fetch(string $key, bool &$found = null): ?string {
        // Fetch from your backend
    }

    protected function _add(string $key, string $payload, int $ttl): bool {
        // Add to your backend (SETNX semantics)
    }

    protected function _store(string $key, string $payload, int $ttl): bool {
        // Store to your backend (SET semantics)
    }

    protected function _delete(string $key): bool {
        // Delete from your backend
    }

    public function info(bool $limited = false): array|false {
        // Return cache statistics
    }

    public function clear_cache(): bool {
        // Clear all entries
    }

    public function enabled(): bool {
        return true;
    }

    public function sma_info(bool $limited = false): array|false {
        return false;  // Not applicable
    }
}
```

## Testing

All drivers are tested for APCu compatibility:

```bash
vendor/bin/phpunit tests/ApcuDrivers/
```

Tests cover:
- Basic store/fetch operations
- TTL expiry
- Atomic operations (cas, inc, dec)
- Batch operations
- Garbage collection
- Edge cases (large values, special characters)

## Production Recommendations

1. **Best:** Install native APCu extension for maximum performance
2. **Good:** Use SQLite driver with `/dev/shm` (Linux tmpfs)
3. **Acceptable:** Use Swoole\Table if running under Swoole
4. **Development only:** Array driver (no persistence)

Installation commands:
```bash
# Debian/Ubuntu
sudo apt-get install php-apcu

# Alpine Linux (Docker)
apk add php83-apcu

# PECL
pecl install apcu
```

## See Also

- Main documentation: `README.md` (APCu Polyfill section)
- PathsRegistry usage: `src/Util/PathsRegistry.php`
- Polyfill entry point: `src/apcu-polyfill.php`
