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

## See Also

- **`src/Util/PathsRegistry.php`** - Individual path registry implementation
- **`src/Util/InstanceStore.php`** - Base storage class
- **`src/Mini.php`** - Core Mini singleton that uses PathRegistries
