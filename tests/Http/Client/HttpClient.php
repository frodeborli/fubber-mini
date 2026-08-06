<?php
/**
 * HttpClient (PSR-18) tests.
 *
 * These tests exercise the client against a REAL socket, but never against the
 * public internet: a local `php -S` fixture server (_fixture-server.php) is
 * started on a free loopback port for the duration of the file and torn down
 * again from a shutdown handler, so teardown is reliable even when a test fails
 * or the process dies with a fatal error.
 *
 * Consequence: `mini test` is deterministic and passes offline.
 */

declare(strict_types=1);

require __DIR__ . '/../../../ensure-autoloader.php';

use mini\Http\Client\HttpClient;
use mini\Http\Client\NetworkException;
use mini\Http\Message\Request;
use mini\Test;
use Psr\Http\Client\ClientInterface;

$test = new class extends Test {

    private string $base;
    private HttpClient $client;

    /** @var resource|null */
    private $server = null;

    /** Where `php -S` writes its access log and any fixture-side PHP error. */
    private ?string $serverLog = null;

    protected function canRun(): bool
    {
        return \extension_loaded('curl') && \function_exists('proc_open');
    }

    protected function skipReason(): string
    {
        if (!\extension_loaded('curl')) {
            return 'ext-curl is required by mini\Http\Client\HttpClient';
        }
        return 'proc_open() is disabled, cannot start the local fixture server';
    }

    protected function setUp(): void
    {
        $port = $this->startServer();
        $this->base = "http://127.0.0.1:$port";
        $this->client = new HttpClient();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Fixture server
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Start the fixture server on a free loopback port and wait until it answers.
     */
    private function startServer(): int
    {
        $lastError = '';

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $port = $this->freePort();
            $this->serverLog = \tempnam(\sys_get_temp_dir(), 'mini-httpfixture-');

            // stdout is unused by `php -S`; stderr carries the access log and any
            // fatal error in the fixture. Both go to files rather than pipes: an
            // unread pipe would eventually fill its 64 KiB buffer and stall the
            // single threaded fixture, and a discarded pipe would throw away the
            // only diagnostic we have when startup fails.
            $descriptors = [
                ['file', '/dev/null', 'r'],
                ['file', '/dev/null', 'w'],
                ['file', $this->serverLog, 'w'],
            ];
            $process = \proc_open(
                [\PHP_BINARY, '-S', "127.0.0.1:$port", '-t', __DIR__, __DIR__ . '/_fixture-server.php'],
                $descriptors,
                $pipes,
                __DIR__
            );

            if (!\is_resource($process)) {
                $lastError = 'proc_open() failed';
                continue;
            }

            $this->server = $process;
            // Terminate the server whatever happens next - assertion failure,
            // uncaught exception, fatal error or normal exit.
            \register_shutdown_function($this->stopServer(...));

            if ($this->waitUntilReady($port)) {
                return $port;
            }

            $lastError = "server on port $port did not answer GET /ping with \"pong\"";
            $log = $this->serverLog();
            if ($log !== '') {
                $lastError .= "\n--- php -S stderr ---\n" . $log;
            }
            $this->stopServer();
        }

        throw new \RuntimeException("Could not start the local HTTP fixture server: $lastError");
    }

    /**
     * The fixture's stderr, minus per-connection noise: timestamps stripped,
     * duplicates collapsed, last 10 lines kept. A fixture that dies on every
     * request would otherwise bury its one useful line (the PHP error) under a
     * hundred access log entries from the readiness poll.
     */
    private function serverLog(): string
    {
        $raw = (string) @\file_get_contents((string) $this->serverLog);
        $lines = \preg_split('/\R/', (string) \preg_replace('/^\[[^\]]*\] /m', '', $raw)) ?: [];
        $lines = \array_filter(
            $lines,
            static fn(string $line): bool => \trim($line) !== '' && !\preg_match('/ (Accepted|Closing)$/', $line)
        );

        return \implode("\n", \array_slice(\array_unique($lines), -10));
    }

    /**
     * Ask the OS for an unused port, then release it again.
     */
    private function freePort(): int
    {
        $socket = @\stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            throw new \RuntimeException("Cannot bind a loopback port: $errstr ($errno)");
        }
        $name = \stream_socket_get_name($socket, false);
        \fclose($socket);

        return (int) \substr((string) $name, \strrpos((string) $name, ':') + 1);
    }

    /**
     * Poll `GET /ping` until the fixture answers with the literal body `pong`.
     *
     * Deliberately a real request rather than a bare TCP connect: accepting a
     * connection only proves that `php -S` is listening, not that the fixture
     * script runs. A syntax error in _fixture-server.php would still pass a
     * connect probe and then surface as a dozen unrelated assertion failures.
     * Handwritten HTTP/1.0 (no HttpClient) so that a broken client cannot make
     * the fixture look broken.
     */
    private function waitUntilReady(int $port): bool
    {
        $deadline = \microtime(true) + 5.0;

        while (\microtime(true) < $deadline) {
            $status = \proc_get_status($this->server);
            if ($status !== false && !$status['running']) {
                return false;
            }
            if ($this->ping($port)) {
                return true;
            }
            \usleep(50_000);
        }

        return false;
    }

    /** One `GET /ping` attempt; true only when the body is exactly `pong`. */
    private function ping(int $port): bool
    {
        $conn = @\fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if ($conn === false) {
            return false;
        }

        try {
            \stream_set_timeout($conn, 2);
            // HTTP/1.0 - the server closes the connection after the response, so
            // reading to EOF is enough and no chunked/keep-alive parsing is needed.
            $written = @\fwrite($conn, "GET /ping HTTP/1.0\r\nHost: 127.0.0.1:$port\r\n\r\n");
            if ($written === false) {
                return false;
            }
            $raw = '';
            while (!\feof($conn)) {
                $chunk = \fread($conn, 8192);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $raw .= $chunk;
            }
        } finally {
            \fclose($conn);
        }

        $split = \strpos($raw, "\r\n\r\n");

        return $split !== false
            && \str_contains(\substr($raw, 0, $split), ' 200 ')
            && \substr($raw, $split + 4) === 'pong';
    }

    private function stopServer(): void
    {
        if ($this->serverLog !== null) {
            @\unlink($this->serverLog);
            $this->serverLog = null;
        }
        if ($this->server === null) {
            return;
        }
        $process = $this->server;
        $this->server = null;

        \proc_terminate($process); // SIGTERM
        // Give it a moment to exit, then reap it.
        for ($i = 0; $i < 20; $i++) {
            $status = \proc_get_status($process);
            if ($status === false || !$status['running']) {
                break;
            }
            \usleep(25_000);
        }
        \proc_close($process);
    }

    /** Decode a JSON response body, failing the test on malformed JSON. */
    private function decode(\Psr\Http\Message\ResponseInterface $response): array
    {
        $body = (string) $response->getBody();
        $data = \json_decode($body, true);
        if (!\is_array($data)) {
            $this->fail('Expected a JSON object body, got: ' . \substr($body, 0, 200));
        }
        return $data;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Tests
    // ─────────────────────────────────────────────────────────────────────────

    public function testImplementsPsr18(): void
    {
        $this->assertInstanceOf(ClientInterface::class, $this->client);
    }

    public function testGetRequest(): void
    {
        $response = $this->client->get($this->base . '/get?q=mini');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('OK', $response->getReasonPhrase());
        $this->assertSame('1.1', $response->getProtocolVersion());
        $this->assertTrue($response->hasHeader('Content-Type'), 'Should have Content-Type header');
        $this->assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));

        $data = $this->decode($response);
        $this->assertSame('GET', $data['method']);
        $this->assertSame('mini', $data['args']['q'] ?? null, 'Query string should reach the server');
    }

    public function testPostFormData(): void
    {
        $response = $this->client->post($this->base . '/post', ['foo' => 'bar', 'baz' => 'qux']);

        $this->assertSame(200, $response->getStatusCode());
        $data = $this->decode($response);
        $this->assertSame('POST', $data['method']);
        $this->assertSame('bar', $data['form']['foo'] ?? null, 'POST data should be received');
        $this->assertSame('qux', $data['form']['baz'] ?? null);
        $this->assertStringContainsString('application/x-www-form-urlencoded', $data['headers']['Content-Type'] ?? '');
    }

    public function testPostJson(): void
    {
        $response = $this->client->postJson($this->base . '/post', ['name' => 'Mini', 'version' => 1]);

        $this->assertSame(200, $response->getStatusCode());
        $data = $this->decode($response);
        $this->assertSame('Mini', $data['json']['name'] ?? null, 'JSON body should be received');
        $this->assertSame(1, $data['json']['version'] ?? null);
        $this->assertSame('application/json', $data['headers']['Content-Type'] ?? null);
    }

    public function testCustomRequestHeaders(): void
    {
        $response = $this->client->get($this->base . '/headers', ['X-Custom-Header' => 'TestValue']);

        $data = $this->decode($response);
        $this->assertSame('TestValue', $data['headers']['X-Custom-Header'] ?? null, 'Custom header should be sent');
    }

    public function testSendRequestWithPsr7Request(): void
    {
        $request = new Request('GET', $this->base . '/user-agent');
        $response = $this->client->sendRequest($request);

        $this->assertSame(200, $response->getStatusCode());
        $data = $this->decode($response);
        $this->assertStringContainsString('Mini', $data['user-agent'] ?? '', 'Default User-Agent should identify Mini');
    }

    public function testResponseHeaderParsing(): void
    {
        $response = $this->client->get($this->base . '/response-headers?X-Test=Hello');

        $this->assertSame('Hello', $response->getHeaderLine('X-Test'), 'Should parse response headers');
        $this->assertSame('Hello', $response->getHeaderLine('x-test'), 'Header lookup is case-insensitive');
    }

    public function testFollowsRedirects(): void
    {
        // Two hops: /redirect/2 → /redirect/1 → /get
        $response = $this->client->get($this->base . '/redirect/2');

        $this->assertSame(200, $response->getStatusCode(), 'Should follow redirects');
        $data = $this->decode($response);
        $this->assertStringEndsWith('/get', $data['url'] ?? '', 'Should end up at the redirect target');
    }

    public function testRedirectsCanBeDisabled(): void
    {
        $client = new HttpClient(['follow_redirects' => false]);
        $response = $client->get($this->base . '/redirect/1');

        $this->assertSame(302, $response->getStatusCode(), 'Should return the redirect itself');
        $this->assertSame('/get', $response->getHeaderLine('Location'));
    }

    public function testHeadRequest(): void
    {
        $response = $this->client->head($this->base . '/get');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->hasHeader('Content-Type'), 'HEAD should still expose headers');
        $this->assertSame('', (string) $response->getBody(), 'HEAD should have no body');
    }

    public function testDeleteRequest(): void
    {
        $response = $this->client->delete($this->base . '/delete');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('DELETE', $this->decode($response)['method']);
    }

    public function testPutRequest(): void
    {
        $response = $this->client->putJson($this->base . '/put', ['updated' => true]);

        $this->assertSame(200, $response->getStatusCode());
        $data = $this->decode($response);
        $this->assertSame('PUT', $data['method']);
        $this->assertSame(true, $data['json']['updated'] ?? null);
    }

    public function testPatchRequest(): void
    {
        $response = $this->client->patchJson($this->base . '/patch', ['patched' => true]);

        $this->assertSame(200, $response->getStatusCode());
        $data = $this->decode($response);
        $this->assertSame('PATCH', $data['method']);
        $this->assertSame(true, $data['json']['patched'] ?? null);
    }

    public function testClientErrorDoesNotThrow(): void
    {
        // PSR-18: only network/request problems throw, HTTP error statuses do not.
        $response = $this->client->get($this->base . '/status/404');
        $this->assertSame(404, $response->getStatusCode(), '404 should not throw');
    }

    public function testServerErrorDoesNotThrow(): void
    {
        $response = $this->client->get($this->base . '/status/500');
        $this->assertSame(500, $response->getStatusCode(), '500 should not throw');
    }

    public function testCustomOptions(): void
    {
        $client = new HttpClient(['timeout' => 5, 'user_agent' => 'CustomAgent/1.0']);
        $response = $client->get($this->base . '/user-agent');

        $this->assertSame('CustomAgent/1.0', $this->decode($response)['user-agent'] ?? null);
    }

    public function testNetworkExceptionOnRefusedConnection(): void
    {
        // A port nothing is listening on - deterministic and offline-safe
        // (no DNS lookup, no public internet).
        $deadPort = $this->freePort();

        $thrown = false;
        try {
            $this->client->get("http://127.0.0.1:$deadPort/get");
        } catch (NetworkException $e) {
            $thrown = true;
            $this->assertNotNull($e->getRequest(), 'PSR-18 NetworkException must expose the request');
            $this->assertSame(
                "http://127.0.0.1:$deadPort/get",
                (string) $e->getRequest()->getUri(),
                'Exception should carry the failed request'
            );
        }
        $this->assertTrue($thrown, 'Should throw NetworkException when the connection is refused');
    }

    /**
     * Kept last: the fixture server is single threaded, so the slow response
     * would otherwise stall the tests that follow.
     */
    public function testTimeoutThrowsNetworkException(): void
    {
        $client = new HttpClient(['timeout' => 1, 'connect_timeout' => 1]);

        $thrown = false;
        try {
            $client->get($this->base . '/delay/3');
        } catch (NetworkException $e) {
            $thrown = true;
            $this->assertNotNull($e->getRequest(), 'PSR-18 NetworkException must expose the request');
        }
        $this->assertTrue($thrown, 'Exceeding the timeout should throw NetworkException');
    }
};

exit($test->run());
