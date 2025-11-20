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
use RuntimeException;
use Symfony\Component\Dotenv\Dotenv;
use WeakMap;

/**
 * Core framework singleton that manages application configuration and service container.
 *
 * This class is instantiated once when Composer's autoloader loads vendor/fubber/mini/bootstrap.php.
 * It provides:
 * - Application configuration (root, locale, timezone, debug mode)
 * - PSR-11 service container with Singleton, Scoped, and Transient lifetimes
 * - Path registries for multi-location file resolution (config, routes, views)
 * - Lifecycle phase management (Initializing → Bootstrap → Ready → Shutdown)
 *
 * Configuration is read from environment variables (MINI_* prefixed) or .env file in project root.
 * All configuration happens during instantiation - the Mini instance is immutable after construction.
 *
 * @package mini
 */
final class Mini implements ContainerInterface {
    /**
     * Global singleton instance of Mini framework.
     *
     * Instantiated automatically when Composer loads vendor/fubber/mini/bootstrap.php.
     * Access via Mini::$mini throughout your application.
     *
     * @var Mini
     */
    public static ?Mini $mini = null;

    /**
     * Application root directory path.
     *
     * Detected automatically from Composer's vendor directory or set via MINI_ROOT environment variable.
     * Typically the directory containing composer.json and vendor/.
     */
    public readonly string $root;

    /**
     * Path registries for multi-location file resolution.
     *
     * Stores PathsRegistry instances for different resource types (config, routes, views).
     * Each registry supports multiple search paths with priority ordering, allowing applications
     * to override framework defaults and supporting plugin/bundle architectures.
     *
     * Example: $paths->config searches _config/ first, then vendor/fubber/mini/config/ as fallback.
     *
     * @var Mini\PathRegistries
     */
    public readonly Mini\PathRegistries $paths;

    /**
     * Web-accessible document root directory path.
     *
     * Configured via MINI_DOC_ROOT environment variable or auto-detected from $_SERVER['DOCUMENT_ROOT'].
     * Falls back to checking for html/ or public/ directories in project root. Can be null if not detected.
     */
    public readonly ?string $docRoot;

    /**
     * Base URL for the application.
     *
     * Configured via MINI_BASE_URL environment variable or auto-detected from HTTP headers.
     * Used for generating absolute URLs. Can be null if not configured or detected.
     */
    public readonly ?string $baseUrl;

    /**
     * CDN base URL for static assets.
     *
     * Configured via MINI_CDN_URL environment variable. Falls back to baseUrl if not set.
     * Used by url() function when $cdn parameter is true for serving static assets from CDN.
     */
    public readonly ?string $cdnUrl;

    /**
     * Default locale for internationalization.
     *
     * Configured via MINI_LOCALE environment variable, falls back to php.ini intl.default_locale,
     * or defaults to 'en_GB.UTF-8'. Used by I18n features and automatically sets PHP's \Locale::setDefault().
     */
    public readonly string $locale;

    /**
     * Default timezone for date/time operations.
     *
     * Configured via MINI_TIMEZONE environment variable or uses PHP's default timezone.
     * Automatically sets PHP's date_default_timezone_set() during bootstrap.
     */
    public readonly string $timezone;

    /**
     * Default language code for translations.
     *
     * Configured via MINI_LANG environment variable or defaults to 'en'.
     * Used by the translation system to determine which language files to load.
     */
    public readonly string $defaultLanguage;

    /**
     * Debug mode flag.
     *
     * Enabled when DEBUG environment variable is set to any non-empty value.
     * When true, displays detailed error pages and stack traces. When false, shows generic error pages.
     */
    public readonly bool $debug;

    /**
     * Application-wide cryptographic salt.
     *
     * Configured via MINI_SALT environment variable or generated from machine-specific fingerprint.
     * Used for CSRF tokens and other cryptographic operations requiring a consistent salt.
     */
    public readonly string $salt;

