<?php

namespace mini\Dispatcher;

use mini\Mini;
use mini\Converter\ConverterRegistryInterface;
use mini\Http\ResponseAlreadySentException;
use Psr\Http\Message\{ServerRequestInterface, ResponseInterface, UploadedFileInterface};
use Psr\Http\Server\{RequestHandlerInterface, MiddlewareInterface};
use mini\Http\Message\{ServerRequest, Stream, UploadedFile};

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

    /** @var array<MiddlewareInterface> Middleware stack (FIFO order) */
    private array $middlewares = [];

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

    public function __construct()
    {
        // Create separate converter registry for exceptions
        // This keeps exception handling separate from content conversion
        $this->exceptionConverters = new \mini\Converter\ConverterRegistry();

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
     * $dispatcher = Mini::$mini->get(HttpDispatcher::class);
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
        $this->exceptionConverters->register($converter);
    }


    /**
     * Dispatch the current HTTP request
     *
     * Complete HTTP request lifecycle:
     * 1. Register ServerRequest as Transient service
     * 2. Create PSR-7 ServerRequest from PHP request globals
     * 3. Set as current request
     * 4. Replace $_GET, $_POST, $_COOKIE with proxies (fiber-safe)
     * 5. Declare Ready phase (locks down service registration)
     * 6. Add request replacement callback for Router
     * 7. Build middleware chain and get RequestHandlerInterface (Router)
     * 8. Process request through middleware chain → router → handlers
     * 9. Catch exceptions and convert to responses
     * 10. Emit response to browser
     *
     * @return void
     */
    public function dispatch(): void
    {
        $response = null;
        $exception = null;

        try {
            // 1. Register ServerRequest as Transient service that returns current request
            Mini::$mini->addService(
                ServerRequestInterface::class,
                \mini\Lifetime::Transient,
                fn() => $this->currentServerRequest ?? throw new \RuntimeException(
                    'No ServerRequest available. ServerRequest is only available during request handling.'
                )
            );

            // 2. Create PSR-7 ServerRequest from PHP request globals (SAPI-specific)
            $serverRequest = $this->createServerRequestFromGlobals();

            // 3. Set current request
            $this->currentServerRequest = $serverRequest;

            // 4. Replace request globals with proxies (fiber-safe)
            $this->installRequestGlobalProxies();

            // 5. Declare Ready phase (locks down service registration)
            Mini::$mini->phase->trigger(\mini\Phase::Ready);

            // 6. Add callback to allow Router to replace current request
            //    Router uses this after Redirect/Reroute to update the request
            $serverRequest = $serverRequest->withAttribute(
                'mini.dispatcher.replaceRequest',
                function(ServerRequestInterface $newRequest) {
                    $this->currentServerRequest = $newRequest;
                }
            );

            // 7. Trigger before-request hook
            $this->onBeforeRequest->trigger($serverRequest);

            // 8. Build middleware chain and dispatch into the framework
            try {
                // Get the final handler (Router)
                $handler = Mini::$mini->get(RequestHandlerInterface::class);

                // Wrap handler with middleware stack (reverse order for FIFO execution)
                $handler = $this->buildMiddlewareChain($handler);

                // Process request through middleware chain
                $response = $handler->handle($serverRequest);

            } catch (ResponseAlreadySentException $e) {
                // Response already sent using classical PHP (echo/header)
                // Nothing more to do - but still trigger after-request hook
                return;

            } catch (\Throwable $e) {
                // Convert exception to response
                $response = $this->exceptionConverters->convert($e, ResponseInterface::class);

                if ($response === null) {
                    // No exception converter registered - rethrow
                    $exception = $e;
                    throw $e;
                }
            }

            // 9. Emit response to browser
            $this->emitResponse($response);

        } catch (\Throwable $e) {
            // Last resort error handling
            $exception = $e;
            $this->handleFatalError($e);
        } finally {
            // 10. Always trigger after-request hook for cleanup (session save, logging, etc.)
            if ($this->currentServerRequest !== null) {
                $this->onAfterRequest->trigger($this->currentServerRequest, $response, $exception);
            }
        }
    }

    /**
     * Build middleware chain wrapper around the final handler
     *
     * Wraps the handler (Router) with all registered middleware in reverse order
     * to ensure FIFO execution (first added middleware executes first).
     *
     * @param RequestHandlerInterface $handler Final handler (typically Router)
     * @return RequestHandlerInterface Wrapped handler with middleware chain
     */
    private function buildMiddlewareChain(RequestHandlerInterface $handler): RequestHandlerInterface
    {
        // If no middleware registered, return handler as-is
        if (empty($this->middlewares)) {
            return $handler;
        }

        // Wrap handler with middleware in reverse order (FIFO execution)
        // Last middleware in array wraps the handler first
        for ($i = count($this->middlewares) - 1; $i >= 0; $i--) {
            $middleware = $this->middlewares[$i];
            $handler = new class($middleware, $handler) implements RequestHandlerInterface {
                public function __construct(
                    private MiddlewareInterface $middleware,
                    private RequestHandlerInterface $next
                ) {}

                public function handle(ServerRequestInterface $request): ResponseInterface {
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
     * Called once during HttpDispatcher construction. Idempotent - safe to call multiple times.
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
     * Last resort error handling - renders a detailed error page in debug mode,
     * or a simple error page in production.
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
        http_response_code($statusCode);

        if (Mini::$mini->debug) {
            // Detailed debug error page
            echo $this->renderDebugErrorPage($e);
        } else {
            // Simple production error page
            echo $this->renderProductionErrorPage($statusCode);
        }
    }

    /**
     * Render detailed error page for debug mode
     *
     * Shows exception type, message, stack trace, and context information.
     *
     * @param \Throwable $e
     * @return string
     */
    private function renderDebugErrorPage(\Throwable $e): string
    {
        $exceptionClass = get_class($e);
        $message = htmlspecialchars($e->getMessage());
        $file = htmlspecialchars($e->getFile());
        $line = $e->getLine();
        $code = $e->getCode();

        // Get stack trace
        $trace = $e->getTraceAsString();
        $traceHtml = htmlspecialchars($trace);

        // Get source code context (5 lines before and after)
        $sourceContext = $this->getSourceContext($e->getFile(), $e->getLine(), 5);

        // Get previous exceptions
        $previousHtml = '';
        $previous = $e->getPrevious();
        if ($previous) {
            $previousList = [];
            while ($previous) {
                $prevClass = htmlspecialchars(get_class($previous));
                $prevMessage = htmlspecialchars($previous->getMessage());
                $prevFile = htmlspecialchars($previous->getFile());
                $prevLine = $previous->getLine();
                $previousList[] = "<li><strong>$prevClass</strong>: $prevMessage<br><small>in $prevFile:$prevLine</small></li>";
                $previous = $previous->getPrevious();
            }
            $previousHtml = '<h2>Previous Exceptions</h2><ul>' . implode('', $previousList) . '</ul>';
        }

        // Request information
        $requestInfo = '';
        try {
            if ($this->currentServerRequest) {
                $method = htmlspecialchars($this->currentServerRequest->getMethod());
                $uri = htmlspecialchars((string)$this->currentServerRequest->getUri());
                $requestInfo = "<h2>Request Information</h2>
                <p><strong>Method:</strong> $method</p>
                <p><strong>URI:</strong> $uri</p>";
            }
        } catch (\Throwable $ignored) {
            // Ignore errors getting request info
        }

        return "<!DOCTYPE html>
<html lang=\"en\">
<head>
    <meta charset=\"UTF-8\">
    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
    <title>Error - $exceptionClass</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #1a1a1a;
            color: #e0e0e0;
            padding: 20px;
            line-height: 1.6;
        }
        .container { max-width: 1200px; margin: 0 auto; }
        h1 {
            color: #ff6b6b;
            font-size: 28px;
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 2px solid #333;
        }
        h2 {
            color: #4ecdc4;
            font-size: 20px;
            margin-top: 30px;
            margin-bottom: 15px;
            padding-bottom: 8px;
            border-bottom: 1px solid #333;
        }
        .error-header {
            background: #2d2d2d;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #ff6b6b;
        }
        .error-type {
            font-size: 18px;
            color: #ff6b6b;
            font-weight: bold;
            margin-bottom: 10px;
        }
        .error-message {
            font-size: 16px;
            margin-bottom: 15px;
            color: #fff;
        }
        .error-location {
            font-family: 'Courier New', monospace;
            font-size: 14px;
            color: #95a5a6;
        }
        .error-code {
            color: #f39c12;
            font-size: 14px;
            margin-top: 5px;
        }
        .section {
            background: #2d2d2d;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .source-code {
            background: #1e1e1e;
            border-radius: 4px;
            overflow-x: auto;
            margin-top: 10px;
        }
        .source-line {
            font-family: 'Courier New', monospace;
            font-size: 13px;
            padding: 4px 10px;
            border-left: 3px solid transparent;
        }
        .source-line-number {
            display: inline-block;
            width: 50px;
            color: #666;
            text-align: right;
            margin-right: 15px;
            user-select: none;
        }
        .source-line-error {
            background: #3d2020;
            border-left-color: #ff6b6b;
        }
        .source-line-error .source-line-number {
            color: #ff6b6b;
            font-weight: bold;
        }
        .stack-trace {
            background: #1e1e1e;
            padding: 15px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            overflow-x: auto;
            white-space: pre;
            color: #95a5a6;
        }
        ul { list-style: none; }
        li {
            padding: 10px;
            margin: 5px 0;
            background: #1e1e1e;
            border-radius: 4px;
        }
        small { color: #95a5a6; }
        p { margin: 10px 0; }
        strong { color: #4ecdc4; }
        .debug-badge {
            display: inline-block;
            background: #f39c12;
            color: #000;
            padding: 4px 12px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class=\"container\">
        <div class=\"debug-badge\">🐛 DEBUG MODE</div>

        <div class=\"error-header\">
            <div class=\"error-type\">$exceptionClass</div>
            <div class=\"error-message\">$message</div>
            <div class=\"error-location\">📁 $file:$line</div>
            " . ($code ? "<div class=\"error-code\">Code: $code</div>" : "") . "
        </div>

        $requestInfo

        <div class=\"section\">
            <h2>Source Code Context</h2>
            <div class=\"source-code\">$sourceContext</div>
        </div>

        <div class=\"section\">
            <h2>Stack Trace</h2>
            <div class=\"stack-trace\">$traceHtml</div>
        </div>

        $previousHtml
    </div>
</body>
</html>";
    }

    /**
     * Render simple error page for production
     *
     * @param int $statusCode
     * @return string
     */
    private function renderProductionErrorPage(int $statusCode): string
    {
        return "<!DOCTYPE html>
<html lang=\"en\">
<head>
    <meta charset=\"UTF-8\">
    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
    <title>$statusCode - Error</title>
    <style>
        body {
            font-family: system-ui, -apple-system, sans-serif;
            max-width: 600px;
            margin: 100px auto;
            padding: 20px;
            text-align: center;
        }
        h1 { color: #dc3545; font-size: 48px; margin-bottom: 20px; }
        p { color: #666; font-size: 18px; }
    </style>
</head>
<body>
    <h1>$statusCode</h1>
    <p>Internal Server Error</p>
</body>
</html>";
    }

    /**
     * Get source code context around the error line
     *
     * @param string $file
     * @param int $errorLine
     * @param int $contextLines Number of lines before and after to show
     * @return string
     */
    private function getSourceContext(string $file, int $errorLine, int $contextLines = 5): string
    {
        if (!is_readable($file)) {
            return '<div class="source-line">Unable to read source file</div>';
        }

        $lines = file($file);
        if ($lines === false) {
            return '<div class="source-line">Unable to read source file</div>';
        }

        $startLine = max(1, $errorLine - $contextLines);
        $endLine = min(count($lines), $errorLine + $contextLines);

        $html = '';
        for ($i = $startLine; $i <= $endLine; $i++) {
            $lineContent = htmlspecialchars(rtrim($lines[$i - 1]));
            $isErrorLine = ($i === $errorLine);
            $class = $isErrorLine ? 'source-line source-line-error' : 'source-line';
            $html .= "<div class=\"$class\"><span class=\"source-line-number\">$i</span>$lineContent</div>";
        }

        return $html;
    }

    /**
     * Create ServerRequest from PHP superglobals
     *
     * SAPI-specific logic for creating ServerRequest from PHP globals.
     * Future FastCGI/fiber-based dispatchers will have their own creation logic.
     */
    private function createServerRequestFromGlobals(): ServerRequestInterface
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $requestTarget = $_SERVER['REQUEST_URI'] ?? '/';
        $body = Stream::create(fopen('php://input', 'r'));
        $headers = $this->extractHeadersFromServer($_SERVER);
        $protocolVersion = isset($_SERVER['SERVER_PROTOCOL'])
            ? str_replace('HTTP/', '', $_SERVER['SERVER_PROTOCOL'])
            : '1.1';

        $uploadedFiles = $this->normalizeFiles($_FILES);

        return new ServerRequest(
            method: $method,
            requestTarget: $requestTarget,
            body: $body,
            headers: $headers,
            queryParams: null, // Derive from request target
            serverParams: $_SERVER,
            cookieParams: $_COOKIE,
            uploadedFiles: $uploadedFiles,
            parsedBody: $_POST,
            protocolVersion: $protocolVersion
        );
    }

    /**
     * Extract headers from $_SERVER
     */
    private function extractHeadersFromServer(array $server): array
    {
        $headers = [];

        foreach ($server as $key => $value) {
            // HTTP_ prefix headers
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', substr($key, 5));
                $headers[$name] = [$value];
                continue;
            }

            // Special case headers without HTTP_ prefix
            if (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'], true)) {
                $name = str_replace('_', '-', $key);
                $headers[$name] = [$value];
            }
        }

        return $headers;
    }

    /**
     * Normalize $_FILES array to UploadedFileInterface instances
     */
    private function normalizeFiles(array $files): array
    {
        $normalized = [];

        foreach ($files as $key => $value) {
            if ($value instanceof UploadedFileInterface) {
                $normalized[$key] = $value;
            } elseif (is_array($value) && isset($value['tmp_name'])) {
                $normalized[$key] = $this->createUploadedFileFromSpec($value);
            } elseif (is_array($value)) {
                $normalized[$key] = $this->normalizeFiles($value);
            }
        }

        return $normalized;
    }

    /**
     * Create UploadedFile instance from $_FILES specification
     */
    private function createUploadedFileFromSpec(array $spec): UploadedFileInterface|array
    {
        if (!is_array($spec['tmp_name'])) {
            // Single file
            $stream = Stream::create($spec['tmp_name']);

            return new UploadedFile(
                $stream,
                $spec['size'] ?? null,
                $spec['error'] ?? \UPLOAD_ERR_OK,
                $spec['name'] ?? null,
                $spec['type'] ?? null
            );
        }

        // Multiple files - normalize nested structure
        $files = [];
        foreach (array_keys($spec['tmp_name']) as $key) {
            $files[$key] = $this->createUploadedFileFromSpec([
                'tmp_name' => $spec['tmp_name'][$key],
                'size' => $spec['size'][$key] ?? null,
                'error' => $spec['error'][$key] ?? \UPLOAD_ERR_OK,
                'name' => $spec['name'][$key] ?? null,
                'type' => $spec['type'][$key] ?? null,
            ]);
        }

        return $files;
    }
}
