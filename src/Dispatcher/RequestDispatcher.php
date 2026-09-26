<?php

namespace mini\Dispatcher;

use mini\Mini;
use mini\Converter\ConverterRegistryInterface;
use Psr\Http\Message\{ServerRequestInterface, ResponseInterface};
use Psr\Http\Server\{RequestHandlerInterface, MiddlewareInterface};

/**
 * The request pipeline, as a PSR-15 request handler: middleware, the Router, exception
 * conversion, and the before/after-request hooks
 *
 * It does not know where requests come from. HttpDispatcher feeds it the request of PHP's own
 * SAPI (FPM, the built-in server) and emits the response; an application server that speaks
 * PSR-15, such as Swerve, calls handle() directly, for many requests at a time in one process:
 *
 * ```php
 * // swerve.php
 * return Mini::$mini->get(\mini\Dispatcher\RequestDispatcher::class);
 * ```
 *
 * Requests are kept apart by request scope (`Mini::getRequestScope()`): `mini\request()` and the
 * `$_GET`/`$_POST`/`$_COOKIE` proxies see the request of the scope they are called from.
 */
class RequestDispatcher implements RequestHandlerInterface
{
    private ConverterRegistryInterface $exceptionConverters;

    /**
     * The request each request scope is handling, see handle(): one map for the process, since
     * a scope handles one request at a time, whichever dispatcher it goes through
     *
     * @var \WeakMap<object, ServerRequestInterface>|null
     */
    private static ?\WeakMap $currentRequests = null;

    /** @var array<MiddlewareInterface> Middleware stack (FIFO order) */
    private array $middlewares = [];

    /** The first request was handled, see start() */
    private bool $started = false;

    /**
     * Event triggered before processing a request
     *
     * Listeners receive the ServerRequestInterface being processed.
     * Use this for request-scoped initialization.
     *
     * @var \mini\Hooks\Event<ServerRequestInterface>
     */
    public readonly \mini\Hooks\Event $onBeforeRequest;

    /**
     * Event triggered after processing a request (in finally block)
     *
     * Always fires, even if an exception was thrown or response already sent.
     * Use this for cleanup, session saving, logging, etc.
     *
     * Listeners receive: (ServerRequestInterface $request, ?ResponseInterface $response, ?\Throwable $exception)
     * - $response is null if exception was thrown before response was created
     * - $exception is the thrown exception (if any), null on success
     *
     * @var \mini\Hooks\Event<ServerRequestInterface, ?ResponseInterface, ?\Throwable>
     */
    public readonly \mini\Hooks\Event $onAfterRequest;

    /**
     * @param RequestHandlerInterface|null $requestHandler the final handler; the Router by default
     */
    public function __construct(
        private ?RequestHandlerInterface $requestHandler = null,
    ) {
        // Create separate converter registry for exceptions
        // This keeps exception handling separate from content conversion
        $this->exceptionConverters = new \mini\Converter\ConverterRegistry();
        self::$currentRequests ??= new \WeakMap();

        // Initialize request lifecycle hooks
        $this->onBeforeRequest = new \mini\Hooks\Event('http.before-request');
        $this->onAfterRequest = new \mini\Hooks\Event('http.after-request');
    }

    /**
     * Add middleware to the request pipeline
     *
     * Middleware is executed in the order added (FIFO).
     * Can only be called during Bootstrap phase - throws exception if called after Ready phase.
     *
     * Examples:
     * ```php
     * // In bootstrap.php or module functions.php
     * $dispatcher = Mini::$mini->get(RequestDispatcher::class);
     * $dispatcher->addMiddleware(Mini::$mini->get(StaticFiles::class));
     * $dispatcher->addMiddleware(new CorsMiddleware());
     * $dispatcher->addMiddleware(new AuthMiddleware());
     * ```
     *
     * @param MiddlewareInterface $middleware PSR-15 middleware instance
     * @return self For method chaining
     * @throws \RuntimeException If called after Bootstrap phase
     */
    public function addMiddleware(MiddlewareInterface $middleware): self
    {
        // Only allow middleware registration during Bootstrap phase
        $currentPhase = Mini::$mini->phase->getCurrentState();
        if ($currentPhase === \mini\Phase::Ready || $currentPhase === \mini\Phase::Shutdown) {
            throw new \RuntimeException(
                'Cannot add middleware after Bootstrap phase. ' .
                'Middleware must be registered during application bootstrap.'
            );
        }

        $this->middlewares[] = $middleware;
        return $this;
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
        // During Bootstrap phase, allow transparent replacement of existing converters.
        // This lets application code override framework defaults without errors.
        if (Mini::$mini->phase->getCurrentState() === \mini\Phase::Bootstrap) {
            $this->exceptionConverters->replace($converter);
        } else {
            $this->exceptionConverters->register($converter);
        }
    }


