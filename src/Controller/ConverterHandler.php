<?php

namespace mini\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Request handler that converts controller method return values to PSR-7 responses
 *
 * This handler wraps a controller method callable and automatically converts its
 * return value to a ResponseInterface using the converter registry.
 *
 * Conversion flow:
 * 1. Invoke the controller method with URL parameters from request attributes
 * 2. If return value is already ResponseInterface, return it directly
 * 3. Otherwise, use converter registry to convert return value to ResponseInterface
 * 4. If no converter found, throw RuntimeException
 *
 * This enables controllers to return any type (arrays, strings, domain objects)
 * without manually creating Response objects.
 *
 * Example:
 * ```php
 * // Controller method returns array
 * public function index(): array {
 *     return ['users' => $this->users];
 * }
 *
 * // ConverterHandler converts array → ResponseInterface via registered converter
 * $handler = new ConverterHandler($this->index(...));
 * $response = $handler->handle($request); // JSON response
 * ```
 *
 * @package mini\Controller
 */
class ConverterHandler implements RequestHandlerInterface
{
    /**
     * @param callable $handler Controller method or callable to invoke
     */
    public function __construct(
        private readonly mixed $handler
    ) {}

    /**
     * Handle the request by invoking the controller method and converting its return value
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     * @throws \RuntimeException If return value cannot be converted to ResponseInterface
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // Invoke controller method with parameters from request attributes
        $result = $this->invokeHandler($request);

        // Already a response? Return directly
        if ($result instanceof ResponseInterface) {
            return $result;
        }

        // Try to convert using converter registry
        $response = \mini\convert($result, ResponseInterface::class);

        if ($response === null) {
            throw new \RuntimeException(
                "Controller method returned " . get_debug_type($result) .
                " which cannot be converted to ResponseInterface. " .
                "Either return ResponseInterface directly or register a converter for this type."
            );
        }

        return $response;
    }

    /**
     * Invoke the handler with dependency injection from request attributes
     *
     * @param ServerRequestInterface $request
     * @return mixed The controller method return value
     */
    private function invokeHandler(ServerRequestInterface $request): mixed
    {
        $handler = $this->handler;

        // Get reflection for parameter analysis
        if ($handler instanceof \Closure) {
            $reflection = new \ReflectionFunction($handler);
        } elseif (is_array($handler)) {
            $reflection = new \ReflectionMethod($handler[0], $handler[1]);
        } else {
            $reflection = new \ReflectionMethod($handler);
        }

        $args = [];

        // Build arguments from request attributes (URL parameters set by Router)
        foreach ($reflection->getParameters() as $param) {
            $name = $param->getName();

            // Get from request attributes (Router stores URL parameters here)
            $value = $request->getAttribute($name);

            if ($value !== null) {
                $args[] = $value;
                continue;
            }

            // Use default value if available
            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
                continue;
            }

            // Allow null if parameter is nullable
            if ($param->allowsNull()) {
                $args[] = null;
                continue;
            }

            throw new \InvalidArgumentException("Missing required parameter: $name");
        }

        return call_user_func_array($handler, $args);
    }
}
