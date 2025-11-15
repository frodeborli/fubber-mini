<?php

namespace mini\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Controller-level router with type-aware route registration
 *
 * Provides route matching and parameter extraction for controller methods.
 * Not PSR-15 middleware - returns match information for AbstractController to handle.
 *
 * Type-aware pattern generation:
 * - int $id → {id} becomes (?<id>\d+)
 * - string $slug → {slug} becomes (?<slug>[^/]+)
 * - float $price → {price} becomes (?<price>\d+\.?\d*)
 *
 * Architecture:
 * 1. Router::match() finds matching route and extracts URL parameters
 * 2. Returns array with 'handler' callable and 'params' (type-cast)
 * 3. AbstractController enriches request with params and creates ConverterHandler
 * 4. ConverterHandler invokes controller method and converts return value
 */
class Router
{
    private array $routes = [];

    public function __construct()
    {
    }

    /**
     * Register GET route
     *
     * @param string $path Route pattern (e.g., '/', '/{id}/', '/{postId}/comments/{commentId}/')
     * @param \Closure $handler Controller method closure
     */
    public function get(string $path, \Closure $handler): void
    {
        $this->addRoute('GET', $path, $handler);
    }

    /**
     * Register POST route
     */
    public function post(string $path, \Closure $handler): void
    {
        $this->addRoute('POST', $path, $handler);
    }

    /**
     * Register PATCH route
     */
    public function patch(string $path, \Closure $handler): void
    {
        $this->addRoute('PATCH', $path, $handler);
    }

    /**
     * Register PUT route
     */
    public function put(string $path, \Closure $handler): void
    {
        $this->addRoute('PUT', $path, $handler);
    }

    /**
     * Register DELETE route
     */
    public function delete(string $path, \Closure $handler): void
    {
        $this->addRoute('DELETE', $path, $handler);
    }

    /**
     * Register route for any method
     */
    public function any(string $path, \Closure $handler): void
    {
        $this->addRoute('*', $path, $handler);
    }

