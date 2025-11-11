<?php

namespace mini\Router;

use mini\Mini;
use mini\Http\ResponseAlreadySentException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Simple file-based router for Mini framework
 *
 * Maps URLs to route handler files in _routes/ directory:
 * - /users → _routes/users.php
 * - /api/posts → _routes/api/posts.php
 * - / → _routes/index.php
 *
 * The Router:
 * 1. Resolves request path to controller file
 * 2. Annotates request with route info
 * 3. Includes controller file
 * 4. Handles controller return value:
 *    - null → throw ResponseAlreadySentException (classical PHP)
 *    - ResponseInterface → return it
 *    - Other → convert to ResponseInterface
 *
 * Controllers can:
 * - Use classical PHP (echo/header) and return nothing
 * - Return a ResponseInterface
 * - Return any value that can be converted to ResponseInterface
 *
 * Example controllers:
 * ```php
 * // _routes/ping.php - Return string (converted to response)
 * <?php
 * return "pong";
 *
 * // _routes/api/users.php - Return array (converted to JSON response)
 * <?php
 * return ['users' => db()->query("SELECT * FROM users")->fetchAll()];
 *
 * // _routes/legacy.php - Classical PHP
 * <?php
 * header('Content-Type: text/html');
 * echo render('legacy-page');
 * // Returns nothing - throws ResponseAlreadySentException
 * ```
 */
class Router implements RequestHandlerInterface
{
    /** Maximum number of internal redirects before failing */
    private const MAX_REDIRECTS = 20;

    /**
     * Handle an HTTP request and return a response
     *
     * PSR-15 RequestHandlerInterface implementation.
     *
     * Supports internal routing via exceptions:
     * - Redirect: Simple path redirect (relative/absolute)
     * - Reroute: Pattern-based routing in __DEFAULT__.php
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     * @throws ResponseAlreadySentException If controller uses classical PHP output
     * @throws \Throwable Any exception thrown by controller
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // Populate $_GET and $_POST from request
        $this->populateRequestglobals($request);

        // Iterative route resolution loop
        while (true) {
            try {
                // 1. Resolve request path to controller file
                $requestTarget = $request->getRequestTarget();
                $path = parse_url($requestTarget, PHP_URL_PATH) ?? '/';

                // Check if this is an internal redirect (allows underscore-prefixed paths)
                $redirectCount = $request->getAttribute('mini.router.redirectCount', 0);
                $allowUnderscore = $redirectCount > 0;
                $handlerFile = $this->resolveHandlerFile($path, $allowUnderscore);

                if ($handlerFile === null) {
                    throw new \mini\Http\NotFoundException("Not Found: $path");
                }

                // 2. Annotate request with route info
                $request = $request->withAttribute('mini.router.handlerFile', $handlerFile);

                // 3. Replace global request instance
                $this->replaceGlobalRequest($request);

                // 4. Include controller file and get return value
                $returnValue = require $handlerFile;

                // 5. Handle null return (classical PHP)
                if ($returnValue === null) {
                    throw new ResponseAlreadySentException();
                }

                // 6. Check if return value is already a response
                if ($returnValue instanceof ResponseInterface) {
                    return $returnValue;
                }

                // 7. Convert to response
                $response = \mini\convert($returnValue, ResponseInterface::class);

                if ($response === null) {
                    throw new \RuntimeException(
                        'Controller returned ' . get_debug_type($returnValue) .
                        ' but no converter is registered to convert it to ResponseInterface'
                    );
                }

                return $response;

            } catch (Redirect $redirect) {
                // Increment redirect count and check limit
                $redirectCount = 1 + $request->getAttribute('mini.router.redirectCount', 0);
                if ($redirectCount > self::MAX_REDIRECTS) {
                    throw new \RuntimeException(
                        'Too many redirects (limit: ' . self::MAX_REDIRECTS . '). ' .
                        'Possible infinite redirect loop. Last target: ' . $redirect->target
                    );
                }

                // Handle Redirect: resolve target path and restart routing
                $request = $this->handleRedirect($request, $handlerFile ?? null, $redirect->target);
                $request = $request->withAttribute('mini.router.redirectCount', $redirectCount);
                continue;

            } catch (Reroute $reroute) {
                // Increment redirect count and check limit (reroute counts as redirect)
                $redirectCount = 1 + $request->getAttribute('mini.router.redirectCount', 0);
                if ($redirectCount > self::MAX_REDIRECTS) {
                    throw new \RuntimeException(
                        'Too many redirects (limit: ' . self::MAX_REDIRECTS . '). ' .
                        'Possible infinite redirect loop in Reroute patterns.'
                    );
                }

                // Handle Reroute: match patterns and restart routing
                $request = $this->handleReroute($request, $handlerFile ?? null, $reroute->routes);
                $request = $request->withAttribute('mini.router.redirectCount', $redirectCount);
                continue;
            }
        }
    }

    /**
     * Resolve request path to controller file
     *
     * Tries multiple candidate files in order of specificity:
     * - /path → ["_routes/path.php", "_routes/__DEFAULT__.php"]
     * - /path/ → ["_routes/path/index.php", "_routes/path/__DEFAULT__.php", "_routes/__DEFAULT__.php"]
     * - /users/123/ → ["_routes/users/123/index.php", "_routes/users/123/__DEFAULT__.php", "_routes/users/__DEFAULT__.php", "_routes/__DEFAULT__.php"]
     *
     * Security:
     * - Client requests: Path components starting with underscore are blocked (except via __DEFAULT__.php)
     * - Internal redirects: Underscore paths allowed (developer-controlled)
     *
     * @param string $path Request path (without query string)
     * @param bool $allowUnderscore Whether to allow underscore-prefixed paths
     * @return string|null Absolute path to controller file, or null if not found
     */
    private function resolveHandlerFile(string $path, bool $allowUnderscore = false): ?string
    {
        // Normalize path
        $path = '/' . trim($path, '/');

        // Security: Block client requests to underscore-prefixed paths
        if (!$allowUnderscore && $this->pathContainsUnderscoreComponent($path)) {
            return null; // 404 - not routable
        }

        // Build candidate list
        $candidates = $this->buildCandidateFiles($path);

        // Try each candidate
        foreach ($candidates as $candidate) {
            $fullPath = Mini::$mini->paths->routes->findFirst($candidate);
            if ($fullPath) {
                return $fullPath;
            }
        }

        return null;
    }

