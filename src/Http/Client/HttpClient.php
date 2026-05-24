<?php

namespace mini\Http\Client;

use mini\Http\Message\Response;
use mini\Http\Message\Stream;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * PSR-18 compliant HTTP client built on curl
 *
 * Usage:
 * ```php
 * $client = new HttpClient();
 *
 * // PSR-18: Send a PSR-7 request
 * $request = new Request('GET', 'https://api.example.com/users');
 * $response = $client->sendRequest($request);
 *
 * // Convenience methods
 * $response = $client->get('https://api.example.com/users');
 * $response = $client->post('https://api.example.com/users', ['name' => 'John']);
 * $response = $client->postJson('https://api.example.com/users', ['name' => 'John']);
 *
 * // With options
 * $client = new HttpClient([
 *     'timeout' => 30,
 *     'verify_ssl' => true,
 *     'follow_redirects' => true,
 *     'max_redirects' => 5,
 * ]);
 * ```
 */
class HttpClient implements ClientInterface
{
    /** @var array Default options */
    private array $options;

    /** @var array Default curl options */
    private const DEFAULT_OPTIONS = [
        'timeout' => 30,
        'connect_timeout' => 10,
        'verify_ssl' => true,
        'follow_redirects' => true,
        'max_redirects' => 5,
        'user_agent' => 'Mini/1.0 (PSR-18 HTTP Client)',
        'headers' => [],
    ];

    public function __construct(array $options = [])
    {
        $this->options = array_merge(self::DEFAULT_OPTIONS, $options);
    }

