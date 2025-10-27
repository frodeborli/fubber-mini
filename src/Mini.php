<?php
namespace mini;

use Closure;
use Fiber;
use mini\Contracts\CollectionInterface;
use mini\Util\InstanceStore;
use mini\Util\PathsRegistry;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionClass;
use Symfony\Component\Dotenv\Dotenv;
use WeakMap;

/**
 * Provides application wide information for the application. This is information
 * that does not change between application requests, and is effectively global state.
 *
 * Also implements PSR-11 ContainerInterface for service dependency injection.
 *
 * @package mini
 */
final class Mini implements ContainerInterface {
    /**
     * Singleton pattern. The Mini instance is immutable and global and always contains the
     * environment configuration.
     * 
     * @var Mini
     */
    public static Mini $mini;

    /** The application root path */
    public readonly string $root;

    /**
     * Collection of path registries (config, views, migrations, etc.)
     * Allows applications and bundles to register searchable path hierarchies
     *
     * @var Util\InstanceStore<string, Util\PathsRegistry>
     */
    public readonly Util\InstanceStore $paths;

    /** The web accessible document root (can be null if it's not configured via MINI_DOC_ROOT env) */
    public readonly ?string $docRoot;

    /** The URL corresponding to $docRoot (can be null if it's not configured via MINI_BASE_URL env) */
    public readonly ?string $baseUrl;

    /** Application default locale (MINI_LOCALE env or php.ini or 'en_GB.UTF-8') */
    public readonly string $locale;

    /** Application default timezone (MINI_TIMEZONE env or PHP default) */
    public readonly string $timezone;

    /** Application default language for translations (MINI_LANG env or 'en') */
    public readonly string $defaultLanguage;

    /** Debug mode */
    public readonly bool $debug;

    /**
     * Caches instances using a scope object; each request will
     * have a scope object which is garbage collected when the
     * request ends.
     *
     * @var WeakMap<object, object>
     */
    private readonly WeakMap $instanceCache;

    /**
     * Service definitions (factory closures + lifetime)
     *
     * @var array<string, array{factory: Closure, lifetime: Lifetime}>
     */
    private array $services = [];

    public function __construct() {
        self::$mini = $this;
        $this->instanceCache = new WeakMap();
        $this->bootstrap();
    }

    /**
     * An object that uniquely identifies the current request scope.
     * This function is intended to be used for caching in WeakMap, instances
     * that are request specific and should survive for the duration
     * of the request only. 
     *
     * {@todo} This function must support customization of some form; for
     * example if a framework is able to have nested fibers that all belong
     * to the same request scope. For now, this is enough.
     * 
     * @return object 
     */
    public function getRequestScope(): object {
        $fiber = Fiber::getCurrent();
        if ($fiber !== null) {
            return $fiber;
        } else {
            return $this;
        }
    }

    /**
     * Load a config file from 'config' path registry with per-request caching
     *
     * Uses path registry to search multiple locations (application first, then plugins/bundles).
     * Config files are cached per request scope, ensuring:
     * - Traditional SAPI: Cached for entire request
     * - Long-running apps: Fresh config per request/fiber
     * - Automatic cleanup: WeakMap releases when request ends
     *
     * @param string $filename Relative to config paths (e.g., 'pdo.php', 'routes.php')
     * @param mixed $default Return this if file not found (omit to throw exception)
     * @return mixed The loaded config value (cached for this request)
     * @throws \Exception If file not found and no default provided
     */
    public function loadConfig(string $filename, mixed $default = null): mixed {
        $cache = $this->getRequestScopeCache();
        $cacheKey = 'config:' . $filename;

        // Return cached value if already loaded in this request
        if (property_exists($cache, $cacheKey)) {
            return $cache->{$cacheKey};
        }

        // Get config path registry (throws if not initialized via __get)
        $configPaths = $this->paths->config;

        // Search for config file in registered paths (application first, then plugins)
        $path = $configPaths->findFirst($filename);

        if (!$path) {
            if (func_num_args() === 1) {
                $searchedPaths = implode(', ', $configPaths->getPaths());
                throw new \Exception("Config file not found: $filename (searched in: $searchedPaths)");
            }
            // Cache the default value to avoid repeated searches
            $cache->{$cacheKey} = $default;
            return $default;
        }

        // Load, cache, and return
        $value = Closure::fromCallable(static function() use ($path) {
            return require $path;
        })->bindTo(null, null)();
        $cache->{$cacheKey} = $value;
        return $value;
    }

