<?php

namespace mini\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Controller-level router with type-aware route registration
 *
 * Analyzes method signatures to generate regex patterns automatically:
 * - int $id → {id} becomes (?<id>\d+)
 * - string $slug → {slug} becomes (?<slug>[^/]+)
 * - float $price → {price} becomes (?<price>\d+\.?\d*)
 */
class Router
{
    private array $routes = [];
    private object $controller;

    public function __construct(object $controller)
    {
        $this->controller = $controller;
    }

    /**
     * Register GET route
     *
     * @param string $path Route pattern (e.g., '/', '/{id}/', '/{postId}/comments/{commentId}/')
     * @param callable $handler Controller method or closure
     */
    public function get(string $path, callable $handler): void
    {
        $this->addRoute('GET', $path, $handler);
    }

    /**
     * Register POST route
     */
    public function post(string $path, callable $handler): void
    {
        $this->addRoute('POST', $path, $handler);
    }

    /**
     * Register PATCH route
     */
    public function patch(string $path, callable $handler): void
    {
        $this->addRoute('PATCH', $path, $handler);
    }

    /**
     * Register PUT route
     */
    public function put(string $path, callable $handler): void
    {
        $this->addRoute('PUT', $path, $handler);
    }

    /**
     * Register DELETE route
     */
    public function delete(string $path, callable $handler): void
    {
        $this->addRoute('DELETE', $path, $handler);
    }

    /**
     * Register route for any method
     */
    public function any(string $path, callable $handler): void
    {
        $this->addRoute('*', $path, $handler);
    }

    /**
     * Add route to registry
     */
    private function addRoute(string $method, string $path, callable $handler): void
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
    private function reflectHandler(callable $handler): array
    {
        if ($handler instanceof \Closure) {
            $reflection = new \ReflectionFunction($handler);
        } elseif (is_array($handler)) {
            $reflection = new \ReflectionMethod($handler[0], $handler[1]);
        } else {
            $reflection = new \ReflectionMethod($handler);
        }

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
     * Dispatch request to matching route
     */
    public function dispatch(ServerRequestInterface $request): ResponseInterface
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
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                // Invoke handler
                return $this->invokeHandler($route, $request, $params);
            }
        }

        // No route matched - 404
        return $this->notFound($request);
    }

    /**
     * Invoke route handler with dependency injection
     */
    private function invokeHandler(array $route, ServerRequestInterface $request, array $urlParams): ResponseInterface
    {
        $handler = $route['handler'];

        $args = [];

        // Build arguments array in parameter order
        if ($handler instanceof \Closure) {
            $reflectionObj = new \ReflectionFunction($handler);
        } elseif (is_array($handler)) {
            $reflectionObj = new \ReflectionMethod($handler[0], $handler[1]);
        } else {
            $reflectionObj = new \ReflectionMethod($handler);
        }

        foreach ($reflectionObj->getParameters() as $param) {
            $name = $param->getName();
            $type = $param->getType();

            // Inject URL parameter
            if (isset($urlParams[$name])) {
                $value = $urlParams[$name];

                // Type cast based on parameter type hint
                if ($type && $type->isBuiltin()) {
                    $value = match($type->getName()) {
                        'int' => (int)$value,
                        'float' => (float)$value,
                        'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                        default => $value
                    };
                }

                $args[] = $value;
                continue;
            }

            // Inject request attribute (set by Router/middleware)
            $attrValue = $request->getAttribute($name);
            if ($attrValue !== null) {
                $args[] = $attrValue;
                continue;
            }

            // Use default value or null
            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            } elseif ($param->allowsNull()) {
                $args[] = null;
            } else {
                throw new \InvalidArgumentException("Missing required parameter: $name");
            }
        }

        // Invoke handler
        $result = call_user_func_array($handler, $args);

        // MUST return ResponseInterface
        if (!$result instanceof ResponseInterface) {
            throw new \RuntimeException(
                "Controller method must return ResponseInterface, got " . get_debug_type($result)
            );
        }

        return $result;
    }

    /**
     * Default 404 handler
     */
    private function notFound(ServerRequestInterface $request): ResponseInterface
    {
        throw new \mini\Http\NotFoundException('Route not found');
    }
}
