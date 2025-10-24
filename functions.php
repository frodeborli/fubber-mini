<?php

namespace mini;

use Exception;
use Throwable;
use Composer\Autoload\ClassLoader;
use mini\Codecs\CodecInterface;
use mini\Contracts\CollectionInterface;
use mini\Repository\RepositoryInterface;
use mini\Repository\RepositoryException;
use mini\Codecs\CodecRegistry;
use mini\Http;
use ReflectionClass;

/**
 * Mini Framework - Global Helper Functions
 *
 * These functions are automatically loaded by Composer and available globally.
 */

/**
 * Render a template with provided variables
 *
 * @param string $template Path to template file (relative to project root)
 * @param array $vars Variables to extract for template
 * @return string Rendered content
 */
function render($template, $vars = []) {
    // Get project root from global state
    $projectRoot = $GLOBALS['app']['root'] ?? dirname((new ReflectionClass(ClassLoader::class))->getFileName(), 3);

    $templatePath = $projectRoot . '/' . ltrim($template, '/');

    if (!file_exists($templatePath)) {
        throw new Exception("Template not found: $templatePath");
    }

    // Extract variables for template use
    extract($vars);

    // Start output buffering
    ob_start();

    try {
        // Include the template
        include $templatePath;
    } catch (Throwable $e) {
        ob_end_clean();
        return (string) $e;
    }

    // Get the content and clean buffer
    $content = ob_get_clean();

    return $content;
}

/**
 * Redirect to URL and exit
 */
function redirect($url) {
    header("Location: $url");
    exit;
}

/**
 * Escape HTML output
 */