    /**
     * Load a config file for a service class
     *
     * Converts class name to config file path by replacing backslashes with slashes.
     * Example: \PDO::class → 'PDO.php'
     * Example: \Psr\SimpleCache\CacheInterface::class → 'Psr/SimpleCache/CacheInterface.php'
     *
     * @param string $className Fully qualified class name
     * @param mixed $default Return this if file not found (omit to throw exception)
     * @return mixed The loaded config value (cached for this request)
     * @throws \Exception If file not found and no default provided
     */
    public function loadServiceConfig(string $className, mixed $default = null): mixed {
        $configPath = str_replace('\\', '/', ltrim($className, '\\')) . '.php';
        return $this->loadConfig($configPath, ...array_slice(func_get_args(), 1));
    }

    /**
     * Returns an object that will survive for the duration of the current
     * request and on which instances can be cached.
     *
     * @return object
     */
    private function getRequestScopeCache(): object {
        $scope = $this->getRequestScope();
        if (!isset($this->instanceCache[$scope])) {
            $this->instanceCache[$scope] = new \stdClass();
        }
        return $this->instanceCache[$scope];
    }

    /**
     * Register a service with the container
     *
     * @param string $id Service identifier (typically class name)
     * @param Lifetime $lifetime Service lifetime (Singleton, Scoped, or Transient)
     * @param Closure $factory Factory function that creates the service instance
     */
    public function addService(string $id, Lifetime $lifetime, Closure $factory): void {
        if (isset($this->services[$id])) {
            throw new \LogicException("Service already registered: $id");
        }
        $this->services[$id] = ['factory' => $factory, 'lifetime' => $lifetime];
    }

    /**
     * Check if a service is registered in the container
     *
     * @param string $id Service identifier
     * @return bool True if service is registered
     */
    public function has(string $id): bool {
        return isset($this->services[$id]);
    }

    /**
     * Get a service from the container
     *
     * Creates instances based on lifetime:
     * - Singleton: One instance stored in instanceCache[$this]
     * - Scoped: One instance per request stored in instanceCache[getRequestScope()]
     * - Transient: New instance every time
     *
     * @param string $id Service identifier
     * @return mixed The service instance
     * @throws Exceptions\NotFoundException If service is not registered
     */
    public function get(string $id): mixed {
        if (!isset($this->services[$id])) {
            throw new Exceptions\NotFoundException("Service not found: $id");
        }

        $service = $this->services[$id];
        $factory = $service['factory'];
        $lifetime = $service['lifetime'];

        // Transient: Always create new instance
        if ($lifetime === Lifetime::Transient) {
            return $factory();
        }

        // Singleton: Store in instanceCache[$this]
        if ($lifetime === Lifetime::Singleton) {
            $singletonCache = $this->instanceCache[$this] ?? null;
            if ($singletonCache === null) {
                $singletonCache = new \stdClass();
                $this->instanceCache[$this] = $singletonCache;
            }

            if (!property_exists($singletonCache, $id)) {
                $singletonCache->{$id} = $factory();
            }

            return $singletonCache->{$id};
        }

        // Scoped: Store in instanceCache[getRequestScope()]
        if ($lifetime === Lifetime::Scoped) {
            $scopedCache = $this->getRequestScopeCache();
            $cacheKey = 'service:' . $id;

            if (!property_exists($scopedCache, $cacheKey)) {
                $scopedCache->{$cacheKey} = $factory();
            }

            return $scopedCache->{$cacheKey};
        }

        // Should never reach here
        throw new \LogicException("Unknown lifetime: " . $lifetime->name);
    }

