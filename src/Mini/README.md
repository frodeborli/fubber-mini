# Mini - Core Framework Components

This namespace contains core infrastructure classes used internally by the Mini framework.

## PathRegistries

Container for managing multiple `PathsRegistry` instances used throughout the framework for resource discovery.

**Purpose:** Provides type-safe storage for named path registries (config, routes, views, translations) with priority-based file resolution.

### Architecture

`PathRegistries` extends `InstanceStore<PathsRegistry>` to provide:
- Type-safe storage of `PathsRegistry` instances
- Array-like access to named registries
- Automatic validation (only `PathsRegistry` instances allowed)

### Built-in Registries

The framework uses these registries by default:

- **`config`** - Configuration file discovery (`_config/` directory)
- **`routes`** - Route file discovery (`_routes/` directory)
- **`views`** - Template file discovery (`_views/` directory)
- **`translations`** - Translation file discovery (`_translations/` directory)

### Usage via Mini Core

Access registries through the Mini singleton:

```php
use mini\Mini;

// Access views registry
$viewPath = Mini::$mini->paths->views->findFirst('user/profile.php');

// Add fallback path to views
Mini::$mini->paths->views->addPath('/vendor/my-bundle/views');

// Access config registry
$configPath = Mini::$mini->paths->config->findFirst('database.php');
```

### Adding Custom Registries

You can add your own registries for custom resource types:

```php
use mini\Mini;
use mini\Util\PathsRegistry;

// Create a registry for email templates
Mini::$mini->paths->emails = new PathsRegistry(
    Mini::$mini->root . '/_emails'
);

// Add fallback path
Mini::$mini->paths->emails->addPath(__DIR__ . '/vendor/bundle/emails');

// Find template
$template = Mini::$mini->paths->emails->findFirst('welcome.html');
```

### Resolution Priority

All registries follow the same priority-based resolution:

1. **Primary path** (application directory) - always checked first
2. **Fallback paths** - checked in reverse order of addition:
   - Most recently added fallback (typically application-level override)
   - Bundle/package fallbacks
   - Framework defaults (added first, lowest priority)

This creates a natural override cascade: **App → Bundle → Framework**

### Example: View Resolution

```php
use mini\Mini;

// Framework adds its fallback during initialization
Mini::$mini->paths->views = new PathsRegistry('/app/_views');
Mini::$mini->paths->views->addPath('/vendor/mini/views');

// Bundle adds its fallback when loaded
Mini::$mini->paths->views->addPath('/vendor/my-bundle/views');

// Application can optionally add override paths
Mini::$mini->paths->views->addPath('/app/themes/custom/views');

// Resolution order when finding 'layout.php':
// 1. /app/_views/layout.php (primary)
// 2. /app/themes/custom/views/layout.php (most recent fallback)
// 3. /vendor/my-bundle/views/layout.php (bundle fallback)
// 4. /vendor/mini/views/layout.php (framework fallback)
```

### Why This Design?

This approach enables:

1. **Clean overrides** - Applications can override bundle/framework resources
2. **Bundle composition** - Multiple bundles can provide fallback resources
3. **Zero config** - Automatic discovery with sensible defaults
4. **Composer-friendly** - Works with Composer's autoload order naturally

### Integration with Composer Autoload

Since Composer loads dependencies before dependent packages, the natural loading order is:

1. Framework (`fubber/mini`) loads → adds framework fallbacks
2. Bundles load → add bundle fallbacks (higher priority)
3. Application loads → primary paths already set (highest priority)

When each adds paths via `addPath()`, the most recent (application) is checked before earlier ones (bundle before framework).

### Internal Usage

The framework uses `PathRegistries` internally for:

- **Config loading** - `Mini::loadServiceConfig()` uses config registry
- **Route discovery** - Router uses routes registry
- **Template rendering** - `render()` uses views registry
- **Translation loading** - `t()` uses translations registry

### API Reference

Since `PathRegistries` extends `InstanceStore<PathsRegistry>`, it inherits:

```php
// ArrayAccess
$registry = Mini::$mini->paths['views'];
Mini::$mini->paths['custom'] = new PathsRegistry('/path');

// Direct property access
$registry = Mini::$mini->paths->views;
Mini::$mini->paths->custom = new PathsRegistry('/path');

// WeakMap-style methods
$hasViews = Mini::$mini->paths->has('views');
$viewsRegistry = Mini::$mini->paths->get('views');
Mini::$mini->paths->set('custom', new PathsRegistry('/path'));

// Collection methods
$keys = Mini::$mini->paths->keys(); // ['views', 'config', 'routes', ...]
$count = Mini::$mini->paths->count(); // 4
```

For individual registry operations, see `PathsRegistry` documentation in `src/Util/README.md`.

