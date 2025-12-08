<?php

namespace mini\Router;

use mini\Mini;
use mini\Http\ResponseAlreadySentException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

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
 * return ['users' => iterator_to_array(db()->query("SELECT * FROM users"))];
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
        // Iterative route resolution loop
        while (true) {
            // 1. Resolve request path to controller file
            $requestTarget = $request->getRequestTarget();
            $path = parse_url($requestTarget, PHP_URL_PATH) ?? '/';

            // Check if this is an internal redirect (allows underscore-prefixed paths)
            $redirectCount = $request->getAttribute('mini.router.redirectCount', 0);

            $internalRequest = $redirectCount > 0;
            $handlerFile = $this->resolveHandlerFile($path, $internalRequest, $resolvedPath);

            if ($handlerFile === null) {
                // Before throwing 404, check if alternate path (with/without trailing slash) would match
                if (!str_ends_with($path, '/')) {
                    // Try with trailing slash
                    $altFile = $this->resolveHandlerFile($path . '/', $internalRequest);
                    if ($altFile !== null) {
                        // Return 301 redirect to path with trailing slash
                        return new \mini\Http\Message\Response('', ['Location' => $path . '/'], 301);
                    }
                } elseif ($path !== '/') {
                    // Try without trailing slash
                    $altFile = $this->resolveHandlerFile(rtrim($path, '/'), $internalRequest);
                    if ($altFile !== null) {
                        // Return 301 redirect to path without trailing slash
                        return new \mini\Http\Message\Response('', ['Location' => rtrim($path, '/')], 301);
                    }
                }

                throw new \mini\Exceptions\NotFoundException("Not Found: $path");
            }

            // Enforce trailing slash consistency:
            // - index.php files should only handle paths WITH trailing slash
            // - non-index .php files should only handle paths WITHOUT trailing slash
            // - __DEFAULT__.php handles its own routing logic (no automatic redirects)
            $isIndexFile = str_ends_with($handlerFile, '/index.php');
            $isDefaultFile = str_ends_with($handlerFile, '/__DEFAULT__.php');
            $pathHasSlash = str_ends_with($path, '/');

            if ($isIndexFile && !$pathHasSlash) {
                // index.php matched a path without trailing slash - redirect to add slash
                return new \mini\Http\Message\Response('', ['Location' => $path . '/'], 301);
            } elseif (!$isIndexFile && !$isDefaultFile && $pathHasSlash && $path !== '/') {
                // Regular .php file matched a path with trailing slash - redirect to remove slash
                return new \mini\Http\Message\Response('', ['Location' => rtrim($path, '/')], 301);
            }

            // Additional check: if we found a handler via wildcard, check if alternate path has exact match
            // This handles cases like: /users/john/ matches users/_/index.php BUT users/john.php exists
            // In this case, redirect to /users/john (the more specific handler)
            $pathSegments = explode('/', trim($path, '/'));
            $lastSegment = end($pathSegments);
            $isWildcardMatch = $lastSegment !== '' && !str_contains($handlerFile, '/' . $lastSegment . '.php') && !str_contains($handlerFile, '/' . $lastSegment . '/');

            if ($isWildcardMatch) {
                if ($pathHasSlash && $path !== '/') {
                    // Current is wildcard with slash - check if non-slash version has exact match
                    $altFile = $this->resolveHandlerFile(rtrim($path, '/'), $internalRequest);
                    if ($altFile !== null && str_contains($altFile, '/' . $lastSegment . '.php')) {
                        // Alternate has exact match - redirect to it
                        return new \mini\Http\Message\Response('', ['Location' => rtrim($path, '/')], 301);
                    }
                } elseif (!$pathHasSlash) {
                    // Current is wildcard without slash - check if slash version has exact match
                    $altFile = $this->resolveHandlerFile($path . '/', $internalRequest);
                    if ($altFile !== null && str_contains($altFile, '/' . $lastSegment . '/')) {
                        // Alternate has exact match - redirect to it
                        return new \mini\Http\Message\Response('', ['Location' => $path . '/'], 301);
                    }
                }
            }

            // 2. Annotate request with route info
            $request = $request->withAttribute('mini.router.handlerFile', $handlerFile);

            // Parse query string from request target and update query params explicitly
            // Query params are separate from request target per PSR-7, so we sync them here
            $requestTarget = $request->getRequestTarget();
            $queryString = parse_url($requestTarget, PHP_URL_QUERY);
            if ($queryString !== null && $queryString !== '') {
                parse_str($queryString, $queryParams);
                $request = $request->withQueryParams($queryParams);
            } elseif ($redirectCount > 0) {
                // If we redirected and there's no query string, clear query params
                $request = $request->withQueryParams([]);
            }

            // 3. Replace global request instance
            $this->replaceGlobalRequest($request);

            // 4. Include controller file and get return value
            try {
                $returnValue = self::runControllerFile($handlerFile);
            } catch (Redirect $redirect) {
                if ($redirectCount > self::MAX_REDIRECTS) {
                    throw new \RuntimeException(
                        'Too many redirects (limit: ' . self::MAX_REDIRECTS . '). ' .
                        'Possible infinite redirect loop. Last target: ' . $redirect->target
                    );
                }

                // Handle Redirect: resolve target path and restart routing
                $request = $this->handleRedirect($request, $resolvedPath, $redirect->target);
                continue;

            } catch (Reroute $reroute) {
                if (!str_ends_with($handlerFile, '/__DEFAULT__.php')) {
                    throw new \RuntimeException("Can only use Reroute in __DEFAULT__ routes");
                }
                if ($redirectCount > self::MAX_REDIRECTS) {
                    throw new \RuntimeException(
                        'Too many internal redirects (limit: ' . self::MAX_REDIRECTS . '). ' .
                        'Possible infinite redirect loop in Reroute patterns.'
                    );
                }

                $request = $this->handleReroute($request, $resolvedPath, $reroute->routes);
                continue;
            }

            // 5. Handle null return (classical PHP)
            if ($returnValue === null) {
                throw new ResponseAlreadySentException();
            }

            // 6. Check if return value is already a response
            if ($returnValue instanceof ResponseInterface) {
                return $returnValue;
            }

            // 7. Check if return value is a PSR-15 request handler
            if ($returnValue instanceof RequestHandlerInterface) {
                // Strip the resolved path from request target for scoped routing
                // e.g., /tests/router/ with resolvedPath="tests/router/" becomes /
                if ($resolvedPath !== null && $resolvedPath !== '') {
                    $scopedPath = '/' . ltrim(substr($path, strlen('/' . rtrim($resolvedPath, '/'))), '/');
                    $queryString = parse_url($requestTarget, PHP_URL_QUERY);
                    $scopedRequestTarget = $scopedPath . ($queryString ? '?' . $queryString : '');
                    $request = $request->withRequestTarget($scopedRequestTarget);

                    // Update global request instance with scoped request target
                    $this->replaceGlobalRequest($request);
                }

                return $returnValue->handle($request);
            }

            // 8. Convert to response
            $response = \mini\convert($returnValue, ResponseInterface::class);

            if ($response === null) {
                throw new \RuntimeException(
                    'Controller returned ' . get_debug_type($returnValue) .
                    ' but no converter is registered to convert it to ResponseInterface'
                );
            }

            return $response;
        }
    }

    private static function runControllerFile(string $filePath)
    {
        // TODO: We should detect if the controller uses \header() etc - and trigger an error
        ob_start();
        $result = (static function() use ($filePath) { return require $filePath; })();
        $output = ob_get_clean();
        if ($result === 1 && ($output !== '' || headers_sent() || headers_list() !== [])) {
            // In the case that the controller returns 1 explicitly, we want to avoid treating the
            // response as HTML, so we must check that either there is output, or headers were set or sent
            echo $output;
            return null;
        } else {
            // return value takes precedence over output buffer
            if ($output !== '') {
                throw new RuntimeException("Controller file '$filePath' produced unexpected output. Controllers must either use classical PHP output (echo/header) and return nothing, or return a value (which will be converted to a response). Mixing both is not allowed.");
            }
            return $result;
        }
    }

    /**
     * Resolve request path to controller file. Supports internal redirects (allowing _* path components) if $internalRequest is true
     *
     * Tries multiple candidate files in order of specificity:
     * - /path → ["_routes/path.php", "_routes/__DEFAULT__.php"]
     * - /path/ → ["_routes/path/index.php", "_routes/path/__DEFAULT__.php", "_routes/__DEFAULT__.php"]
     * - /users/123/ → ["_routes/users/123/index.php", "_routes/users/123/__DEFAULT__.php", "_routes/users/__DEFAULT__.php", "_routes/__DEFAULT__.php"]
     *
     * Filesystem Wildcards:
     * - Use "_" as directory or file name to match any single path segment
     * - Exact matches take precedence over wildcard matches
     * - Captured values stored in $_GET[0], $_GET[1], etc. (right to left - nearest wildcard is [0])
     * - Examples:
     *   - /users/123 → tries users/123.php, then users/_.php (captures "123" in $_GET[0])
     *   - /users/100/friendship/200 → tries exact path, then users/_/friendship/_.php ($_GET[0]="200", $_GET[1]="100")
     *
     * Security:
     * - Client requests: Path components starting with underscore are blocked (except via __DEFAULT__.php)
     * - Internal redirects: Underscore paths allowed (developer-controlled)
     * - Wildcard files (_/*.php) are internal-only (underscore prefix protection applies)
     *
     * @param string $path Request path (without query string)
     * @param bool $internalRequest Whether to allow underscore-prefixed paths
     * @param ?string $resolvedPath The path that was found the route registry
     * @return string|null Absolute path to controller file, or null if not found
     */
    private function resolveHandlerFile(string $path, bool $internalRequest = false, ?string &$resolvedPath=null): ?string
    {
        if ($path === '' || $path[0] !== '/') {
            throw new \LogicException("Router::resolveHandlerFile expects absolute paths from the root of the router");
        }

        $isDir = substr($path, -1) === '/';
        $parts = explode("/", substr($path, 1));
        $partCount = count($parts);

        // validate path
        foreach ($parts as $i => $part) {
            if ($isDir && $i === $partCount - 1) {
                // empty as expected
            } elseif ($part === '' || (!$internalRequest && $part[0] === '_')) {
                return null;
            }
        }

        // Route file registry:
        $routes = Mini::$mini->paths->routes;

        // Try to match path with wildcards (filesystem-based wildcards using "_")
        // Build path segment by segment, trying exact match first, then "_" wildcard
        $matchedParts = [];
        $wildcardValues = [];

        // Get all possible base paths (primary + fallbacks)
        $basePaths = $routes->getPaths();

        // Match directory segments
        for ($i = 0; $i < $partCount - 1; $i++) {
            $segment = $parts[$i];
            $currentPath = implode("/", $matchedParts);

            $foundMatch = false;

            // Try exact match for this directory segment
            $candidateDir = ($currentPath !== '' ? $currentPath . '/' : '') . $segment;
            foreach ($basePaths as $basePath) {
                if (is_dir($basePath . '/' . $candidateDir)) {
                    $matchedParts[] = $segment;
                    $foundMatch = true;
                    break;
                }
            }

            if (!$foundMatch) {
                // Try wildcard directory "_"
                $wildcardDir = ($currentPath !== '' ? $currentPath . '/' : '') . '_';
                foreach ($basePaths as $basePath) {
                    if (is_dir($basePath . '/' . $wildcardDir)) {
                        $matchedParts[] = '_';
                        $wildcardValues[] = $segment;
                        $foundMatch = true;
                        break;
                    }
                }
            }

            if (!$foundMatch) {
                // No match found, stop trying file-based matching
                break;
            }
        }

        // If we successfully matched all directory segments, try to match the file
        if (count($matchedParts) === $partCount - 1) {
            $finalSegment = $parts[$partCount - 1];
            $basePath = implode("/", $matchedParts);

            if ($isDir) {
                // Request ends with /, look for index.php
                $exactFile = ($basePath !== '' ? $basePath . '/' : '') . $finalSegment . '/index.php';
                if (null !== ($match = $routes->findFirst($exactFile))) {
                    $resolvedPath = dirname($exactFile) . '/';
                    if ($resolvedPath === './') {
                        $resolvedPath = '';
                    }
                    $this->populateWildcardParams($wildcardValues);
                    return $match;
                }

                // Try wildcard directory with index.php
                $wildcardFile = ($basePath !== '' ? $basePath . '/' : '') . '_/index.php';
                if (null !== ($match = $routes->findFirst($wildcardFile))) {
                    $resolvedPath = dirname($wildcardFile) . '/';
                    if ($resolvedPath === './') {
                        $resolvedPath = '';
                    }
                    $wildcardValues[] = $finalSegment;
                    $this->populateWildcardParams($wildcardValues);
                    return $match;
                }
            } else {
                // Request without trailing slash, look for .php file
                $exactFile = ($basePath !== '' ? $basePath . '/' : '') . $finalSegment . '.php';
                if (null !== ($match = $routes->findFirst($exactFile))) {
                    $resolvedPath = dirname($exactFile) . '/';
                    if ($resolvedPath === './') {
                        $resolvedPath = '';
                    }
                    $this->populateWildcardParams($wildcardValues);
                    return $match;
                }

                // Try wildcard file _.php
                $wildcardFile = ($basePath !== '' ? $basePath . '/' : '') . '_.php';
                if (null !== ($match = $routes->findFirst($wildcardFile))) {
                    $resolvedPath = dirname($wildcardFile) . '/';
                    if ($resolvedPath === './') {
                        $resolvedPath = '';
                    }
                    $wildcardValues[] = $finalSegment;
                    $this->populateWildcardParams($wildcardValues);
                    return $match;
                }
            }
        }

        // no direct match, so we must look for __DEFAULT__.php files
        for ($i = $partCount - 1; $i >= 0; $i--) {
            $candidatePath = implode("/", array_slice($parts, 0, $i)) . '/__DEFAULT__.php';
            if (null !== ($match = $routes->findFirst($candidatePath))) {
                $resolvedPath = dirname($candidatePath) . '/';
                return $match;
            }
        }

        $resolvedPath = null;

        return null;
    }

    /**
     * Populate $_GET with wildcard parameter values
     *
     * Assigns numeric indices (0, 1, 2, ...) to wildcard values captured from filesystem-based
     * wildcard routing using "_" directories and files. Values are stored in reverse order
     * (rightmost/nearest wildcard is $_GET[0]) so code remains stable when files are moved.
     *
     * @param array<int, string> $values Wildcard values in left-to-right URL order
     */
    private function populateWildcardParams(array $values): void
    {
        $reversed = array_reverse($values);
        foreach ($reversed as $index => $value) {
            $_GET[$index] = $value;
        }
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
    private function handleRedirect(ServerRequestInterface $request, string $resolvedPath, string $target): ServerRequestInterface
    {

        $requestTarget = $request->getRequestTarget();

        $rtPath = new \mini\Util\Path('/' . $resolvedPath);

        // Split target into path and query string
        $targetParts = explode("?", $target, 2);
        $targetPath = $targetParts[0];
        $targetQuery = $targetParts[1] ?? '';

        // Resolve the new path
        $newPath = $rtPath->join($targetPath);

        // Build complete request target (path + query string)
        $newRequestTarget = (string) $newPath;
        if ($targetQuery !== '') {
            $newRequestTarget .= '?' . $targetQuery;
        }

        // Update request target
        $request = $this->incrementRedirectCount($request)->withRequestTarget($newRequestTarget);

        return $request;
    }

    /**
     * Handle Reroute exception
     *
     * Matches current path against patterns and resolves target.
     * Only valid from __DEFAULT__.php files.
     *
     * @param ServerRequestInterface $request Current request
     * @param string|null $resolvedPath The url path where rerouting happens from
     * @param array $routes Pattern => target mapping
     * @return ServerRequestInterface Updated request
     * @throws \RuntimeException If not called from __DEFAULT__.php
     */
    private function handleReroute(ServerRequestInterface $request, string $resolvedPath, array $routes): ServerRequestInterface
    {

        $requestTarget = $request->getRequestTarget();
        $partialRequestTarget = substr($requestTarget, strlen($resolvedPath));

        foreach ($routes as $pattern => $target) {
            if ($this->matchPattern($pattern, $partialRequestTarget, $matches)) {

                if ($target instanceof \Closure) {
                    try {
                        $target = $this->injectRunClosure($target, $matches);
                    } catch (\Throwable $e) {
                        throw new \RuntimeException("Reroute handlers must not throw exceptions; they must return a string path which will be resolved from the directory of the __DEFAULT__.php file (query parameters can be included): Example target: '../other-dir/_custom-handler?id=123' (relative or absolute path allowed), query parameters optional. Exception received:\n$e");
                    }
                } elseif (is_string($target)) {
                } else {
                    throw new \RuntimeException("Reroute target must be Closure or string, got " . get_debug_type($target));
                }

                return $this->handleRedirect($request, $resolvedPath, $target);
            }
        }

        throw new \mini\Exceptions\NotFoundException("No route pattern matched: $partialRequestTarget");
    }

    private function injectRunClosure(\Closure $target, array $vars): string {
        $rf = new \ReflectionFunction($target);

        $finalArgs = [];

        foreach ($rf->getParameters() as $rp) {
            if (\array_key_exists($rp->name, $vars)) {
                $finalArgs[] = $vars[$rp->name];
            } else {
                throw new \InvalidArgumentException("Unable to inject parameter named '" . $rp->name . "'");
            }
        }

        return $target(...$finalArgs);
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
     * Pattern matching supports:
     * - Exact match: `/users` matches `/users`
     * - Simple placeholder: `/{id}` matches `/123` (captures single segment, no slashes)
     * - Constrained placeholder: `/{id:\d+}` matches `/123` but not `/abc`
     * - Greedy placeholder: `/{path:.*}` matches `/foo/bar/baz` (captures everything including slashes)
     *
     * Examples:
     * - `/{id}` → matches `/123`, captures `['id' => '123']`
     * - `/{id:\d+}` → matches `/123`, not `/abc`
     * - `/{page:.*}` → matches `/foo/bar`, captures `['page' => 'foo/bar']`
     * - `/users/{id}/posts/{slug:.*}` → matches `/users/42/posts/hello/world`
     *
     * @param string $pattern Route pattern with optional placeholders
     * @param string $path Request path to match
     * @param array &$matches Output array of captured parameters
     * @return bool True if pattern matches, false otherwise
     */
    private function matchPattern(string $pattern, string $path, ?array &$matches = null): bool
    {
        // Exact match
        if ($pattern === $path) {
            $matches = [];
            return true;
        }

        // Convert pattern to regex, supporting {name:regex} syntax
        $regex = preg_replace_callback(
            '/\{(\w+)(?::([^}]+))?\}/',
            function ($m) {
                $name = $m[1];
                $constraint = $m[2] ?? '[^/]+';  // Default: match anything except slashes
                return '(?P<' . $name . '>' . $constraint . ')';
            },
            $pattern
        );

        // Escape forward slashes and other regex special chars in the static parts
        $regex = '#^' . $regex . '$#';

        if (preg_match($regex, $path, $m)) {
            // Filter to only named captures (string keys)
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
     * Replace the global request instance
     *
     * Uses the callback provided by HttpDispatcher to update the current request.
     * This allows controllers to see updated request with route attributes and
     * redirected paths.
     *
     * @param ServerRequestInterface $request
     * @return void
     */
    private function replaceGlobalRequest(ServerRequestInterface $request): void
    {
        $callback = $request->getAttribute('mini.dispatcher.replaceRequest');
        if ($callback instanceof \Closure) {
            $callback($request);
        }
    }

    private function incrementRedirectCount(ServerRequestInterface $request): ServerRequestInterface {
        return $request->withAttribute('mini.router.redirectCount', 1 + $request->getAttribute('mini.router.redirectCount', 0));
    }
}