    private function bootstrap(): void {
        $this->root = getenv('MINI_ROOT') ?: \dirname((new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName(), 3);
        if (is_readable($this->root . '/.env')) {
            $dotenv = new Dotenv();
            $dotenv->load($this->root . '/.env');
        }

        // Initialize paths collection with registries for framework resources
        $this->paths = new InstanceStore(Util\PathsRegistry::class);

        // Config registry: application config first, framework config as fallback
        $primaryConfigPath = $_ENV['MINI_CONFIG_ROOT'] ?? ($this->root . '/_config');
        $this->paths->config = new Util\PathsRegistry($primaryConfigPath);
        $frameworkConfigPath = \dirname((new \ReflectionClass(self::class))->getFileName(), 2) . '/config';
        $this->paths->config->addPath($frameworkConfigPath);

        // Routes registry for route handlers
        $primaryRoutesPath = $_ENV['MINI_ROUTES_ROOT'] ?? ($this->root . '/_routes');
        $this->paths->routes = new Util\PathsRegistry($primaryRoutesPath);

        $this->debug = !empty($_ENV['DEBUG']);

        $docRoot = $_ENV['MINI_DOC_ROOT'] ?? null;
        if (!$docRoot && isset($_SERVER['DOCUMENT_ROOT']) && is_dir($_SERVER['DOCUMENT_ROOT'])) {
            $docRoot = $_SERVER['DOCUMENT_ROOT'];
        }
        if (!$docRoot && is_dir($this->root . '/html')) {
            $docRoot = $this->root . '/html';
        }
        if (!$docRoot && is_dir($this->root . '/public')) {
            $docRoot = $this->root . '/public';
        }
        $this->docRoot = $docRoot;

        $baseUrl = $_ENV['MINI_BASE_URL'] ?? null;
        if ($baseUrl === null && PHP_SAPI !== 'cli' && isset($_SERVER['HTTP_HOST'])) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'];

            $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
            $scriptDir = dirname($scriptName);
            $basePath = ($scriptDir !== '/' && $scriptDir !== '\\') ? $scriptDir : '';

            $baseUrl = $scheme . '://' . $host . $basePath;
        }
        $this->baseUrl = $baseUrl;

        // Application default locale (respects php.ini, can override with MINI_LOCALE)
        $locale = $_ENV['MINI_LOCALE'] ?? \ini_get('intl.default_locale') ?: 'en_GB.UTF-8';
        $this->locale = \Locale::canonicalize($locale);
        \Locale::setDefault($this->locale);

        // Application default timezone (respects PHP default, can override with MINI_TIMEZONE)
        $this->timezone = $_ENV['MINI_TIMEZONE'] ?? \date_default_timezone_get();
        \date_default_timezone_set($this->timezone);

        // Application default language for translations (can override with MINI_LANG)
        $this->defaultLanguage = $_ENV['MINI_LANG'] ?? 'en';

        // Register core services
        $this->registerCoreServices();
    }

    /**
     * Register core framework services in the container
     */
    private function registerCoreServices(): void
    {
        // Register PDO service - delegated to service class
        $this->addService(\PDO::class, Lifetime::Scoped, fn() => Services\PDO::factory());

        // Register DatabaseInterface - delegated to service class
        $this->addService(Contracts\DatabaseInterface::class, Lifetime::Scoped, fn() => Services\DatabaseInterface::factory());

        // Register SimpleCache service - delegated to service class
        $this->addService(\Psr\SimpleCache\CacheInterface::class, Lifetime::Singleton, fn() => Services\SimpleCache::factory());

        // Note: Logger service is registered in src/Logger/functions.php
    }
}