function h($str) {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

/**
 * Get current URL
 */
function current_url() {
    return (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
           . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
}

/**
 * Generate URL relative to base_url with optional query parameters
 */
function url($path = '', array $query = []) {
    $config = $GLOBALS['app']['config'] ?? [];
    $base_url = $config['base_url'] ?? '';
    $path = ltrim($path, '/');
    $url = rtrim($base_url, '/') . '/' . $path;

    if (!empty($query)) {
        $separator = strpos($url, '?') !== false ? '&' : '?';
        $url .= $separator . http_build_query($query);
    }

    return $url;
}

/**
 * Flash message functions
 */
function flash_set($type, $message) {
    if (!isset($_SESSION['flash'])) {
        $_SESSION['flash'] = [];
    }
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flash_get() {
    if (!isset($_SESSION['flash'])) {
        return [];
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}


/**
 * Translation function - creates a Translatable instance
 *
 * @param string $text The text to translate
 * @param array $vars Variables for interpolation (e.g., ['name' => 'John'])
 * @return mini\Translatable
 */
function t(string $text, array $vars = []): Translatable {
    return new Translatable($text, $vars);
}

/**
 * Start session if not already started
 *
 * Safe wrapper around session_start() that prevents notices
 * when session is already active or disabled.
 *
 * @return bool True if session was started or already active, false if disabled or failed to start
 */
function session(): bool {
    if (session_status() === PHP_SESSION_NONE) {
        return session_start();
    }
    return session_status() === PHP_SESSION_ACTIVE;
}

/**
 * Get the database singleton (lazy-initialized)
 *
 * @return Contracts\DatabaseInterface Database instance from global app state
 */
function db(): Contracts\DatabaseInterface {
    if (!isset($GLOBALS['app']['db'])) {
        // Get fresh PDO instance (PDO has state, shouldn't be cached)
        $pdoConfigPath = paths('config')->findFirst('pdo.php');

        if (!$pdoConfigPath) {
            throw new \Exception('No PDO configuration found. Please create config/pdo.php or configure config[\'pdo_factory\']');
        }

        // Load fresh PDO instance from configuration
        $pdo = require $pdoConfigPath;

        if (!($pdo instanceof \PDO)) {
            throw new \Exception('PDO configuration must return a PDO instance');
        }

        // Wrap PDO with our database interface
        $GLOBALS['app']['db'] = new Database\PdoDatabase($pdo);
    }

    return $GLOBALS['app']['db'];
}

/**
 * Get the translator singleton (lazy-initialized)
 *
 * @return Translator Translator instance from global app state
 */
function translator(): Translator {
    if (!isset($GLOBALS['app']['translator'])) {
        $config = $GLOBALS['app']['config'] ?? [];
        $projectRoot = $GLOBALS['app']['root'] ?? dirname((new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName(), 3);

        $defaultLanguage = $config['default_language'] ?? 'en';
        $translationsPath = $projectRoot . '/translations';

        // Use the framework's already-configured locale
        $currentLocale = \Locale::getDefault();
        $GLOBALS['app']['translator'] = new Translator($translationsPath, $currentLocale);

        // Register mini framework translation scope
        $miniFrameworkPath = dirname(__FILE__, 1); // Path to mini framework directory
        $GLOBALS['app']['translator']->addNamedScope('MINI-FRAMEWORK', $miniFrameworkPath);
        $GLOBALS['app']['mini_scope_registered'] = true;
    }

    return $GLOBALS['app']['translator'];
}

/**
 * Get a formatter instance for convenience
 *
 * Note: Fmt methods are static, so you can also call Fmt::currency() directly
 * @return Fmt Stateless formatter instance
 */
function fmt(): Fmt {
    // Return a stateless instance - all methods are static and use locale() internally
    return new Fmt();
}

/**
 * Get cache instance (lazy-initialized)
 *
 * @param string|null $namespace Optional namespace for cache isolation
 * @return \Psr\SimpleCache\CacheInterface Cache instance
 */
function cache(?string $namespace = null): \Psr\SimpleCache\CacheInterface {
    // Initialize root cache instance if not exists
    if (!isset($GLOBALS['app']['cache'])) {
        $GLOBALS['app']['cache'] = new Cache\DatabaseCache(db());
    }

    // Return namespaced cache if namespace provided
    if ($namespace !== null) {
        return new Cache\NamespacedCache($GLOBALS['app']['cache'], $namespace);
    }

    return $GLOBALS['app']['cache'];
}

/**
 * Get the repositories store
 *
 * @return CollectionInterface<string, RepositoryInterface> Collection of repositories
 */
function repositories(): CollectionInterface {
    if (!isset($GLOBALS['app']['repositories'])) {
        $GLOBALS['app']['repositories'] = new Util\InstanceStore(RepositoryInterface::class);
    }

    return $GLOBALS['app']['repositories'];
}

/**
 * Get repository for table/model (convenience function)
 *
 * Recommended usage: mini\table(User::class) for proper type hints
 *
 * @template T of object
 * @param class-string<T>|string $name Repository name (preferably class name for type safety)
 * @return Repository<T> Repository wrapper for the specified table/model
 */
function table(string $name): Repository {
    if (!isset($GLOBALS['app']['repository_wrappers'])) {
        $GLOBALS['app']['repository_wrappers'] = [];
    }

    if (!isset($GLOBALS['app']['repository_wrappers'][$name])) {
        $implementation = repositories()->get($name);
        if ($implementation === null) {
            throw new RepositoryException("Repository '$name' not found");
        }
        $GLOBALS['app']['repository_wrappers'][$name] = new Repository($implementation);
    }

    return $GLOBALS['app']['repository_wrappers'][$name];
}

/**
 * Get the collator singleton (lazy-initialized)
 *
 * @return \Collator Collator instance for consistent sorting and comparisons
 */
function collator(): \Collator {
    if (!isset($GLOBALS['app']['collator'])) {
        // Try to get collator from cached config first
        $collator = getCachedConfig('collator.php', 'collator.php');
        if ($collator && $collator instanceof \Collator) {
            $GLOBALS['app']['collator'] = $collator;
        } else {
            // Use PHP's default locale, fallback to en_US_POSIX for SQLite compatibility
            $currentLocale = \Locale::getDefault() ?: 'en_US_POSIX';
            $GLOBALS['app']['collator'] = new \Collator($currentLocale);
            $GLOBALS['app']['collator']->setAttribute(\Collator::NUMERIC_COLLATION, \Collator::ON);
        }
    }

    return $GLOBALS['app']['collator'];
}

/**
 * Get a correctly configured NumberFormatter instance
 *
 * @param string|null $locale Override locale (uses mini\locale() if null)
 * @param int $style NumberFormatter style constant (DECIMAL, CURRENCY, etc.)
 * @return \NumberFormatter NumberFormatter instance configured for the locale
 */
function numberFormatter(?string $locale = null, int $style = \NumberFormatter::DECIMAL): \NumberFormatter {
    // Use provided locale or fall back to PHP's default locale
    $targetLocale = $locale ?? \Locale::getDefault();

    // Create NumberFormatter with proper locale
    return new \NumberFormatter($targetLocale, $style);
}

/**
 * Get a correctly configured MessageFormatter instance
 *
 * @param string $pattern ICU MessageFormat pattern
 * @param string|null $locale Override locale (uses mini\locale() if null)
 * @return \MessageFormatter MessageFormatter instance configured for the locale
 */
function messageFormatter(string $pattern, ?string $locale = null): \MessageFormatter {
    // Use provided locale or fall back to PHP's default locale
    $targetLocale = $locale ?? \Locale::getDefault();

    // Create MessageFormatter with proper locale
    return new \MessageFormatter($targetLocale, $pattern);
}

/**
 * Get a correctly configured IntlDateFormatter instance
 *
 * @param int|null $dateType Date format type (IntlDateFormatter::NONE, SHORT, MEDIUM, LONG, FULL)
 * @param int|null $timeType Time format type (IntlDateFormatter::NONE, SHORT, MEDIUM, LONG, FULL)
 * @param string|null $locale Override locale (uses mini\locale() if null)
 * @param string|null $timezone Timezone string (uses system default if null)
 * @param string|null $pattern Custom ICU pattern (overrides dateType/timeType if provided)
 * @return \IntlDateFormatter IntlDateFormatter instance configured for the locale
 */
function intlDateFormatter(?int $dateType = \IntlDateFormatter::MEDIUM, ?int $timeType = \IntlDateFormatter::SHORT, ?string $locale = null, ?string $timezone = null, ?string $pattern = null): \IntlDateFormatter {
    // Use provided locale or fall back to PHP's default locale
    $targetLocale = $locale ?? \Locale::getDefault();

    // Create IntlDateFormatter with proper locale
    $formatter = new \IntlDateFormatter($targetLocale, $dateType, $timeType, $timezone);

    if ($pattern !== null) {
        $formatter->setPattern($pattern);
    }

    return $formatter;
}

/**
 * Parse locale string into components
 *
 * @param string|null $locale Locale string (uses mini\locale() if null)
 * @return array Locale components (language, script, region, variants, keywords)
 */
function parseLocale(?string $locale = null): array {
    $targetLocale = $locale ?? \Locale::getDefault();
    return \Locale::parseLocale($targetLocale) ?: [];
}

/**
 * Get primary language from locale
 *
 * @param string|null $locale Locale string (uses Locale::getDefault() if null)
 * @return string Primary language code (e.g., 'en' from 'en_US')
 */
function localeLanguage(?string $locale = null): string {
    $targetLocale = $locale ?? \Locale::getDefault();
    return \Locale::getPrimaryLanguage($targetLocale) ?: 'en';
}

/**
 * Get region from locale
 *
 * @param string|null $locale Locale string (uses Locale::getDefault() if null)
 * @return string|null Region code (e.g., 'US' from 'en_US') or null if none
 */
function localeRegion(?string $locale = null): ?string {
    $targetLocale = $locale ?? \Locale::getDefault();
    $region = \Locale::getRegion($targetLocale);
    return $region ?: null;
}

/**
 * Canonicalize locale string
 *
 * @param string $locale Locale string to canonicalize
 * @return string Canonicalized locale string
 */
function canonicalizeLocale(string $locale): string {
    return \Locale::canonicalize($locale) ?: $locale;
}

/**
 * Get the path registries store
 *
 * @return CollectionInterface<string, Util\PathsRegistry> Collection of path registries
 */
function pathRegistries(): CollectionInterface {
    if (!isset($GLOBALS['app']['path_registries'])) {
        $GLOBALS['app']['path_registries'] = new Util\InstanceStore(Util\PathsRegistry::class);
    }
    return $GLOBALS['app']['path_registries'];
}

/**
 * Get a specific path registry
 *
 * @param string $type Registry type (config, templates, etc.)
 * @return Util\PathsRegistry|null Path registry instance or null if not found
 */
function paths(string $type): ?Util\PathsRegistry {
    return pathRegistries()->get($type);
}

/**
 * Get or cache a config value by loading it from paths('config')
 *
 * Implements lazy config loading with caching to avoid repeated file loads.
 * First checks $GLOBALS['app']['config'][$configKey], then loads from file if needed.
 *
 * WARNING: Only use for stateless configuration objects. Don't use for stateful
 * objects like PDO instances - use paths('config')->findFirst() for those.
 *
 * @param string $configKey Config key to cache in $GLOBALS['app']['config']
 * @param string $filename Config filename to search for in paths('config')
 * @return mixed The resolved config value or null if not found
 */
function getCachedConfig(string $configKey, string $filename) {
    // Check if already cached
    if (isset($GLOBALS['app']['config'][$configKey])) {
        return $GLOBALS['app']['config'][$configKey];
    }

    // Try to find and load config file
    $configFile = paths('config')->findFirst($filename);
    if ($configFile) {
        $GLOBALS['app']['config'][$configKey] = require $configFile;
        return $GLOBALS['app']['config'][$configKey];
    }

    return null;
}

/**
 * Bootstrap the mini framework application
 *
 * Sets up:
 * - Project root detection
 * - Configuration loading
 * - Global application state
 *
 * Note: Database connections, sessions, and other utilities are lazy-initialized
 * when first accessed via their respective global functions.
 *
 * @param array $options Reserved for future use (enables named arguments)
 * @param bool $disable_router Skip router initialization (useful for CLI, tests, etc.)
 */
function bootstrap(array $options = [], bool $disable_router = false): void
{
    // Detect project root from composer autoloader location
    $projectRoot = dirname((new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName(), 3);

    // Track where bootstrap was called for better error messages
    static $callSite = null;

    // Initialize global app state - prevent double bootstrap
    if (isset($GLOBALS['app'])) {
        $currentCall = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0];
        $currentSite = ($currentCall['file'] ?? 'unknown') . ':' . ($currentCall['line'] ?? 'unknown');
        throw new \Exception("Bootstrap already called - mini\\bootstrap() should only be called once. Previously called from $callSite, now called from $currentSite");
    }

    // Record where bootstrap was called
    $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0];
    $callSite = ($backtrace['file'] ?? 'unknown') . ':' . ($backtrace['line'] ?? 'unknown');

    $GLOBALS['app'] = [];

    // Safely remove as many pre-existing output handlers as possible
    // Stop when we can't remove any more (mandatory handlers)
    $previousLevel = -1;
    while (ob_get_level() > 0 && ob_get_level() !== $previousLevel) {
        $previousLevel = ob_get_level();
        @ob_end_clean(); // Suppress errors for mandatory handlers
    }

    // Store project root for framework components
    $GLOBALS['app']['root'] = $projectRoot;

    // Set up error handler that converts errors to exceptions
    set_error_handler(function($severity, $message, $file, $line) {
        // Don't convert suppressed errors (when @ is used)
        if (!(error_reporting() & $severity)) {
            return false;
        }

        // Convert error to exception for unified handling
        throw new \ErrorException($message, 0, $severity, $file, $line);
    });

    // Set up global exception handler for HTTP exceptions
    set_exception_handler(function(\Throwable $exception) use ($projectRoot) {
        // Always log the exception for debugging
        error_log("Uncaught exception: " . $exception->getMessage() . " in " . $exception->getFile() . " line " . $exception->getLine());
        error_log("Stack trace: " . $exception->getTraceAsString());

        if (headers_sent()) {
            // Headers already sent - output simple debug info
            if (defined('mini\\DEBUG') && DEBUG) {
                echo $exception;
            } else {
                echo get_class($exception) . " thrown in " . $exception->getFile() . " line " . $exception->getLine();
            }
            die();
        }

        // Headers not sent - clean current buffer content and render error page
        if (ob_get_level() > 0) {
            ob_clean();
        }

        if ($exception instanceof \mini\Http\AccessDeniedException) {
            // Handle access denied with proper 401/403 logic
            handleAccessDeniedException($exception, $projectRoot);
        } elseif ($exception instanceof \mini\Http\HttpException) {
            // Handle other HTTP exceptions
            handleHttpException($exception, $projectRoot);
        } else {
            // Generic error handling - try to show 500.php page
            try {
                showErrorPage(500, $exception, $projectRoot);
            } catch (\Throwable $e) {
                // Fallback if error page fails
                http_response_code(500);
                echo "<h1>Internal Server Error</h1>";
                echo "<p>An unexpected error occurred.</p>";
                if (ini_get('display_errors')) {
                    echo "<pre>" . htmlspecialchars($exception->getMessage()) . "</pre>";
                    echo "<hr><p>Error page also failed:</p>";
                    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
                }
            }
        }
    });

    // Initialize strategic output buffering for exception handling
    // Start our framework buffer with known configuration
    // 8192 bytes, flushable and cleanable for exception recovery
    $GLOBALS['app']['ob_started'] = ob_start(null, 8192,
        PHP_OUTPUT_HANDLER_FLUSHABLE | PHP_OUTPUT_HANDLER_CLEANABLE);

    // Initialize PSR structure for request/response objects
    $GLOBALS['app']['psr'] = $GLOBALS['app']['psr'] ?? [];

    // Handle clean URL redirects if router.php exists (unless router is disabled)
    if (!$disable_router && file_exists($projectRoot . '/router.php')) {
        // Use already-loaded config if available, otherwise load it
        if (isset($GLOBALS['app']['config'])) {
            $config = $GLOBALS['app']['config'];
        } else {
            $configPath = $projectRoot . '/config.php';
            $config = file_exists($configPath) ? require $configPath : [];
            $GLOBALS['app']['config'] = $config;
        }
        \mini\SimpleRouter::handleCleanUrlRedirects();
    }

    // Load configuration (if not already loaded by router)
    if (!isset($GLOBALS['app']['config'])) {
        $configPath = $projectRoot . '/config.php';
        if (!file_exists($configPath)) {
            throw new \Exception("Configuration file not found: $configPath");
        }
        $config = require $configPath;
        $GLOBALS['app']['config'] = $config;
    } else {
        $config = $GLOBALS['app']['config'];
    }

    // Define debug mode constant
    if (!defined('mini\DEBUG')) {
        define('mini\DEBUG', $config['debug'] ?? false);
    }

    // Initialize system locale using PHP's Locale class
    // 1. Use Accept-Language header first (user preference)
    $defaultLocale = null;
    if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
        $defaultLocale = \Locale::acceptFromHttp($_SERVER['HTTP_ACCEPT_LANGUAGE']);
    }

    // 2. Fall back to configured default_language
    if (!$defaultLocale) {
        $defaultLocale = $config['default_language'] ?? null;
    }

    // 3. Fall back to system default
    if (!$defaultLocale) {
        $defaultLocale = ini_get('intl.default_locale') ?: 'en_US.UTF-8';
    }

    // Canonicalize and set as PHP's default locale
    $canonicalized = \Locale::canonicalize($defaultLocale);
    \Locale::setDefault($canonicalized);

    // Set system locale for date/time functions (best effort)
    $localeVariants = [
        $canonicalized . '.UTF-8',
        $canonicalized . '.utf8',
        $canonicalized,
        substr($canonicalized, 0, 2) . '_' . strtoupper(substr($canonicalized, 0, 2)) . '.UTF-8',
        substr($canonicalized, 0, 2) . '.UTF-8'
    ];

    foreach ($localeVariants as $variant) {
        if (setlocale(LC_TIME, $variant) !== false) {
            break;
        }
    }

    // Initialize core path registries
    $configPaths = new Util\PathsRegistry($projectRoot . '/config'); // App config first
    $configPaths->addPath(dirname(__FILE__) . '/config'); // Framework fallback
    pathRegistries()->set('config', $configPaths);

    // All other framework components (db, translator, fmt, cache, etc.)
    // will be lazy-initialized when first accessed via their respective functions

    // Include project-specific bootstrap if it exists
    $projectBootstrap = $projectRoot . '/config/bootstrap.php';
    if (file_exists($projectBootstrap)) {
        require_once $projectBootstrap;
    }
}

/**
 * Router entry point for pretty URLs
 *
 * Minimal bootstrap that delegates all routing logic to SimpleRouter.
 * Called by router.php when a file doesn't exist.
 */
function router(): void
{
    $projectRoot = $GLOBALS['app']['root'] ?? dirname((new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName(), 3);
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';

    // Don't initialize $GLOBALS['app'] - that's bootstrap's job
    // The included page will call bootstrap() and set up everything properly

    // Delegate everything to SimpleRouter
    $router = new \mini\SimpleRouter();
    $router->handleRequest($requestUri);
}

function request(): \Psr\Http\Message\ServerRequestInterface
{
    // Validate bootstrap was called
    if (!isset($GLOBALS['app'])) {
        throw new \RuntimeException('mini\bootstrap() must be called before mini\request()');
    }

    // Return cached instance if exists
    if (isset($GLOBALS['app']['psr']['request'])) {
        return $GLOBALS['app']['psr']['request'];
    }

    // Create and cache PSR-7 request
    $psr17Factory = new \Nyholm\Psr7\Factory\Psr17Factory();

    $creator = new \Nyholm\Psr7Server\ServerRequestCreator(
        $psr17Factory,
        $psr17Factory,
        $psr17Factory,
        $psr17Factory
    );

    $request = $creator->fromGlobals();

    $GLOBALS['app']['psr']['request'] = $request;
    return $request;
}

function response(): \Psr\Http\Message\ResponseInterface
{
    $psr17Factory = new \Nyholm\Psr7\Factory\Psr17Factory();
    return $psr17Factory->createResponse(200);
}

function json_response(int|float|bool|string|null|\JsonSerializable|array $value, int $status = 200): \Psr\Http\Message\ResponseInterface
{
    return response()
        ->withStatus($status)
        ->withHeader('Content-Type', 'application/json')
        ->withBody(\Nyholm\Psr7\Stream::create(json_encode($value)));
}

function html_response(string $html, int $status = 200): \Psr\Http\Message\ResponseInterface
{
    return response()
        ->withStatus($status)
        ->withHeader('Content-Type', 'text/html; charset=utf-8')
        ->withBody(\Nyholm\Psr7\Stream::create($html));
}

/**
 * Save a model (automatically detects insert vs update)
 *
 * @throws ValidationException If validation fails
 * @throws AccessDeniedException If access is denied
 */
function model_save(object $model): bool
{
    return table(get_class($model))->saveModel($model);
}

/**
 * Delete a model
 *
 * @throws AccessDeniedException If access is denied
 */
function model_delete(object $model): bool
{
    return table(get_class($model))->deleteModel($model);
}

/**
 * Check if a model has unsaved changes
 */
function model_dirty(object $model): bool
{
    return table(get_class($model))->isDirty($model);
}

/**
 * Validate a model without saving
 *
 * @return array<string, Translatable>|null Returns null if valid, errors array if invalid
 */
function model_invalid(object $model): ?array
{
    return table(get_class($model))->validateModel($model);
}

/**
 * Get the global codec registry for type-specific codec registration
 *
 * @return \mini\Util\InstanceStore
 */
function codecs(): \mini\Util\InstanceStore
{
    if (!isset($GLOBALS['app']['codecs'])) {
        $GLOBALS['app']['codecs'] = new \mini\Util\InstanceStore(CodecInterface::class);
        registerBuiltinCodecs();
    }
    return $GLOBALS['app']['codecs'];
}

/**
 * Register built-in codec types
 *
 * Called automatically during framework initialization to provide
 * out-of-the-box support for common PHP types.
 */
function registerBuiltinCodecs(): void
{
    static $registered = false;
    if ($registered) return;
    $registered = true;

    // DateTime support - handles both string and integer backends
    codecs()->set(\DateTime::class, new class
        implements \mini\Codecs\StringCodecInterface, \mini\Codecs\IntegerCodecInterface {

        public function fromBackendString(string $value): \DateTime
        {
            return new \DateTime($value);
        }

        public function toBackendString(mixed $value): string
        {
            return $value->format('Y-m-d H:i:s');
        }

        public function fromBackendInteger(int $value): \DateTime
        {
            $dt = new \DateTime();
            $dt->setTimestamp($value);
            return $dt;
        }

        public function toBackendInteger(mixed $value): int
        {
            return $value->getTimestamp();
        }
    });

    // DateTimeImmutable support - handles both string and integer backends
    codecs()->set(\DateTimeImmutable::class, new class
        implements \mini\Codecs\StringCodecInterface, \mini\Codecs\IntegerCodecInterface {

        public function fromBackendString(string $value): \DateTimeImmutable
        {
            return new \DateTimeImmutable($value);
        }

        public function toBackendString(mixed $value): string
        {
            return $value->format('Y-m-d H:i:s');
        }

        public function fromBackendInteger(int $value): \DateTimeImmutable
        {
            $dt = new \DateTimeImmutable();
            return $dt->setTimestamp($value);
        }

        public function toBackendInteger(mixed $value): int
        {
            return $value->getTimestamp();
        }
    });
}

/**
 * Setup authentication system with application implementation
 *
 * Applications should call this during bootstrap to register their
 * AuthInterface implementation with the framework.
 */
function setupAuth(\mini\AuthInterface $auth): void
{
    \mini\Auth::setImplementation($auth);
}

/**
 * Get the auth facade instance
 *
 * Provides access to authentication methods like requireLogin(), hasRole(), etc.
 * Returns null if no auth system is registered.
 */
function auth(): ?\mini\Auth
{
    try {
        return \mini\Auth::isAuthenticated() !== null ? new \mini\Auth() : null;
    } catch (\RuntimeException) {
        return null;
    }
}

/**
 * Check if user is currently authenticated
 */
function is_logged_in(): bool
{
    return \mini\Auth::isAuthenticated();
}

/**
 * Require user to be logged in, redirect to login if not
 */
function require_login(): void
{
    \mini\Auth::requireLogin();
}

/**
 * Require user to have a specific role
 *
 * @throws \mini\Http\AccessDeniedException If user doesn't have the required role
 */
function require_role(string $role): void
{
    \mini\Auth::requireRole($role);
}

/**
 * Handle AccessDeniedException with proper 401/403 logic
 */
function handleAccessDeniedException(\mini\Http\AccessDeniedException $exception, string $projectRoot): void
{
    // Determine correct HTTP status based on authentication state
    if (\mini\Auth::hasImplementation()) {
        if (\mini\Auth::isAuthenticated()) {
            // User is authenticated but lacks permission → 403 Forbidden
            showErrorPage(403, $exception, $projectRoot);
        } else {
            // User is not authenticated → 401 Unauthorized
            showErrorPage(401, $exception, $projectRoot);
        }
    } else {
        // No auth system registered - default to 401 (needs authentication)
        showErrorPage(401, $exception, $projectRoot);
    }
}

/**
 * Handle other HTTP exceptions
 */
function handleHttpException(\mini\Http\HttpException $exception, string $projectRoot): void
{
    showErrorPage($exception->getStatusCode(), $exception, $projectRoot);
}

/**
 * Show error page with fallback logic
 */
function showErrorPage(int $statusCode, \Throwable $exception, string $projectRoot): void
{
    http_response_code($statusCode);

    $errorFile = $projectRoot . "/_errors/{$statusCode}.php";

    // If the specific error page doesn't exist, try fallbacks for auth errors
    if (!file_exists($errorFile)) {
        if ($statusCode === 401 && file_exists($projectRoot . "/_errors/403.php")) {
            $errorFile = $projectRoot . "/_errors/403.php";
        } elseif ($statusCode === 403 && file_exists($projectRoot . "/_errors/401.php")) {
            $errorFile = $projectRoot . "/_errors/401.php";
        }
    }

    if (file_exists($errorFile)) {
        // Make exception available to error page
        $httpException = $exception;
        require $errorFile;
    } else {
        // Fallback error page
        $statusText = getHttpStatusText($statusCode);
        echo "<!DOCTYPE html>
<html lang=\"en\">
<head>
    <meta charset=\"UTF-8\">
    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
    <title>{$statusCode} - {$statusText}</title>
</head>
<body>
    <h1>{$statusCode} - {$statusText}</h1>
    <p>" . htmlspecialchars($exception->getMessage()) . "</p>
</body>
</html>";
    }
}

/**
 * Get HTTP status text for status code
 */
function getHttpStatusText(int $statusCode): string
{
    return match($statusCode) {
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        500 => 'Internal Server Error',
        default => 'Error'
    };
}

