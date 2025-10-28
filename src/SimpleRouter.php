<?php

namespace mini;

class SimpleRouter
{
    private array $routes;
    private string $scope;

    public function __construct(array $routes = [], string $scope = '')
    {
        $this->routes = $routes;
        $this->scope = $scope;
    }

    /**
     * Handle a complete routing request
     * Searches _routes/ directory for route handlers
     */
    public function handleRequest(string $requestUri): void
    {
        $path = parse_url($requestUri, PHP_URL_PATH) ?? '';
        $baseUrl = Mini::$mini->baseUrl ?? '';

        // 1. Try file-based routing in _routes/ directory
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
            $result = require $routeInfo['file'];

            // Handle routes array
            if (is_array($result)) {
                // Include base URL prefix in the basePath for proper pattern matching
                $basePath = '';
                if (!empty($baseUrl)) {
                    $basePath = parse_url($baseUrl, PHP_URL_PATH) ?? '';
                }
                $fullBasePath = $basePath . $routeInfo['basePath'];

                $this->routes = $this->processRoutesWithScope($result, $routeInfo['scope'], $fullBasePath);
                $this->scope = $routeInfo['scope'];

                if ($this->resolve($requestUri)) {
                    return;
                }
            }
        }

        // 3. Try global config/routes.php with stripped path (in project root - outside web root)
        $globalRoutesFile = Mini::$mini->root . '/config/routes.php';
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
        $this->handle404();

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

        // Find route file in _routes/ directory via PathsRegistry
        $routeFile = ltrim($file, '/');
        $fullPath = Mini::$mini->paths->routes->findFirst($routeFile);

        if (!$fullPath) {
            http_response_code(500);
            echo "Internal Server Error: Route target not found: $routeFile";
            return;
        }

        // Execute controller with exception handling
        try {
            // Include the target file (it will echo its output)
            require $fullPath;
        } catch (\Throwable $e) {
            // Re-throw for global exception handler
            throw $e;
        }
    }

    private function executeCallableTarget(callable $target): void
    {
        try {
            // Call the target function/closure
            $result = call_user_func($target);

            // If callable returns a string, treat it as a redirect target
            if (is_string($result)) {
                $this->executeStringTarget($result);
            }
            // Otherwise, assume callable echoed its output directly

        } catch (\Throwable $e) {
            // Re-throw for global exception handler
            throw $e;
        }
    }

    private function executeArrayTarget(array $target): void
    {
        // Future extension for complex routing (controllers, middleware, etc.)
        throw new \RuntimeException('Array route targets not yet implemented');
    }


    private function redirectTo(string $path): void
    {
        $redirectUrl = $path;
        if (!empty($_SERVER['QUERY_STRING'])) {
            $redirectUrl .= '?' . $_SERVER['QUERY_STRING'];
        }

        http_response_code(301);
        header('Location: ' . $redirectUrl);
        exit;
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

        // Try to find route handler in _routes/ via PathsRegistry
        // / → _routes/index.php
        // /users → _routes/users.php
        // /api/posts → _routes/api/posts.php

        $routeFile = empty($remaining) ? 'index.php' : $remaining . '.php';
        $foundPath = Mini::$mini->paths->routes->findFirst($routeFile);

        if ($foundPath) {
            // Return path relative to routes directory for executeTarget()
            return $routeFile;
        }

        // Try directory index: /users → _routes/users/index.php
        if (!empty($remaining)) {
            $indexFile = $remaining . '/index.php';
            $foundPath = Mini::$mini->paths->routes->findFirst($indexFile);

            if ($foundPath) {
                // Redirect to /path/ to ensure consistent URLs
                return '/' . $remaining . '/';
            }
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

        // Check from most specific to least specific directory in _routes/
        while (!empty($pathParts)) {
            $dirPath = implode('/', $pathParts);
            $routeFile = $dirPath . '/_routes.php';

            // Try to find in _routes/ via PathsRegistry
            $foundPath = Mini::$mini->paths->routes->findFirst($routeFile);

            if ($foundPath) {
                return [
                    'file' => $foundPath,
                    'scope' => $dirPath,
                    'basePath' => '/' . $dirPath
                ];
            }

            array_pop($pathParts);
        }

        // No scoped routes found, fall back to global routes file
        $globalFile = Mini::$mini->root . '/_config/routes.php';
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

    private function resolveTargetFile(string $target): string
    {
        // Parse target URL and extract file path
        $parts = parse_url($target);
        $file = $parts['path'] ?? $target;

        // Resolve relative paths within scope
        if (!empty($this->scope) && !str_starts_with($file, '/')) {
            $file = $this->scope . '/' . $file;
        }

        // Remove leading slash for PathsRegistry lookup
        $file = ltrim($file, '/');

        // Try to find in _routes/ via PathsRegistry
        $foundPath = Mini::$mini->paths->routes->findFirst($file);

        if ($foundPath) {
            return $foundPath;
        }

        // Fallback: return as-is (for backwards compatibility or error handling)
        return Mini::$mini->root . '/_routes/' . $file;
    }

    private function handle404(): void
    {
        // Use centralized error page handling
        $exception = new \mini\Http\HttpException('Page not found', 404);
        \mini\showErrorPage(404, $exception);
    }

}