    /**
     * Ultra-fast local cache for data where network round-trip would be slower than local fetch.
     *
     * Uses APCu shared memory if available, falls back to VoidMicrocache if not.
     * Ideal for caching parsed config files, route tables, translations, schema metadata, etc.
     *
     * Performance: ~0.001ms (APCu) vs ~0.5-1ms (Redis/Memcached network RTT)
     *
     * @var Mini\Microcache\MicrocacheInterface
     */
    public readonly Mini\Microcache\MicrocacheInterface $fastCache;


    /**
     * Caches instances using a scope object; each request will
     * have a scope object which is garbage collected when the
     * request ends.
     *
     * @var WeakMap<object, object>
     */
    private readonly WeakMap $instanceCache;

    /**
     * Application lifecycle state machine
     *
     * Tracks transitions between Initializing → Bootstrap → Ready → Shutdown phases with validation.
     * Use $mini->phase->trigger(Phase::Ready) to transition between phases.
     * The Ready phase handles all request processing (one or many concurrent requests).
     *
     * @var Hooks\StateMachine
     */
    public readonly Hooks\StateMachine $phase;

    /**
     * Service definitions (factory closures + lifetime)
     *
     * @var array<string, array{factory: Closure, lifetime: Lifetime}>
     */
    private array $services = [];

    public function __construct() {
        if (self::$mini !== null) {
            throw new RuntimeException("Can't have two Mini instances");
        }
        self::$mini = $this;
        $this->instanceCache = new WeakMap();

        // Initialize ultra-fast local cache (zero config, zero dependencies)
        // APCu shared memory if available, VoidMicrocache otherwise
        $this->fastCache = (extension_loaded('apcu') && apcu_enabled())
            ? new Mini\Microcache\ApcuMicrocache()
            : new Mini\Microcache\VoidMicrocache();

        // Initialize lifecycle state machine
        // The phase tracks application state, not individual request state
        // Ready phase can handle many concurrent requests
        $this->phase = new Hooks\StateMachine([
            [Phase::Initializing, Phase::Bootstrap, Phase::Failed],  // Must bootstrap (or fail trying)
            [Phase::Bootstrap, Phase::Ready, Phase::Failed],         // Bootstrap completes or fails
            [Phase::Ready, Phase::Shutdown],                         // Ready handles requests, eventually shuts down
            [Phase::Failed, Phase::Shutdown],                        // Failed must shutdown
            [Phase::Shutdown],                                       // Terminal state
        ], 'application-lifecycle');

        // Transition to Bootstrap phase and run initialization
        $this->phase->trigger(Phase::Bootstrap);
        $this->bootstrap();
    }

    /**
     * An object that uniquely identifies the current request scope.
     * This function is intended to be used for caching in WeakMap, instances
     * that are request specific and should survive for the duration
     * of the request only.
     *
     * Returns:
     * - Current Fiber if in fiber context (Swerve, ReactPHP, RoadRunner)
     * - $this if in traditional PHP-FPM request (after bootstrap() called)
     *
     * @return object
     * @throws \LogicException If called in Bootstrap phase (before mini\bootstrap())
     */
    public function getRequestScope(): object {
        $fiber = Fiber::getCurrent();
        if ($fiber !== null) {
            return $fiber;
        }

        // Not in fiber - check if we're in Ready phase (request handling enabled)
        if ($this->phase->getCurrentState() !== Phase::Ready) {
            throw new \LogicException(
                'Cannot access Scoped services outside of Ready phase. ' .
                'Scoped services (db(), auth(), etc.) can only be accessed after calling mini\bootstrap(). ' .
                'Current phase: ' . $this->phase
            );
        }

        return $this;
    }

