<?php

namespace mini\Dispatcher;

use mini\Mini;
use mini\Converter\ConverterRegistryInterface;
use Psr\Http\Message\{ServerRequestInterface, ResponseInterface, StreamInterface, UploadedFileInterface};
use Psr\Http\Server\{RequestHandlerInterface, MiddlewareInterface};
use mini\Http\Message\{ServerRequest, Stream, UploadedFile};

/**
 * HTTP request dispatcher
 *
 * The HttpDispatcher is the entry point for HTTP requests in PHP's own SAPIs (FPM, the built-in
 * server). It:
 * 1. Creates PSR-7 ServerRequest from PHP globals
 * 2. Hands it to the RequestDispatcher: middleware, the Router, exception conversion
 * 3. Emits the response to the browser, with Range support
 * 4. Shows a last-resort error page for an exception nothing converted
 *
 * Architecture:
 * HttpDispatcher (SAPI) → RequestDispatcher (PSR-15 pipeline) → Router → Controllers
 *
 * Application servers that speak PSR-15, such as Swerve, use the RequestDispatcher directly.
 * The registration methods here (addMiddleware(), registerExceptionConverter(), the request
 * hooks) are the RequestDispatcher's, so what is registered applies to both.
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
    private RequestDispatcher $requests;

    /**
     * Event triggered before processing a request: the RequestDispatcher's
     *
     * @var \mini\Hooks\Event<ServerRequestInterface>
     */
    public readonly \mini\Hooks\Event $onBeforeRequest;

    /**
     * Event triggered after processing a request: the RequestDispatcher's
     *
     * @var \mini\Hooks\Event<ServerRequestInterface, ?ResponseInterface, ?\Throwable>
     */
    public readonly \mini\Hooks\Event $onAfterRequest;

    /**
     * @param RequestHandlerInterface|null $requestHandler a final handler instead of the Router,
     *                                                    with a RequestDispatcher of its own
     */
    public function __construct(?RequestHandlerInterface $requestHandler = null)
    {
        $this->requests = null === $requestHandler ? Mini::$mini->get(RequestDispatcher::class) : new RequestDispatcher($requestHandler);
        $this->onBeforeRequest = $this->requests->onBeforeRequest;
        $this->onAfterRequest = $this->requests->onAfterRequest;
    }

    /**
     * Add middleware to the request pipeline, see RequestDispatcher::addMiddleware()
     */
    public function addMiddleware(MiddlewareInterface $middleware): self
    {
        $this->requests->addMiddleware($middleware);

        return $this;
    }

    /**
     * Register an exception converter, see RequestDispatcher::registerExceptionConverter()
     */
    public function registerExceptionConverter(\Closure $converter): void
    {
        $this->requests->registerExceptionConverter($converter);
    }

    /**
     * Dispatch the current HTTP request
     *
     * Creates the PSR-7 ServerRequest from PHP's request globals, handles it with the
     * RequestDispatcher, and emits the response. An exception nothing converted gets the
     * last-resort error page.
     *
     * @return void
     */
    public function dispatch(): void
    {
        $request = null;
        try {
            $request = $this->createServerRequestFromGlobals();
            $this->emitResponse($this->requests->handle($request), $request);
        } catch (\Throwable $e) {
            // Last resort error handling
            $this->handleFatalError($e, $request);
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
    private function emitResponse(ResponseInterface $response, ServerRequestInterface $request): void
    {
        $body = $response->getBody();
        $size = $this->resolveBodySize($response, $body);
        $rangeable = $body->isSeekable() && $size !== null;

        // Advertise Range support whenever we can actually honor it.
        if ($rangeable && !$response->hasHeader('Accept-Ranges')) {
            $response = $response->withHeader('Accept-Ranges', 'bytes');
        }

        // Apply Range request if possible. Only single byte-ranges — multi-range
        // requires multipart/byteranges responses, which we don't generate.
        $startOffset = 0;
        $remaining = $size; // null = unknown length, stream until eof

        $rangeHeader = $request->getHeaderLine('Range');

        if ($rangeable
            && $rangeHeader !== ''
            && $response->getStatusCode() === 200
            && !$response->hasHeader('Content-Range')
        ) {
            $parsed = $this->parseRangeHeader($rangeHeader, $size);
            if ($parsed === false) {
                $response = $response
                    ->withStatus(416)
                    ->withHeader('Content-Range', "bytes */$size")
                    ->withHeader('Content-Length', '0');
                $remaining = 0;
            } elseif ($parsed !== null) {
                [$start, $end] = $parsed;
                $startOffset = $start;
                $remaining = $end - $start + 1;
                $response = $response
                    ->withStatus(206)
                    ->withHeader('Content-Range', "bytes $start-$end/$size")
                    ->withHeader('Content-Length', (string) $remaining);
            }
        }

        http_response_code($response->getStatusCode());
        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                header("$name: $value", false);
            }
        }

        // Long downloads must not be killed by max_execution_time.
        @set_time_limit(0);

        // Drop any output buffering layers (ini output_buffering, ob_start
        // elsewhere) so chunks go to the client immediately instead of
        // accumulating in PHP-side buffers.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // Stream body in chunks so multi-GB responses don't materialize in
        // memory and so the client sees bytes promptly. A future coroutine-aware
        // StreamInterface can have a writer coroutine feeding the stream while
        // this loop reads from it.
        if ($body->isSeekable() && $startOffset > 0) {
            $body->seek($startOffset);
        } elseif ($body->isSeekable() && $startOffset === 0) {
            $body->rewind();
        }

        while ($remaining === null || $remaining > 0) {
            if (connection_aborted() || $body->eof()) {
                break;
            }
            $chunkSize = $remaining !== null ? min(65536, $remaining) : 65536;
            $buf = $body->read($chunkSize);
            if ($buf === '') {
                break;
            }
            echo $buf;
            flush();
            if ($remaining !== null) {
                $remaining -= strlen($buf);
            }
        }
    }

    /**
     * Resolve body size in bytes, preferring the Content-Length header set by
     * the application, falling back to the stream's reported size. Returns
     * null when the size is not known — in that case the dispatcher streams
     * until EOF and cannot honor Range requests.
     */
    private function resolveBodySize(ResponseInterface $response, StreamInterface $body): ?int
    {
        $headerLen = $response->getHeaderLine('Content-Length');
        if ($headerLen !== '' && ctype_digit($headerLen)) {
            return (int) $headerLen;
        }
        $size = $body->getSize();
        return $size === null ? null : (int) $size;
    }

    /**
     * Parse a `Range: bytes=...` header against a known total size.
     *
     * Returns [start, end] (inclusive) on a satisfiable single range,
     * false when unsatisfiable (caller must respond 416),
     * null when unparseable or multi-range (caller falls back to 200 full body).
     *
     * @return array{0:int,1:int}|false|null
     */
    private function parseRangeHeader(string $header, int $size): array|false|null
    {
        if (!preg_match('/^\s*bytes=(.+)$/i', $header, $m)) {
            return null;
        }
        $spec = trim($m[1]);
        if ($spec === '' || str_contains($spec, ',')) {
            return null;
        }
        if (!preg_match('/^(\d*)-(\d*)$/', $spec, $r)) {
            return null;
        }
        [$_, $startStr, $endStr] = $r;
        if ($startStr === '' && $endStr === '') {
            return null;
        }
        if ($startStr === '') {
            // suffix range: last N bytes
            $n = (int) $endStr;
            if ($n === 0) {
                return false;
            }
            $start = max(0, $size - $n);
            $end = $size - 1;
        } else {
            $start = (int) $startStr;
            $end = $endStr === '' ? $size - 1 : (int) $endStr;
        }
        if ($start > $end || $start >= $size) {
            return false;
        }
        if ($end >= $size) {
            $end = $size - 1;
        }
        return [$start, $end];
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
    private function handleFatalError(\Throwable $e, ?ServerRequestInterface $request): void
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
            if ($request) {
                $method = htmlspecialchars($request->getMethod());
                $uri = htmlspecialchars((string)$request->getUri());
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
            // Single file — pass tmp_name as file path
            // UploadedFile(source, clientFilename, clientMediaType, size, error)
            return new UploadedFile(
                $spec['tmp_name'],
                $spec['name'] ?? null,
                $spec['type'] ?? null,
                isset($spec['size']) ? (int) $spec['size'] : null,
                isset($spec['error']) ? (int) $spec['error'] : \UPLOAD_ERR_OK,
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
