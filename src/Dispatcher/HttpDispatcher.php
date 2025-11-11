<?php

namespace mini\Dispatcher;

use mini\Mini;
use mini\Converter\ConverterRegistryInterface;
use mini\Http\ResponseAlreadySentException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;

/**
 * HTTP request dispatcher
 *
 * The HttpDispatcher is the entry point for HTTP requests. It:
 * 1. Creates PSR-7 ServerRequest from PHP globals
 * 2. Makes request available via mini\request()
 * 3. Delegates to RequestHandlerInterface (Router)
 * 4. Converts exceptions to HTTP responses
 * 5. Emits response to browser
 *
 * Architecture:
 * HttpDispatcher (request lifecycle) → RequestHandlerInterface (Router) → Controllers
 *
 * Exception handling:
 * Exceptions thrown during request handling are converted to ResponseInterface
 * using a separate exception converter registry. This allows registering specific
 * exception handlers without polluting the main converter registry.
 *
 * Usage:
 * ```php
 * // html/index.php
 * Mini::$mini->get(HttpDispatcher::class)->dispatch();
 * ```
 *
 * Register exception converters:
 * ```php
 * // bootstrap.php
 * $dispatcher = Mini::$mini->get(HttpDispatcher::class);
 * $dispatcher->registerExceptionConverter(function(NotFoundException $e): ResponseInterface {
 *     return new Response(404, ['Content-Type' => 'text/html'], render('404'));
 * });
 * ```
 */
class HttpDispatcher
{
    private ConverterRegistryInterface $exceptionConverters;
    private ?ServerRequestInterface $currentServerRequest = null;

    public function __construct()
    {
        // Create separate converter registry for exceptions
        // This keeps exception handling separate from content conversion
        $this->exceptionConverters = new \mini\Converter\ConverterRegistry();

        // Register ServerRequest as Transient service that returns current request
        // This allows mini\request() to work and get the current request from dispatcher
        Mini::$mini->addService(
            ServerRequestInterface::class,
            \mini\Lifetime::Transient,
            fn() => $this->currentServerRequest ?? throw new \RuntimeException(
                'No ServerRequest available. ServerRequest is only available during request handling.'
            )
        );
    }

    /**
     * Register an exception converter
     *
     * Exception converters transform exceptions to HTTP responses.
     * They are separate from the main converter registry to keep concerns separated.
     *
     * Examples:
     * ```php
     * // Handle 404 errors
     * $dispatcher->registerExceptionConverter(function(NotFoundException $e): ResponseInterface {
     *     return new Response(404, ['Content-Type' => 'text/html'], render('404'));
     * });
     *
     * // Handle validation errors
     * $dispatcher->registerExceptionConverter(function(ValidationException $e): ResponseInterface {
     *     $json = json_encode(['errors' => $e->errors]);
     *     return new Response(400, ['Content-Type' => 'application/json'], $json);
     * });
     *
     * // Generic error handler
     * $dispatcher->registerExceptionConverter(function(\Throwable $e): ResponseInterface {
     *     $statusCode = 500;
     *     $message = Mini::$mini->debug ? $e->getMessage() : 'Internal Server Error';
     *     return new Response($statusCode, ['Content-Type' => 'text/html'], render('error', compact('message')));
     * });
     * ```
     *
     * @param \Closure $converter Typed closure: function(ExceptionType): ResponseInterface
     * @return void
     */
    public function registerExceptionConverter(\Closure $converter): void
    {
        $this->exceptionConverters->register($converter);
    }


    /**
     * Dispatch the current HTTP request
     *
     * Complete HTTP request lifecycle:
     * 1. Transition to Ready phase (enables Scoped services)
     * 2. Create PSR-7 ServerRequest from PHP request globals
     * 3. Set as current request (available via mini\request())
     * 4. Get RequestHandlerInterface from container (Router)
     * 5. Handle request and get response
     * 6. Catch exceptions and convert to responses
     * 7. Emit response to browser
     *
     * @return void
     */
    public function dispatch(): void
    {
        try {
            // 1. Transition to Ready phase (enables Scoped services)
            Mini::$mini->phase->trigger(\mini\Phase::Ready);

            // 2. Create PSR-7 ServerRequest from PHP request globals
            $psr17Factory = new Psr17Factory();
            $creator = new ServerRequestCreator(
                $psr17Factory, // ServerRequestFactory
                $psr17Factory, // UriFactory
                $psr17Factory, // UploadedFileFactory
                $psr17Factory  // StreamFactory
            );
            $serverRequest = $creator->fromGlobals();

            // 3. Set current request (makes it available via mini\request())
            $this->currentServerRequest = $serverRequest;

            // 4. Add callback to allow Router to replace current request
            //    Router uses this after Redirect/Reroute to update the request
            $serverRequest = $serverRequest->withAttribute(
                'mini.dispatcher.replaceRequest',
                function(ServerRequestInterface $newRequest) {
                    $this->currentServerRequest = $newRequest;
                }
            );

            // 5. Dispatch into the framework
            try {
                $handler = Mini::$mini->get(RequestHandlerInterface::class);
                $response = $handler->handle($serverRequest);

            } catch (ResponseAlreadySentException $e) {
                // Response already sent using classical PHP (echo/header)
                // Nothing more to do
                return;

            } catch (\Throwable $e) {
                // Convert exception to response
                $response = $this->exceptionConverters->convert($e, ResponseInterface::class);

                if ($response === null) {
                    // No exception converter registered - rethrow
                    throw $e;
                }
            }

            // 6. Emit response to browser
            $this->emitResponse($response);

        } catch (\Throwable $e) {
            // Last resort error handling
            $this->handleFatalError($e);
        }
    }

    /**
     * Emit a PSR-7 response to the browser
     *
     * Sends status code, headers, and body.
     *
     * @param ResponseInterface $response
     * @return void
     */
    private function emitResponse(ResponseInterface $response): void
    {
        // Send status code
        http_response_code($response->getStatusCode());

        // Send headers
        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                header("$name: $value", false);
            }
        }

        // Send body
        echo $response->getBody();
    }

    /**
     * Handle fatal errors when no exception converter is registered
     *
     * Last resort error handling - renders a basic error page.
     *
     * @param \Throwable $e
     * @return void
     */
    private function handleFatalError(\Throwable $e): void
    {
        // Clean output buffer if present
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $statusCode = 500;
        $message = Mini::$mini->debug
            ? htmlspecialchars($e->getMessage())
            : 'Internal Server Error';

        http_response_code($statusCode);
        echo "<!DOCTYPE html>
<html lang=\"en\">
<head>
    <meta charset=\"UTF-8\">
    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
    <title>$statusCode - Error</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 600px; margin: 100px auto; padding: 20px; }
        h1 { color: #dc3545; }
    </style>
</head>
<body>
    <h1>$statusCode - Internal Server Error</h1>
    <p>$message</p>
</body>
</html>";
    }
}
