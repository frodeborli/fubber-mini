<?php
/**
 * Local HTTP fixture server for the HttpClient test suite.
 *
 * Started by tests/Http/Client/HttpClient.php as:
 *
 *     php -S 127.0.0.1:<port> tests/Http/Client/_fixture-server.php
 *
 * It implements the handful of httpbin.org endpoints the HTTP client tests used
 * to reach over the public internet, so the default test suite is deterministic
 * and passes offline. Deliberately dependency-free plain PHP: the subject under
 * test is the client, not the server.
 *
 * Endpoints:
 *   GET    /get                      → {method, url, args, headers}
 *   POST   /post                     → {method, url, form, data, headers}
 *   PUT    /put, PATCH /patch, DELETE /delete → same shape
 *   GET    /headers                  → {headers: {...}}
 *   GET    /user-agent               → {"user-agent": "..."}
 *   GET    /response-headers?A=B     → echoes query pairs as response headers
 *   GET    /redirect/<n>             → 302 chain of <n> hops, ending at /get
 *   GET    /status/<code>            → empty body with that status code
 *   GET    /delay/<seconds>          → sleeps, then behaves like /get
 *   GET    /ping                     → "pong" (text/plain); the readiness probe
 *                                      the test polls before it starts, so a
 *                                      corrupt fixture fails loudly at startup
 *                                      instead of as N unrelated assertions
 *
 * The file name starts with "_" so the test runner skips it.
 */

declare(strict_types=1);

/** Request headers, canonicalised as Foo-Bar. */
$requestHeaders = static function (): array {
    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (\str_starts_with($key, 'HTTP_')) {
            $name = \strtolower(\str_replace('_', '-', \substr($key, 5)));
        } elseif ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
            $name = \strtolower(\str_replace('_', '-', $key));
        } else {
            continue;
        }
        $headers[\ucwords($name, '-')] = (string) $value;
    }
    \ksort($headers);
    return $headers;
};

$json = static function (array $data, int $status = 200): void {
    \http_response_code($status);
    \header('Content-Type: application/json');
    echo \json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
};

$path = \parse_url($_SERVER['REQUEST_URI'] ?? '/', \PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$url = 'http://' . ($_SERVER['HTTP_HOST'] ?? '127.0.0.1') . ($_SERVER['REQUEST_URI'] ?? '/');
$rawBody = \file_get_contents('php://input') ?: '';

$echo = static function () use ($json, $requestHeaders, $method, $url, $rawBody): void {
    $form = [];
    if (($_SERVER['CONTENT_TYPE'] ?? '') === 'application/x-www-form-urlencoded') {
        \parse_str($rawBody, $form);
    }
    $json([
        'method' => $method,
        'url' => $url,
        'args' => $_GET,
        'form' => $form,
        'data' => $rawBody,
        'json' => \json_decode($rawBody, true),
        'headers' => $requestHeaders(),
    ]);
};

if ($path === '/ping') {
    \header('Content-Type: text/plain');
    echo 'pong';
    return true;
}

if ($path === '/headers') {
    $json(['headers' => $requestHeaders()]);
    return true;
}

if ($path === '/user-agent') {
    $json(['user-agent' => $_SERVER['HTTP_USER_AGENT'] ?? null]);
    return true;
}

if ($path === '/response-headers') {
    foreach ($_GET as $name => $value) {
        if (\preg_match('/^[A-Za-z0-9-]+$/', (string) $name)) {
            \header($name . ': ' . (string) $value, false);
        }
    }
    $json($_GET);
    return true;
}

if (\preg_match('#^/redirect/(\d+)$#', $path, $m)) {
    $remaining = (int) $m[1];
    $target = $remaining > 1 ? '/redirect/' . ($remaining - 1) : '/get';
    \http_response_code(302);
    \header('Location: ' . $target);
    return true;
}

if (\preg_match('#^/status/(\d{3})$#', $path, $m)) {
    \http_response_code((int) $m[1]);
    \header('Content-Type: text/plain');
    return true;
}

if (\preg_match('#^/delay/(\d+)$#', $path, $m)) {
    \sleep((int) $m[1]);
    $echo();
    return true;
}

if (\in_array($path, ['/get', '/post', '/put', '/patch', '/delete'], true)) {
    $echo();
    return true;
}

$json(['error' => 'not found', 'path' => $path], 404);
return true;