    /**
     * Import routes from controller method attributes
     *
     * Scans the provided object for methods with Route attributes
     * (#[GET], #[POST], #[Route], etc.) and automatically registers them.
     *
     * Example:
     * ```php
     * class UserController extends AbstractController {
     *     public function __construct() {
     *         parent::__construct();
     *         $this->router->importRoutesFromAttributes($this);
     *     }
     *
     *     #[GET('/')]
     *     public function index(): ResponseInterface { ... }
     *
     *     #[POST('/')]
     *     public function create(): ResponseInterface { ... }
     * }
     * ```
     *
     * @param object $controller Controller instance to scan for route attributes
     */
    public function importRoutesFromAttributes(object $controller): void
    {
        $reflection = new \ReflectionClass($controller);

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            // Skip constructor and other special methods
            if ($method->isConstructor() || $method->isDestructor() || $method->isStatic()) {
                continue;
            }

            // Get Route attributes (includes GET, POST, etc. since they extend Route)
            $attributes = $method->getAttributes(
                Attributes\Route::class,
                \ReflectionAttribute::IS_INSTANCEOF
            );

            foreach ($attributes as $attribute) {
                /** @var Attributes\Route $route */
                $route = $attribute->newInstance();

                // Create closure for this method
                $closure = $method->getClosure($controller);

                // Determine HTTP method
                $httpMethod = $route->method ?? '*';

                // Register the route
                $this->addRoute($httpMethod, $route->path, $closure);
            }
        }
    }

    /**
     * Add route to registry
     */
    private function addRoute(string $method, string $path, \Closure $handler): void
    {
        // Analyze handler to determine parameter types
        $reflection = $this->reflectHandler($handler);

        // Compile path pattern with type-aware regex
        $pattern = $this->compilePath($path, $reflection);

        $this->routes[] = [
            'method' => $method,
            'path' => $path,
            'pattern' => $pattern,
            'handler' => $handler,
            'reflection' => $reflection
        ];
    }

    /**
     * Reflect on handler to extract parameter information
     */
    private function reflectHandler(\Closure $handler): array
    {
        $reflection = new \ReflectionFunction($handler);

        $params = [];
        foreach ($reflection->getParameters() as $param) {
            $name = $param->getName();
            $type = $param->getType();

            $params[$name] = [
                'name' => $name,
                'type' => $type ? $type->getName() : 'string',
                'nullable' => $type && $type->allowsNull(),
                'hasDefault' => $param->isDefaultValueAvailable(),
                'default' => $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null
            ];
        }

        return $params;
    }

    /**
     * Compile path pattern with type-aware regex
     *
     * Analyzes handler parameters to determine regex patterns:
     * - int → \d+
     * - string → [^/]+
     * - float → \d+\.?\d*
     * - bool → [01]|true|false
     *
     * Examples:
     *   '/{id}/' + int $id → '/(?<id>\d+)/'
     *   '/{slug}/' + string $slug → '/(?<slug>[^/]+)/'
     *   '/{postId}/comments/{commentId}/' + int params → '/(?<postId>\d+)/comments/(?<commentId>\d+)/'
     */
    private function compilePath(string $path, array $params): string
    {
        $pattern = preg_replace_callback(
            '/\{(\w+)\}/',
            function($matches) use ($params) {
                $paramName = $matches[1];

                // Determine regex based on parameter type
                if (isset($params[$paramName])) {
                    $regex = match($params[$paramName]['type']) {
                        'int' => '\d+',
                        'float' => '\d+\.?\d*',
                        'bool' => '[01]|true|false',
                        default => '[^/]+'
                    };
                } else {
                    // Parameter not found in handler - default to string
                    $regex = '[^/]+';
                }

                return "(?<{$paramName}>{$regex})";
            },
            $path
        );

        return '#^' . $pattern . '$#';
    }

    /**
     * Match request to a registered route
     *
     * Returns route match information including handler callable and type-cast parameters.
     * Handles trailing slash redirects by returning ResponseInterface for redirects.
     *
     * @param ServerRequestInterface $request
     * @return array|ResponseInterface Array with 'handler' and 'params', or redirect Response
     * @throws \mini\Exceptions\ResourceNotFoundException If no route matches
     */
    public function match(ServerRequestInterface $request): array|ResponseInterface
    {
        $method = $request->getMethod();
        $path = parse_url($request->getRequestTarget(), PHP_URL_PATH) ?? '/';

        // Try to find matching route
        foreach ($this->routes as $route) {
            // Check HTTP method
            if ($route['method'] !== '*' && $route['method'] !== $method) {
                continue;
            }

            // Check path pattern
            if (preg_match($route['pattern'], $path, $matches)) {
                // Extract named parameters
                $rawParams = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                // Enforce trailing slash consistency
                $routeEndsWithSlash = str_ends_with($route['path'], '/');
                $pathEndsWithSlash = str_ends_with($path, '/');

                if ($routeEndsWithSlash && !$pathEndsWithSlash) {
                    // Route expects trailing slash but path doesn't have it - redirect
                    return new \mini\Http\Message\Response('', ['Location' => $path . '/'], 301);
                } elseif (!$routeEndsWithSlash && $pathEndsWithSlash && $path !== '/') {
                    // Route doesn't expect trailing slash but path has it - redirect
                    return new \mini\Http\Message\Response('', ['Location' => rtrim($path, '/')], 301);
                }

                // Type-cast parameters
                $params = $this->typeCastParams($route, $rawParams);

                // Return match information
                return [
                    'handler' => $route['handler'],
                    'params' => $params,
                ];
            }
        }

        // No route matched - check if alternate path (with/without trailing slash) would match
        if (!str_ends_with($path, '/')) {
            // Try with trailing slash
            foreach ($this->routes as $route) {
                if ($route['method'] !== '*' && $route['method'] !== $method) {
                    continue;
                }
                if (preg_match($route['pattern'], $path . '/', $matches)) {
                    // Alternate path matches - redirect to it
                    return new \mini\Http\Message\Response('', ['Location' => $path . '/'], 301);
                }
            }
        } elseif ($path !== '/') {
            // Try without trailing slash
            $pathWithoutSlash = rtrim($path, '/');
            foreach ($this->routes as $route) {
                if ($route['method'] !== '*' && $route['method'] !== $method) {
                    continue;
                }
                if (preg_match($route['pattern'], $pathWithoutSlash, $matches)) {
                    // Alternate path matches - redirect to it
                    return new \mini\Http\Message\Response('', ['Location' => $pathWithoutSlash], 301);
                }
            }
        }

        // No route matched - 404
        throw new \mini\Exceptions\ResourceNotFoundException('Route not found');
    }


    /**
     * Type-cast URL parameters based on route reflection info
     *
     * @param array $route Matched route information
     * @param array $rawParams Raw URL parameters from regex match
     * @return array Type-cast parameters
     */
    private function typeCastParams(array $route, array $rawParams): array
    {
        $params = [];

        // Type-cast parameters based on reflection info
        foreach ($route['reflection'] as $paramName => $paramInfo) {
            if (!isset($rawParams[$paramName])) {
                continue;
            }

            $value = $rawParams[$paramName];

            // Type cast based on parameter type
            $params[$paramName] = match($paramInfo['type']) {
                'int' => (int)$value,
                'float' => (float)$value,
                'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                default => $value
            };
        }

        return $params;
    }

}
