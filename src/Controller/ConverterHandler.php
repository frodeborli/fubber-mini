<?php

namespace mini\Controller;

use mini\Mini;
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

        // Resolution order for each parameter:
        //   1. $_0, $_1, ... → positional capture from 'mini.pathcomponents'
        //      (the same array that backs $_GET[0], $_GET[1], ... — nearest
        //      wildcard at index 0).
        //   2. Class-typed parameter — inject the current ServerRequest if the
        //      type matches, otherwise resolve from the service container.
        //   3. Named match against request attributes (URL captures set by
        //      Controller\Router live here, plus anything middleware added).
        //   4. Default value, then nullable → null, then throw.
        foreach ($reflection->getParameters() as $param) {
            $name = $param->getName();
            $type = $param->getType();

            // 1. $_N positional wildcards
            if (preg_match('/^_(\d+)$/', $name, $m)) {
                $components = $request->getAttribute('mini.pathcomponents', []);
                $idx = (int) $m[1];
                if (isset($components[$idx])) {
                    $value = $components[$idx];
                    if ($type instanceof \ReflectionNamedType && $type->isBuiltin()) {
                        settype($value, $type->getName());
                    }
                    $args[] = $value;
                    continue;
                }
                // fall through to defaults/nullable/throw
            }

            // 2. Class-typed injection
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $typeName = $type->getName();
                if (is_a($request, $typeName)) {
                    $args[] = $request;
                    continue;
                }
                if (Mini::$mini->has($typeName)) {
                    $args[] = Mini::$mini->get($typeName);
                    continue;
                }
            }

            // 3. Named match against request attributes
            $value = $request->getAttribute($name);
            if ($value !== null) {
                $args[] = $value;
                continue;
            }

            // 4. Default / nullable / throw
            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
                continue;
            }
            if ($param->allowsNull()) {
                $args[] = null;
                continue;
            }

            throw new \InvalidArgumentException("Missing required parameter: $name");
        }

        return call_user_func_array($handler, $args);
    }
}