    /**
     * Handle one request: middleware, the Router, handlers, and exception conversion
     *
     * An exception with a registered converter becomes its response; one without is thrown to
     * the caller. `onAfterRequest` fires as this returns: before a streamed response body has
     * been sent.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->start();
        $scope     = Mini::$mini->getRequestScope();
        $response  = null;
        $exception = null;

        // The Router and the middleware make the request they pass on the scope's current one
        // (query parsing, reroutes, body parsing), so that request() and the proxies follow it
        $track   = function (ServerRequestInterface $newRequest) use ($scope): void {
            self::$currentRequests[$scope] = $newRequest;
        };
        $request = $request->withAttribute('mini.dispatcher.replaceRequest', $track);
        self::$currentRequests[$scope] = $request;
        try {
            $this->onBeforeRequest->trigger($request);
            $handler = $this->buildMiddlewareChain($this->requestHandler ?? Mini::$mini->get(\mini\Router\Router::class), $track);
            try {
                return $response = $handler->handle($request);
            } catch (\Throwable $e) {
                $found    = false;
                $response = $this->exceptionConverters->tryConvert($e, ResponseInterface::class, found: $found);
                if (!$found) {
                    // No exception converter registered - rethrow
                    $exception = $e;
                    throw $e;
                }

                return $response;
            }
        } finally {
            // Always trigger after-request hook for cleanup (session save, logging, etc.)
            $this->onAfterRequest->trigger(self::$currentRequests[$scope], $response, $exception);
            unset(self::$currentRequests[$scope]);
        }
    }

    /**
     * The request the current request scope is handling: what the ServerRequestInterface
     * service (mini\request()) returns
     *
     * @throws \RuntimeException outside of request handling
     */
    public static function currentRequest(): ServerRequestInterface
    {
        return self::$currentRequests[Mini::$mini->getRequestScope()] ?? throw new \RuntimeException(
            'No ServerRequest available. ServerRequest is only available during request handling.'
        );
    }

    /**
     * Before the first request: replace the request globals with proxies, and declare the
     * Ready phase (which locks down service registration) unless the application did.
     * Once per process.
     */
    private function start(): void
    {
        if ($this->started) {
            return;
        }
        $this->started = true;
        $this->installRequestGlobalProxies();
        if (Mini::$mini->phase->getCurrentState() !== \mini\Phase::Ready) {
            Mini::$mini->phase->trigger(\mini\Phase::Ready);
        }
    }

    /**
     * Build middleware chain wrapper around the final handler
     *
     * Wraps the handler (Router) with all registered middleware in reverse order
     * to ensure FIFO execution (first added middleware executes first).
     *
     * Each wrapper makes the request it is given the scope's current one, so that
     * mini\request() and $_GET/$_POST proxies always reflect the latest request
     * as it flows through middleware. This enables middleware like JSON body parsers
     * to make their changes visible to $_POST and request()->getParsedBody().
     *
     * @param RequestHandlerInterface $handler Final handler (typically Router)
     * @param \Closure(ServerRequestInterface): void $trackRequest makes a request the scope's current one
     * @return RequestHandlerInterface Wrapped handler with middleware chain
     */
    private function buildMiddlewareChain(RequestHandlerInterface $handler, \Closure $trackRequest): RequestHandlerInterface
    {
        // Wrap the final handler (Router) so its handle() calls are also tracked
        $handler = new class($handler, $trackRequest) implements RequestHandlerInterface {
            /** @param \Closure(ServerRequestInterface): void $trackRequest */
            public function __construct(
                private RequestHandlerInterface $inner,
                private \Closure $trackRequest
            ) {}

            public function handle(ServerRequestInterface $request): ResponseInterface {
                ($this->trackRequest)($request);
                return $this->inner->handle($request);
            }
        };

        // Wrap handler with middleware in reverse order (FIFO execution)
        // Last middleware in array wraps the handler first
        for ($i = count($this->middlewares) - 1; $i >= 0; $i--) {
            $middleware = $this->middlewares[$i];
            $handler = new class($middleware, $handler, $trackRequest) implements RequestHandlerInterface {
                /** @param \Closure(ServerRequestInterface): void $trackRequest */
                public function __construct(
                    private MiddlewareInterface $middleware,
                    private RequestHandlerInterface $next,
                    private \Closure $trackRequest
                ) {}

                public function handle(ServerRequestInterface $request): ResponseInterface {
                    ($this->trackRequest)($request);
                    return $this->middleware->process($request, $this->next);
                }
            };
        }

        return $handler;
    }

    /**
     * Install request global proxies for fiber-safe request handling
     *
     * Replaces $_GET, $_POST, $_COOKIE with ArrayAccess proxies that delegate
     * to the current ServerRequest. This enables:
     * - Fiber-safe concurrent request handling
     * - Zero code changes (existing $_GET['id'] works)
     * - Works with all SAPIs (FPM, Swoole, ReactPHP, etc.)
     *
     * Called once, before the first request.
     *
     * @return void
     */
    private function installRequestGlobalProxies(): void
    {
        static $installed = false;

        if ($installed) {
            return;
        }

        $_GET = new \mini\Http\RequestGlobalProxy('query');
        $_POST = new \mini\Http\RequestGlobalProxy('post');
        $_COOKIE = new \mini\Http\RequestGlobalProxy('cookie');
        $_SESSION = new \mini\Session\SessionProxy();

        $installed = true;
    }
}
