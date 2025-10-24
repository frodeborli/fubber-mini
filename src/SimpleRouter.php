<?php

namespace mini;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

class SimpleRouter
{
    private array $routes;
    private string $scope;
    private string $projectRoot;
    private array $config;

    public function __construct(array $routes = [], string $scope = '')
    {
        $this->routes = $routes;
        $this->scope = $scope;
    }

    /**
     * Handle a complete routing request with file-based and hierarchical routing
     */
    public function handleRequest(string $requestUri): void
    {
        $this->projectRoot = $GLOBALS['app']['root'] ?? dirname((new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName(), 3);

        // Load config if not already loaded by bootstrap
        if (isset($GLOBALS['app']['config'])) {
            $this->config = $GLOBALS['app']['config'];
        } else {
            $configPath = $this->projectRoot . '/config.php';
            $this->config = file_exists($configPath) ? require $configPath : [];
        }

        $path = parse_url($requestUri, PHP_URL_PATH) ?? '';
        $baseUrl = $this->config['base_url'] ?? '';

        // 1. Try automatic file-based routing first
        $target = $this->tryFileBasedRouting($path, $baseUrl);
        if ($target) {
            $this->routes = [$path => $target];
            $this->scope = '';
            $this->resolve($path);
            return;
        }

        // 2. Try hierarchical _routes.php files
        $strippedPath = $this->stripBasePath($path, $baseUrl);
        $routeInfo = $this->findScopedRouteFile($strippedPath);

        if ($routeInfo) {
            $routes = require $routeInfo['file'];
            // Include base URL prefix in the basePath for proper pattern matching
            $basePath = '';
            if (!empty($baseUrl)) {
                $basePath = parse_url($baseUrl, PHP_URL_PATH) ?? '';
            }
            $fullBasePath = $basePath . $routeInfo['basePath'];

            $this->routes = $this->processRoutesWithScope($routes, $routeInfo['scope'], $fullBasePath);
            $this->scope = $routeInfo['scope'];

            if ($this->resolve($requestUri)) {
                return;
            }
        }

        // 3. Try global config/routes.php with stripped path
        $globalRoutesFile = $this->projectRoot . '/config/routes.php';
        if (file_exists($globalRoutesFile)) {
            $globalRoutes = require $globalRoutesFile;
            $this->routes = $globalRoutes;
            $this->scope = '';

            if ($this->resolve($requestUri)) {
                return;
            }
        }

        // 4. 404 - nothing found
        $this->handle404();
    }

    public function resolve(string $requestUri): bool
    {
        $path = parse_url($requestUri, PHP_URL_PATH);

        // 1. Try exact match
        if ($target = $this->findMatch($path)) {
            $this->executeTarget($target);
            return true;
        }

        // 2. Try with trailing slash (common case)
        if (!str_ends_with($path, '/')) {
            if ($target = $this->findMatch($path . '/')) {
                $this->redirectTo($path . '/');
                return true;
            }
        }

        // 3. Try without trailing slash (less common)
        if (str_ends_with($path, '/') && $path !== '/') {
            $pathWithoutSlash = rtrim($path, '/');
            if ($target = $this->findMatch($pathWithoutSlash)) {
                $this->redirectTo($pathWithoutSlash);
                return true;
            }
        }

        // 4. Handle 404
        http_response_code(404);

        // Get project root from global state
        $projectRoot = $GLOBALS['app']['root'] ?? dirname((new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName(), 3);

        if (file_exists($projectRoot . '/404.php')) {
            require $projectRoot . '/404.php';
        } else {
            // Basic fallback 404 page
            echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 - Page Not Found</title>
</head>
<body>
    <h1>404 - Page Not Found</h1>
    <p>The requested page could not be found.</p>
</body>
</html>';
        }

        return true;
    }

    private function findMatch(string $path): string|false
    {
        // Check routes in declared order - important!
        foreach ($this->routes as $pattern => $handler) {
            $regex = $this->compilePattern($pattern);

            if (preg_match($regex, $path, $matches)) {
                $namedMatches = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                $target = $this->dispatchHandler($handler, $namedMatches);

                if ($target === false) {
                    // Handler returned false (404), but pattern matched
                    return false;
                }

                return $target;
            }
        }

        return false;
    }

    private function compilePattern(string $pattern): string
    {
        // Convert FastRoute-inspired patterns to regex with named capture groups
        // {param} becomes (?<param>[^/]+)
        // {param:\d+} becomes (?<param>\d+)
        $pattern = preg_replace_callback(
            '/\{(\w+)(?::([^}]+))?\}/',
            fn($matches) => "(?<{$matches[1]}>" . ($matches[2] ?? '[^/]+') . ")",
            $pattern
        );

        return "#^{$pattern}$#";
    }

    private function dispatchHandler(callable|string $handler, array $matches): string|false
    {
        // Handle string targets directly (for file-based routing)
        if (is_string($handler)) {
            return $handler;
        }

        // Handle callable handlers (for dynamic routing)
        $reflection = new \ReflectionFunction($handler);
        $params = [];

        foreach ($reflection->getParameters() as $param) {
            $name = $param->getName();
            $value = $matches[$name] ?? null;

            // Type casting based on parameter type hints
            if ($value !== null && $param->hasType()) {
                $type = $param->getType();
                if (!$type instanceof \ReflectionUnionType) {
                    $typeName = $type->getName();
                    $value = match($typeName) {
                        'int' => (int)$value,
                        'float' => (float)$value,
                        'bool' => (bool)$value,
                        default => $value
                    };
                }
            }

            $params[] = $value;
        }

        return $handler(...$params);
    }

    private function executeTarget($target): void
    {
        if (is_string($target)) {
            $this->executeStringTarget($target);
        } elseif (is_callable($target)) {
            $this->executeCallableTarget($target);
        } elseif (is_array($target)) {
            $this->executeArrayTarget($target);
        } elseif ($target instanceof RequestHandlerInterface) {
            $this->executeRequestHandler($target);
        } else {
            throw new \InvalidArgumentException('Invalid route target type: ' . gettype($target));
        }
    }

    private function executeStringTarget(string $target): void
    {
        // Parse target URL and populate $_GET
        $parts = parse_url($target);
        $file = $parts['path'];

        if (isset($parts['query'])) {
            parse_str($parts['query'], $queryParams);
            $_GET = array_merge($_GET, $queryParams);
        }

        // Resolve relative paths within scope
        if (!empty($this->scope) && !str_starts_with($file, '/')) {
            $file = $this->scope . '/' . $file;
        }

        // Get project root for file resolution
        $projectRoot = $GLOBALS['app']['root'] ?? dirname((new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName(), 3);

        // Include the target file with proper error handling
        $fullPath = $projectRoot . '/' . ltrim($file, '/');
        if (!file_exists($fullPath)) {
            http_response_code(500);
            echo "Internal Server Error: Target file not found: $fullPath";
            return;
        }

        // Execute controller with exception handling and buffer management
        try {
            // Include the target file and capture return value
            $result = require $fullPath;

            // Handle PSR-7 response from included file
            if ($result instanceof ResponseInterface) {
                // Check if headers have already been sent
                if (headers_sent($file, $line)) {
                    throw new \RuntimeException(
                        "Cannot send PSR-7 response: headers already sent in $file on line $line. " .
                        "Controllers returning ResponseInterface must not produce any output before returning."
                    );
                }

                // Check for unwanted output in buffer before cleaning
                $this->validateCleanPsr7Response($fullPath);

                // Clean any output the controller may have produced
                \mini\cleanGlobalControllerOutput();
                $this->outputPsr7Response($result);
            }
            // For traditional approach (no return value), output has already been sent directly

        } catch (\Throwable $e) {
            // Attempt exception recovery using output buffer management
            $this->handleControllerException($e, $fullPath);
        }
    }

    private function executeCallableTarget(callable $target): void
    {
        try {
            // Call the target function/closure
            $result = call_user_func($target);

            // Handle different return types
            if (is_string($result)) {
                $this->executeStringTarget($result);
            } elseif ($result instanceof ResponseInterface) {
                // Check headers and validate clean response
                if (headers_sent($file, $line)) {
                    throw new \RuntimeException(
                        "Cannot send PSR-7 response: headers already sent in $file on line $line"
                    );
                }

                // Check for unwanted output before cleaning
                $this->validateCleanPsr7Response('callable target');
                \mini\cleanGlobalControllerOutput();
                $this->outputPsr7Response($result);
            } elseif ($result instanceof RequestHandlerInterface) {
                $this->executeRequestHandler($result);
            }

        } catch (\Throwable $e) {
            // Handle callable target exceptions
            $this->handleControllerException($e, 'callable target');
        }
    }

    private function executeArrayTarget(array $target): void
    {
        // Future extension for complex routing (controllers, middleware, etc.)
        throw new \RuntimeException('Array route targets not yet implemented');
    }

    private function executeRequestHandler(RequestHandlerInterface $handler): void
    {
        $request = \mini\request();
        $response = $handler->handle($request);
        $this->outputPsr7Response($response);
    }

    private function outputPsr7Response(ResponseInterface $response): void
    {
        // Set HTTP status code
        http_response_code($response->getStatusCode());

        // Set headers
        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                header($name . ': ' . $value, false);
            }
        }

        // Output body
        echo $response->getBody();
    }

    private function redirectTo(string $path): void
    {
        $redirectUrl = $path;
        if (!empty($_SERVER['QUERY_STRING'])) {
            $redirectUrl .= '?' . $_SERVER['QUERY_STRING'];
        }

        header('Location: ' . $redirectUrl, true, 301);
    }

    // === Helper methods moved from functions.php ===

    private function tryFileBasedRouting(string $path, string $baseUrl): ?string
    {
        // Remove base_url path from the request path
        if (!empty($baseUrl)) {
            $basePath = parse_url($baseUrl, PHP_URL_PATH) ?? '';
            if (!empty($basePath) && str_starts_with($path, $basePath)) {
                $path = substr($path, strlen($basePath));
            }
        }

        // Remove leading slash
        $remaining = ltrim($path, '/');

        // Handle root path
        if (empty($remaining)) {
            if (file_exists($this->projectRoot . '/index.php')) {
                return '/index.php';
            }
            return null;
        }

        // Try /path.php for /path requests
        $phpFile = $remaining . '.php';
        if (file_exists($this->projectRoot . '/' . $phpFile)) {
            return '/' . $phpFile;
        }

        // Try /path/index.php for /path requests
        $indexFile = $remaining . '/index.php';
        if (file_exists($this->projectRoot . '/' . $indexFile)) {
            // Redirect to /path/ to ensure consistent URLs
            return '/' . $remaining . '/';
        }

        return null;
    }

    private function stripBasePath(string $path, string $baseUrl): string
    {
        if (empty($baseUrl)) {
            return $path;
        }

        $basePath = parse_url($baseUrl, PHP_URL_PATH) ?? '';
        if (!empty($basePath) && str_starts_with($path, $basePath)) {
            $stripped = substr($path, strlen($basePath));
            return $stripped ?: '/';
        }

        return $path;
    }

    private function findScopedRouteFile(string $requestPath): ?array
    {
        // Strip leading slash and split path
        $pathParts = explode('/', trim($requestPath, '/'));
        $pathParts = array_filter($pathParts, fn($part) => $part !== '');

        // Check from most specific to least specific directory
        while (!empty($pathParts)) {
            $dirPath = implode('/', $pathParts);
            $routeFile = $this->projectRoot . '/' . $dirPath . '/_routes.php';

            if (file_exists($routeFile)) {
                return [
                    'file' => $routeFile,
                    'scope' => $dirPath,
                    'basePath' => '/' . $dirPath
                ];
            }

            array_pop($pathParts);
        }

        // No scoped routes found, fall back to global config
        $globalFile = $this->projectRoot . '/config/routes.php';
        if (file_exists($globalFile)) {
            return [
                'file' => $globalFile,
                'scope' => '',
                'basePath' => ''
            ];
        }

        return null;
    }

    private function processRoutesWithScope(array $routes, string $scope, string $basePath): array
    {
        $processedRoutes = [];

        foreach ($routes as $pattern => $target) {
            // Prefix pattern with base path for scoped routes
            if ($scope !== '') {
                // Transform relative patterns: '/' -> '/admin/users/', '/edit' -> '/admin/users/edit'
                if ($pattern === '/') {
                    $fullPattern = $basePath . '/';
                } elseif (str_starts_with($pattern, '/')) {
                    $fullPattern = $basePath . $pattern;
                } else {
                    $fullPattern = $basePath . '/' . $pattern;
                }
            } else {
                $fullPattern = $pattern;
            }

            // Note: Target transformation is now handled by SimpleRouter internally
            // No need to transform targets here since router has scope awareness

            $processedRoutes[$fullPattern] = $target;
        }

        return $processedRoutes;
    }

    private function handle404(): void
    {
        http_response_code(404);

        if (file_exists($this->projectRoot . '/404.php')) {
            require $this->projectRoot . '/404.php';
        } else {
            // Basic fallback 404 page (this is the same as the existing one)
            echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 - Page Not Found</title>
</head>
<body>
    <h1>404 - Page Not Found</h1>
    <p>The requested page could not be found.</p>
</body>
</html>';
        }
    }

    /**
     * Static method to handle clean URL redirects (called from bootstrap)
     */
    public static function handleCleanUrlRedirects(): void
    {
        $projectRoot = $GLOBALS['app']['root'] ?? dirname((new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName(), 3);
        $config = $GLOBALS['app']['config'] ?? [];

        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $path = parse_url($requestUri, PHP_URL_PATH) ?? '';
        $query = $_SERVER['QUERY_STRING'] ?? '';
        $baseUrl = $config['base_url'] ?? '';

        $redirectTo = null;

        // Parse base path from base_url for accurate redirection
        $basePath = '';
        if (!empty($baseUrl)) {
            $basePath = parse_url($baseUrl, PHP_URL_PATH) ?? '';
        }

        // Strip base path for processing, then add back for redirects
        $strippedPath = $path;
        if (!empty($basePath) && str_starts_with($path, $basePath)) {
            $strippedPath = substr($path, strlen($basePath)) ?: '/';
        }

        // Handle PHP extension removal: /login.php → /login (if not index.php)
        if (preg_match('/^(.+)\.php$/', $strippedPath, $matches) && !str_ends_with($strippedPath, '/index.php')) {
            $redirectTo = $basePath . $matches[1];
        }
        // Handle unnecessary trailing slash: /test-routing/ → /test-routing (if test-routing.php exists but test-routing/index.php doesn't)
        elseif (str_ends_with($strippedPath, '/') && $strippedPath !== '/') {
            $pathWithoutSlash = rtrim($strippedPath, '/');
            $phpFile = ltrim($pathWithoutSlash, '/') . '.php';
            $indexFile = ltrim($strippedPath, '/') . 'index.php';

            // If there's a .php file but no index.php, redirect to remove trailing slash
            if (file_exists($projectRoot . '/' . $phpFile) && !file_exists($projectRoot . '/' . $indexFile)) {
                $redirectTo = $basePath . $pathWithoutSlash;
            }
        }

        // Perform redirect if needed
        if ($redirectTo !== null) {
            $redirectUrl = $redirectTo;
            if ($query) {
                $redirectUrl .= '?' . $query;
            }

            // Send 301 permanent redirect
            header('Location: ' . $redirectUrl, true, 301);
            exit;
        }
    }

    /**
     * Validate that controller hasn't produced unwanted output before PSR-7 response
     *
     * @param string $controllerPath Path to the controller for error context
     * @throws \RuntimeException When controller produced output before returning PSR-7 response
     */
    private function validateCleanPsr7Response(string $controllerPath): void
    {
        // Check for output in any active buffers
        $totalOutput = 0;
        $outputDetails = [];

        // Check all buffer levels for content
        for ($level = 1; $level <= ob_get_level(); $level++) {
            $content = ob_get_contents();
            if ($content !== false && strlen($content) > 0) {
                $totalOutput += strlen($content);
                $outputDetails[] = "Level $level: " . strlen($content) . " bytes";
            }
        }

        // If any output was detected, throw strict validation error
        if ($totalOutput > 0) {
            $errorMessage = "Controller returned PSR-7 response but produced output ($totalOutput bytes total). " .
                "Controllers returning ResponseInterface must not produce any output. " .
                "Remove all echo, print, var_dump, or other output statements.\n" .
                "Controller: $controllerPath\n" .
                "Output detected: " . implode(', ', $outputDetails);

            throw new \RuntimeException($errorMessage);
        }
    }

    /**
     * Handle controller exceptions with buffer-aware recovery
     *
     * @param \Throwable $exception The exception thrown by the controller
     * @param string $controllerPath Path to the controller that threw the exception
     */
    private function handleControllerException(\Throwable $exception, string $controllerPath): void
    {
        // Headers sent check and logging will be handled by global exception handler
        // Just re-throw and let global handler deal with everything
        throw $exception;
    }

}