    /**
     * Load a configuration file using the path registry system.
     *
     * Searches for the config file in registered paths with priority ordering:
     * 1. Application config (_config/ or MINI_CONFIG_ROOT)
     * 2. Framework config (vendor/fubber/mini/config/)
     *
     * This allows applications to override framework defaults and supports plugin/bundle architectures
     * where multiple packages can contribute config files.
     *
     * @param string $filename Relative path to config file (e.g., 'routes.php', 'PDO.php')
     * @param mixed $default Return this if file not found (omit to throw exception)
     * @return mixed The value returned by the config file (usually an object or array)
     * @throws \Exception If file not found and no default provided
     */
    public function loadConfig(string $filename, mixed $default = null): mixed {
        // Get config path registry (throws if not initialized via __get)
        $configPaths = $this->paths->config;

        // Search for config file in registered paths (application first, then plugins)
        $path = $configPaths->findFirst($filename);

        if (!$path) {
            if (func_num_args() === 1) {
                $searchedPaths = implode(', ', $configPaths->getPaths());
                throw new \Exception("Config file not found: $filename (searched in: $searchedPaths)");
            }
            return $default;
        }

        // Load and return
        return Closure::fromCallable(static function() use ($path) {
            return require $path;
        })->bindTo(null, null)();
    }

    /**
     * Load service configuration by class name using path registry.
     *
     * Converts class name to config file path by replacing namespace separators with directory separators:
     * - PDO → '_config/PDO.php'
     * - Psr\SimpleCache\CacheInterface → '_config/Psr/SimpleCache/CacheInterface.php'
     * - mini\UUID\FactoryInterface → '_config/mini/UUID/FactoryInterface.php'
     *
     * Uses the path registry system, so application configs (_config/) take precedence over
     * framework defaults (vendor/fubber/mini/config/).
     *
     * @param string $className Fully qualified class name (with or without leading backslash)
     * @param mixed $default Return this if file not found (omit to throw exception)
     * @return mixed The value returned by the config file (typically a service instance)
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
     * Can only be called in Bootstrap phase (before mini\bootstrap()).
     * The container is locked once request handling begins.
     *
     * @param string $id Service identifier (typically class name)
     * @param Lifetime $lifetime Service lifetime (Singleton, Scoped, or Transient)
     * @param Closure $factory Factory function that creates the service instance
     * @throws \LogicException If called in Request phase or if service already registered
     */
    public function addService(string $id, Lifetime $lifetime, Closure $factory): void {
        if ($this->phase->getCurrentState() !== Phase::Bootstrap) {
            throw new \LogicException(
                "Cannot register services in Request phase. " .
                "Services must be registered during application bootstrap (before calling mini\bootstrap()). " .
                "Attempted to register: $id"
            );
        }

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
     * @template T
     * @param class-string<T> $id Service identifier (typically a class or interface name)
     * @return T The service instance
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

        // Initialize paths registries directly (too fundamental to be a configurable service)
        $this->paths = new Mini\PathRegistries();

        // Register as singleton service for consistency and future DI
        $this->addService(Mini\PathRegistries::class, Lifetime::Singleton, fn() => $this->paths);

        // Config registry: application config first, framework config as fallback
        $primaryConfigPath = $_ENV['MINI_CONFIG_ROOT'] ?? ($this->root . '/_config');
        $this->paths->config = new Util\PathsRegistry($primaryConfigPath);
        $frameworkConfigPath = \dirname((new \ReflectionClass(self::class))->getFileName(), 2) . '/config';
        $this->paths->config->addPath($frameworkConfigPath);

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

        // CDN base URL for static assets (falls back to baseUrl if not configured)
        $this->cdnUrl = $_ENV['MINI_CDN_URL'] ?? $baseUrl;

        // Application default locale (respects php.ini, can override with MINI_LOCALE)
        $locale = $_ENV['MINI_LOCALE'] ?? \ini_get('intl.default_locale') ?: 'en_GB.UTF-8';
        $this->locale = \Locale::canonicalize($locale);
        \Locale::setDefault($this->locale);

        // Application default timezone (respects PHP default, can override with MINI_TIMEZONE)
        $this->timezone = $_ENV['MINI_TIMEZONE'] ?? \date_default_timezone_get();
        \date_default_timezone_set($this->timezone);

        // Application default language for translations (can override with MINI_LANG)
        $this->defaultLanguage = $_ENV['MINI_LANG'] ?? 'en';

        // Application salt for cryptographic operations (CSRF tokens, etc.)
        // Uses machine-specific fingerprint + persistent random salt if MINI_SALT not set
        $this->salt = $_ENV['MINI_SALT'] ?? Util\MachineSalt::get();
    }

}