    /**
     * Check if path contains any underscore-prefixed components
     *
     * Security check for client requests.
     * Examples:
     * - /_ping → true (blocked)
     * - /users/_internal → true (blocked)
     * - /user-profile → false (hyphen, not underscore)
     *
     * @param string $path
     * @return bool
     */
    private function pathContainsUnderscoreComponent(string $path): bool
    {
        $parts = explode('/', trim($path, '/'));
        foreach ($parts as $part) {
            if ($part !== '' && str_starts_with($part, '_')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Build list of candidate files for a given path
     *
     * Returns candidates in order of specificity (most specific first).
     *
     * IMPORTANT: Trailing slash matters!
     * - /path → path.php (NOT path/index.php)
     * - /path/ → path/index.php (NOT path.php)
     *
     * @param string $path Normalized path (leading slash)
     * @return array<string> List of candidate files to try
     */
    private function buildCandidateFiles(string $path): array
    {
        $candidates = [];

        // Handle root path specially
        if ($path === '/') {
            return ['index.php', '__DEFAULT__.php'];
        }

        // Check if path ends with slash
        $hasTrailingSlash = str_ends_with($path, '/');

        if ($hasTrailingSlash) {
            // Path with trailing slash: /users/123/
            // Try: users/123/index.php, users/123/__DEFAULT__.php, users/__DEFAULT__.php, __DEFAULT__.php
            $pathWithoutSlash = rtrim($path, '/');
            $candidates[] = trim($pathWithoutSlash, '/') . '/index.php';
            $candidates[] = trim($pathWithoutSlash, '/') . '/__DEFAULT__.php';

            // Add parent __DEFAULT__.php files
            $this->addParentDefaults($pathWithoutSlash, $candidates);

        } else {
            // Path without trailing slash: /users/123
            // Try: users/123.php, users/__DEFAULT__.php, __DEFAULT__.php
            $candidates[] = trim($path, '/') . '.php';

            // Add __DEFAULT__.php files from parent directories
            $dir = dirname($path);
            if ($dir === '/') {
                $candidates[] = '__DEFAULT__.php';
            } else {
                $this->addParentDefaults($dir, $candidates);
            }
        }

        return $candidates;
    }

    /**
     * Add parent __DEFAULT__.php files to candidates list
     *
     * @param string $path Path to get parents for
     * @param array $candidates Candidates array to append to
     * @return void
     */
    private function addParentDefaults(string $path, array &$candidates): void
    {
        $parts = explode('/', trim($path, '/'));

        // Walk up the directory tree
        while (count($parts) > 0) {
            array_pop($parts);
            if (count($parts) > 0) {
                $candidates[] = implode('/', $parts) . '/__DEFAULT__.php';
            }
        }

        // Root __DEFAULT__.php
        $candidates[] = '__DEFAULT__.php';
    }

    /**
     * Handle Redirect exception
     *
     * Resolves the redirect target path (relative/absolute) and creates a new request.
     * Target is a REQUEST PATH, not a filename. Router will resolve it to a file.
     *
     * @param ServerRequestInterface $request Current request
     * @param string|null $currentFile Current controller file path
     * @param string $target Redirect target REQUEST PATH (e.g., "../_user?id=123")
     * @return ServerRequestInterface Updated request
     */
    private function handleRedirect(ServerRequestInterface $request, ?string $currentFile, string $target): ServerRequestInterface
    {
        // Resolve target path relative to current file
        $resolvedPath = $this->resolveRedirectPath($currentFile, $target);

        // Parse query string from target
        $parts = parse_url($resolvedPath);
        $path = $parts['path'] ?? $resolvedPath;
        $queryString = $parts['query'] ?? '';

        // Parse query params
        $queryParams = [];
        if ($queryString !== '') {
            parse_str($queryString, $queryParams);
        }

        // Build new request target
        $newTarget = $path;
        if ($queryString !== '') {
            $newTarget .= '?' . $queryString;
        }

        // Update request
        $uri = $request->getUri()->withPath($path)->withQuery($queryString);
        $newRequest = $request
            ->withUri($uri)
            ->withQueryParams(array_merge($request->getQueryParams(), $queryParams))
            ->withAttribute('mini.previousRequest', $request)
            ->withRequestTarget($newTarget);

        // Update $_GET request global
        $_GET = array_merge($_GET, $queryParams);

        return $newRequest;
    }

    /**
     * Handle Reroute exception
     *
     * Matches current path against patterns and resolves target.
     * Only valid from __DEFAULT__.php files.
     *
     * @param ServerRequestInterface $request Current request
     * @param string|null $currentFile Current controller file path
     * @param array $routes Pattern => target mapping
     * @return ServerRequestInterface Updated request
     * @throws \RuntimeException If not called from __DEFAULT__.php
     */
    private function handleReroute(ServerRequestInterface $request, ?string $currentFile, array $routes): ServerRequestInterface
    {
        // Verify called from __DEFAULT__.php
        if ($currentFile === null || !str_ends_with(basename($currentFile), '__DEFAULT__.php')) {
            throw new \RuntimeException('Reroute can only be used in __DEFAULT__.php files');
        }

        // Get current path relative to __DEFAULT__.php directory
        $currentPath = parse_url($request->getRequestTarget(), PHP_URL_PATH) ?? '/';
        $defaultDir = dirname($currentFile);

        // Match patterns (simplified - you may want to use a proper pattern matcher)
        foreach ($routes as $pattern => $target) {
            if ($this->matchPattern($pattern, $currentPath, $matches)) {
                // Pattern matched - resolve target
                $resolvedTarget = $this->resolveRerouteTarget($target, $matches, $defaultDir);

                // Handle as redirect
                return $this->handleRedirect($request, $currentFile, $resolvedTarget);
            }
        }

        throw new \mini\Http\NotFoundException("No route pattern matched: $currentPath");
    }

    /**
     * Resolve redirect path (relative/absolute)
     *
     * @param string|null $currentFile Current file absolute path
     * @param string $target Target path
     * @return string Resolved path
     */
    private function resolveRedirectPath(?string $currentFile, string $target): string
    {
        // Absolute path
        if (str_starts_with($target, '/')) {
            return $target;
        }

        // Relative path - resolve from current file's directory
        if ($currentFile !== null) {
            $currentDir = dirname($currentFile);
            $routesDir = Mini::$mini->paths->routes->getPaths()[0] ?? Mini::$mini->root . '/_routes';

            // Get relative path from routes dir
            $relativeDir = str_replace($routesDir, '', $currentDir);
            $relativeDir = trim($relativeDir, '/');

            // Resolve ../ and ./
            $parts = $relativeDir !== '' ? explode('/', $relativeDir) : [];
            $targetParts = explode('/', $target);

            foreach ($targetParts as $part) {
                if ($part === '..') {
                    array_pop($parts);
                } elseif ($part !== '.' && $part !== '') {
                    $parts[] = $part;
                }
            }

            return '/' . implode('/', $parts);
        }

        return '/' . ltrim($target, '/');
    }

    /**
     * Match a route pattern against a path
     *
     * Simple pattern matching. Supports:
     * - /{id}/ - Named parameter
     * - / - Exact match
     *
     * @param string $pattern
     * @param string $path
     * @param array &$matches Output parameter matches
     * @return bool
     */
    private function matchPattern(string $pattern, string $path, array &$matches): bool
    {
        // Exact match
        if ($pattern === $path) {
            $matches = [];
            return true;
        }

        // Pattern matching - convert {id} to regex
        $regex = preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $pattern);
        $regex = '#^' . $regex . '$#';

        if (preg_match($regex, $path, $m)) {
            $matches = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            return true;
        }

        return false;
    }

    /**
     * Resolve reroute target
     *
     * @param mixed $target Target (string, Closure, ResponseInterface, etc.)
     * @param array $matches Pattern matches
     * @param string $baseDir Base directory for relative paths
     * @return string Resolved target path
     */
    private function resolveRerouteTarget(mixed $target, array $matches, string $baseDir): string
    {
        // Closure - invoke with matches
        if ($target instanceof \Closure) {
            $target = $target(...array_values($matches));
        }

        // Must be string at this point
        if (!is_string($target)) {
            throw new \RuntimeException('Reroute target must resolve to a string path');
        }

        // Resolve relative to __DEFAULT__.php directory
        if (!str_starts_with($target, '/')) {
            $routesDir = Mini::$mini->paths->routes->getPaths()[0] ?? Mini::$mini->root . '/_routes';
            $relativeDir = str_replace($routesDir, '', $baseDir);
            $target = '/' . trim($relativeDir, '/') . '/' . ltrim($target, '/');
        }

        return $target;
    }

    /**
     * Populate $_GET and $_POST request globals from request
     *
     * Makes request data available via traditional PHP request globals.
     * This maintains compatibility with Mini's "back to basics" philosophy.
     *
     * @param ServerRequestInterface $request
     * @return void
     */
    private function populateRequestglobals(ServerRequestInterface $request): void
    {
        // Populate $_GET from query params
        $_GET = $request->getQueryParams();

        // Populate $_POST from parsed body (if it's an array)
        $parsedBody = $request->getParsedBody();
        if (is_array($parsedBody)) {
            $_POST = $parsedBody;
        }
    }

    /**
     * Replace the global request instance
     *
     * Updates the service container so mini\request() returns the updated request.
     * This allows middleware/hooks to modify the request and have controllers see the changes.
     *
     * @param ServerRequestInterface $request
     * @return void
     */
    private function replaceGlobalRequest(ServerRequestInterface $request): void
    {
        Mini::$mini->set(ServerRequestInterface::class, $request);
    }
}