    /**
     * PSR-18: Send a PSR-7 request and return a PSR-7 response
     *
     * @throws NetworkException On network errors
     * @throws RequestException On malformed requests
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        if (!extension_loaded('curl')) {
            throw new ClientException('The curl extension is required for HttpClient');
        }

        $curl = curl_init();

        try {
            $this->configureCurl($curl, $request);
            return $this->executeCurl($curl, $request);
        } finally {
            curl_close($curl);
        }
    }

    /**
     * Configure curl handle from PSR-7 request
     */
    private function configureCurl(\CurlHandle $curl, RequestInterface $request): void
    {
        $url = (string) $request->getUri();
        $method = $request->getMethod();

        // Basic setup
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => $this->options['timeout'],
            CURLOPT_CONNECTTIMEOUT => $this->options['connect_timeout'],
            CURLOPT_USERAGENT => $this->options['user_agent'],
            CURLOPT_SSL_VERIFYPEER => $this->options['verify_ssl'],
            CURLOPT_SSL_VERIFYHOST => $this->options['verify_ssl'] ? 2 : 0,
            CURLOPT_FOLLOWLOCATION => $this->options['follow_redirects'],
            CURLOPT_MAXREDIRS => $this->options['max_redirects'],
            CURLOPT_ENCODING => '', // Accept all encodings
        ]);

        // HTTP method
        switch (strtoupper($method)) {
            case 'GET':
                curl_setopt($curl, CURLOPT_HTTPGET, true);
                break;
            case 'POST':
                curl_setopt($curl, CURLOPT_POST, true);
                break;
            case 'HEAD':
                curl_setopt($curl, CURLOPT_NOBODY, true);
                break;
            case 'PUT':
            case 'DELETE':
            case 'PATCH':
            case 'OPTIONS':
                curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
                break;
            default:
                curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
        }

        // Request body
        $body = $request->getBody();
        $bodyContent = (string) $body;
        if ($bodyContent !== '' && !in_array(strtoupper($method), ['GET', 'HEAD'])) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $bodyContent);
        }

        // Headers
        $headers = $this->buildHeaders($request);
        if (!empty($headers)) {
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        }

        // HTTP version
        $version = $request->getProtocolVersion();
        if ($version === '1.0') {
            curl_setopt($curl, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_0);
        } elseif ($version === '1.1') {
            curl_setopt($curl, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        } elseif ($version === '2' || $version === '2.0') {
            curl_setopt($curl, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2);
        }
    }

    /**
     * Build headers array for curl
     */
    private function buildHeaders(RequestInterface $request): array
    {
        $headers = [];

        // Add default headers
        foreach ($this->options['headers'] as $name => $value) {
            $headers[] = "$name: $value";
        }

        // Add request headers (overrides defaults)
        foreach ($request->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $headers[] = "$name: $value";
            }
        }

        return $headers;
    }

    /**
     * Execute curl and return PSR-7 response
     */
    private function executeCurl(\CurlHandle $curl, RequestInterface $request): ResponseInterface
    {
        $result = curl_exec($curl);

        if ($result === false) {
            $errno = curl_errno($curl);
            $error = curl_error($curl);

            // Network errors
            if (in_array($errno, [
                CURLE_COULDNT_RESOLVE_HOST,
                CURLE_COULDNT_CONNECT,
                CURLE_OPERATION_TIMEDOUT,
                CURLE_SSL_CONNECT_ERROR,
                CURLE_GOT_NOTHING,
                CURLE_RECV_ERROR,
                CURLE_SEND_ERROR,
            ])) {
                throw new NetworkException($request, "Network error: $error", $errno);
            }

            throw new RequestException($request, "Request failed: $error", $errno);
        }

        return $this->parseResponse($result, $curl);
    }

    /**
     * Parse curl response into PSR-7 Response
     */
    private function parseResponse(string $result, \CurlHandle $curl): ResponseInterface
    {
        $headerSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        $statusCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        $headerString = substr($result, 0, $headerSize);
        $body = substr($result, $headerSize);

        // Parse headers
        $headers = [];
        $protocolVersion = '1.1';
        $reasonPhrase = '';

        $lines = explode("\r\n", trim($headerString));
        foreach ($lines as $line) {
            // Skip empty lines
            if ($line === '') {
                continue;
            }

            // Status line
            if (str_starts_with($line, 'HTTP/')) {
                if (preg_match('#^HTTP/(\d+\.?\d*)\s+(\d+)\s*(.*)$#', $line, $matches)) {
                    $protocolVersion = $matches[1];
                    $statusCode = (int) $matches[2];
                    $reasonPhrase = $matches[3];
                }
                continue;
            }

            // Header line
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $name = trim($parts[0]);
                $value = trim($parts[1]);
                $headers[$name][] = $value;
            }
        }

        return new Response(
            body: Stream::cast($body),
            headers: $headers,
            statusCode: $statusCode,
            reasonPhrase: $reasonPhrase,
            protocolVersion: $protocolVersion
        );
    }

    // =========================================================================
    // Convenience methods
    // =========================================================================

    /**
     * Send a GET request
     */
    public function get(string $url, array $headers = []): ResponseInterface
    {
        return $this->request('GET', $url, null, $headers);
    }

    /**
     * Send a POST request with form data
     */
    public function post(string $url, array|string $data = [], array $headers = []): ResponseInterface
    {
        if (is_array($data)) {
            $data = http_build_query($data);
            $headers['Content-Type'] ??= 'application/x-www-form-urlencoded';
        }
        return $this->request('POST', $url, $data, $headers);
    }

    /**
     * Send a POST request with JSON body
     */
    public function postJson(string $url, mixed $data, array $headers = []): ResponseInterface
    {
        $headers['Content-Type'] = 'application/json';
        return $this->request('POST', $url, json_encode($data), $headers);
    }

    /**
     * Send a PUT request
     */
    public function put(string $url, array|string $data = [], array $headers = []): ResponseInterface
    {
        if (is_array($data)) {
            $data = http_build_query($data);
            $headers['Content-Type'] ??= 'application/x-www-form-urlencoded';
        }
        return $this->request('PUT', $url, $data, $headers);
    }

    /**
     * Send a PUT request with JSON body
     */
    public function putJson(string $url, mixed $data, array $headers = []): ResponseInterface
    {
        $headers['Content-Type'] = 'application/json';
        return $this->request('PUT', $url, json_encode($data), $headers);
    }

    /**
     * Send a PATCH request
     */
    public function patch(string $url, array|string $data = [], array $headers = []): ResponseInterface
    {
        if (is_array($data)) {
            $data = http_build_query($data);
            $headers['Content-Type'] ??= 'application/x-www-form-urlencoded';
        }
        return $this->request('PATCH', $url, $data, $headers);
    }

    /**
     * Send a PATCH request with JSON body
     */
    public function patchJson(string $url, mixed $data, array $headers = []): ResponseInterface
    {
        $headers['Content-Type'] = 'application/json';
        return $this->request('PATCH', $url, json_encode($data), $headers);
    }

    /**
     * Send a DELETE request
     */
    public function delete(string $url, array $headers = []): ResponseInterface
    {
        return $this->request('DELETE', $url, null, $headers);
    }

    /**
     * Send a HEAD request
     */
    public function head(string $url, array $headers = []): ResponseInterface
    {
        return $this->request('HEAD', $url, null, $headers);
    }

    /**
     * Send a request with custom method
     *
     * @param string $method HTTP method
     * @param string $url Target URL
     * @param string|StreamInterface|null $body Request body (string or stream)
     * @param array $headers Request headers
     */
    public function request(string $method, string $url, string|\Psr\Http\Message\StreamInterface|null $body = null, array $headers = []): ResponseInterface
    {
        // Use Request::create() which properly parses full URLs into URI components
        $request = \mini\Http\Message\Request::create($method, $url);

        // Add headers
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        // Add body if provided
        if ($body !== null) {
            if ($body instanceof \Psr\Http\Message\StreamInterface) {
                $request = $request->withBody($body);
            } elseif ($body !== '') {
                $request = $request->withBody(Stream::cast($body));
            }
        }

        return $this->sendRequest($request);
    }
}