## APCu Polyfill System

Mini provides automatic APCu function polyfills when the native extension is unavailable. This enables L1 caching functionality across all environments without requiring explicit configuration.

### Architecture

Located in `src/Mini/ApcuDrivers/`, the polyfill system consists of:

- **ApcuDriverInterface** - Contract for all driver implementations
- **ApcuDriverTrait** - Shared logic (serialization, TTL, high-level operations)
- **ApcuDriverFactory** - Automatic driver selection
- **Driver Implementations:**
  - `SwooleTableApcuDriver` - Swoole\Table backend (coroutine-safe)
  - `PDOSqlite3ApcuDriver` - SQLite backend (persistent, `/dev/shm` on Linux)
  - `ArrayApcuDriver` - In-memory fallback (process-scoped)

### How It Works

1. Bootstrap checks if `apcu` extension is loaded (`src/apcu-polyfill.php`)
2. If native APCu available: Uses it (only polyfills `apcu_entry()` if APCu < 5.1.0)
3. If not available: Loads driver system and defines all `apcu_*` functions
4. Driver selection happens automatically via `ApcuDriverFactory::getDriver()`

### Driver Selection Logic

```
Is Swoole loaded?
  Yes → SwooleTableApcuDriver
  No  → Is pdo_sqlite available?
    Yes → PDOSqlite3ApcuDriver (uses /dev/shm on Linux)
    No  → ArrayApcuDriver (fallback, no persistence)
```

### Configuration

**Swoole Table Driver:**
```bash
MINI_APCU_SWOOLE_SIZE=4096          # Rows (default: 4096)
MINI_APCU_SWOOLE_VALUE_SIZE=4096    # Max bytes per value (default: 4096)
```

**SQLite Driver:**
```bash
MINI_APCU_SQLITE_PATH=/custom/path.sqlite  # Custom path (optional)
```

Default SQLite path uses project root hash:
- Linux: `/dev/shm/apcu_mini_{hash}.sqlite` (tmpfs-backed RAM storage)
- Other: `{sys_temp_dir}/apcu_mini_{hash}.sqlite`

### Framework Usage

Mini uses APCu internally for performance-critical operations:

**PathsRegistry (`src/Util/PathsRegistry.php`):**
```php
// L2 cache (APCu) with 1-second TTL
$result = apcu_entry($this->cachePrefix . $filename, function() use ($filename) {
    // Expensive: Check file_exists() across multiple paths
    return $this->searchFilesystem($filename);
}, ttl: 1);
```

This pattern provides:
- **Request 1:** Filesystem check (~microseconds)
- **Request 2+:** APCu hit (~1-2 microseconds)
- **After 1 second:** Cache expires, filesystem rechecked

### Application Usage

Applications can use `apcu_*` functions directly:

```php
// Store configuration
apcu_store('app:config', $config, ttl: 300);

// Fetch-or-compute pattern
$translations = apcu_entry('i18n:en', function() {
    return json_decode(file_get_contents('translations/en.json'), true);
}, ttl: 60);

// Atomic operations
apcu_inc('page:views', 1);
```

### Performance Impact

**PathsRegistry with APCu (1s TTL):**
- Reduces filesystem `stat()` calls by ~99% under steady load
- Negligible memory overhead (only stores resolved paths)
- Automatic invalidation when paths change

**Typical numbers (SQLite driver on Linux `/dev/shm`):**
- APCu hit: ~2-5 microseconds
- Filesystem miss: ~50-100 microseconds (OS cache) to milliseconds (cold)

### Garbage Collection

All drivers implement probabilistic GC:
- 1% chance per `apcu_store()`/`apcu_entry()` call
- Scans for expired entries and removes them
- Similar to PHP session GC behavior
- No manual cleanup required

### Environment Compatibility

| Environment | Driver | Cross-Request | Persistence |
|-------------|--------|---------------|-------------|
| Native APCu | Native | ✓ | RAM only |
| Swoole | SwooleTable | ✓ (workers) | No |
| FPM/mod_php | SQLite | ✓ | Yes |
| CLI scripts | Array | ✗ | No |
| Docker | SQLite | ✓ | Yes (tmpfs) |

### For More Information

- Complete driver documentation: `src/Mini/ApcuDrivers/README.md`
- Usage examples: Main `README.md` (APCu Polyfill section)
- Implementation: `src/apcu-polyfill.php`

## See Also

- **`src/Util/PathsRegistry.php`** - Individual path registry implementation (uses APCu)
- **`src/Util/InstanceStore.php`** - Base storage class
- **`src/Mini.php`** - Core Mini singleton that uses PathRegistries
- **`src/Mini/ApcuDrivers/`** - APCu polyfill driver implementations
