<?php
/**
 * Test SessionMiddleware cookie formatting
 */

require __DIR__ . '/../../ensure-autoloader.php';
require __DIR__ . '/../assert.php';

use mini\Session\SessionMiddleware;

// =============================================================================
// Test the formatCookie method via reflection
// =============================================================================

echo "Testing SessionMiddleware...\n";

$middleware = new SessionMiddleware();
$ref = new ReflectionMethod($middleware, 'formatCookie');
$ref->setAccessible(true);

// Test: Basic cookie formatting
$cookie = [
    'name' => 'PHPSESSID',
    'value' => 'abc123',
    'options' => [
        'expires' => strtotime('2026-01-15 12:00:00 UTC'),
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ],
];

$result = $ref->invoke($middleware, $cookie);
assert_contains('PHPSESSID=abc123', $result);
assert_contains('Path=/', $result);
assert_contains('HttpOnly', $result);
assert_contains('SameSite=Lax', $result);
assert_contains('Expires=Thu, 15 Jan 2026 12:00:00 GMT', $result);
echo "  ✓ Basic cookie formatting works\n";

// Test: Cookie with domain
$cookie['options']['domain'] = '.example.com';
$result = $ref->invoke($middleware, $cookie);
assert_contains('Domain=.example.com', $result);
echo "  ✓ Domain attribute works\n";

// Test: Cookie with explicit secure flag
unset($cookie['options']['domain']);
$cookie['options']['secure'] = true;
$result = $ref->invoke($middleware, $cookie);
assert_contains('Secure', $result);
echo "  ✓ Secure attribute works\n";

// Test: Cookie name/value encoding
$cookie = [
    'name' => 'session id',  // Space in name (unusual but test encoding)
    'value' => 'value with spaces & special=chars',
    'options' => ['path' => '/'],
];
$result = $ref->invoke($middleware, $cookie);
assert_contains('session%20id=', $result);
assert_contains('value%20with%20spaces', $result);
echo "  ✓ Cookie name/value URL encoding works\n";

// Test: Minimal cookie (no optional attributes)
$cookie = [
    'name' => 'simple',
    'value' => 'test',
    'options' => [],
];
$result = $ref->invoke($middleware, $cookie);
assert_eq('simple=test', $result);
echo "  ✓ Minimal cookie works\n";

// Test: Expiration cookie (for destroy)
$cookie = [
    'name' => 'PHPSESSID',
    'value' => '',
    'options' => [
        'expires' => 1,
        'path' => '/',
    ],
];
$result = $ref->invoke($middleware, $cookie);
assert_contains('PHPSESSID=', $result);
assert_contains('Expires=Thu, 01 Jan 1970 00:00:01 GMT', $result);
echo "  ✓ Expiration cookie formatting works\n";

// =============================================================================
// Test applyCacheLimiter method
// =============================================================================

// Create a mock response for testing
$mockResponse = new class implements \Psr\Http\Message\ResponseInterface {
    private array $headers = [];
    private int $statusCode = 200;

    public function getStatusCode(): int { return $this->statusCode; }
    public function withStatus(int $code, string $reasonPhrase = ''): static {
        $new = clone $this;
        $new->statusCode = $code;
        return $new;
    }
    public function getReasonPhrase(): string { return ''; }
    public function getProtocolVersion(): string { return '1.1'; }
    public function withProtocolVersion(string $version): static { return $this; }
    public function getHeaders(): array { return $this->headers; }
    public function hasHeader(string $name): bool { return isset($this->headers[strtolower($name)]); }
    public function getHeader(string $name): array { return $this->headers[strtolower($name)] ?? []; }
    public function getHeaderLine(string $name): string { return implode(', ', $this->getHeader($name)); }
    public function withHeader(string $name, $value): static {
        $new = clone $this;
        $new->headers[strtolower($name)] = is_array($value) ? $value : [$value];
        return $new;
    }
    public function withAddedHeader(string $name, $value): static {
        $new = clone $this;
        $existing = $new->headers[strtolower($name)] ?? [];
        $new->headers[strtolower($name)] = array_merge($existing, is_array($value) ? $value : [$value]);
        return $new;
    }
    public function withoutHeader(string $name): static {
        $new = clone $this;
        unset($new->headers[strtolower($name)]);
        return $new;
    }
    public function getBody(): \Psr\Http\Message\StreamInterface {
        return new class implements \Psr\Http\Message\StreamInterface {
            public function __toString(): string { return ''; }
            public function close(): void {}
            public function detach() { return null; }
            public function getSize(): ?int { return 0; }
            public function tell(): int { return 0; }
            public function eof(): bool { return true; }
            public function isSeekable(): bool { return false; }
            public function seek(int $offset, int $whence = SEEK_SET): void {}
            public function rewind(): void {}
            public function isWritable(): bool { return false; }
            public function write(string $string): int { return 0; }
            public function isReadable(): bool { return false; }
            public function read(int $length): string { return ''; }
            public function getContents(): string { return ''; }
            public function getMetadata(?string $key = null) { return null; }
        };
    }
    public function withBody(\Psr\Http\Message\StreamInterface $body): static { return $this; }
};

$refMethod = new ReflectionMethod($middleware, 'applyCacheLimiter');
$refMethod->setAccessible(true);

// Test: nocache limiter (default)
$result = $refMethod->invoke($middleware, $mockResponse);
assert_true($result->hasHeader('Cache-Control'), 'nocache should set Cache-Control');
assert_contains('no-store', $result->getHeaderLine('Cache-Control'));
assert_contains('no-cache', $result->getHeaderLine('Cache-Control'));
assert_true($result->hasHeader('Pragma'), 'nocache should set Pragma for HTTP/1.0');
assert_true($result->hasHeader('Expires'), 'nocache should set Expires');
echo "  ✓ Cache limiter 'nocache' sets correct headers\n";

// Test: Restricts existing Cache-Control (session + caching = bug)
$responseWithCache = $mockResponse->withHeader('Cache-Control', 'public, max-age=3600');
$result = $refMethod->invoke($middleware, $responseWithCache);
$cacheControl = $result->getHeaderLine('Cache-Control');
assert_contains('no-store', $cacheControl, 'Should restrict to no-store');
assert_contains('no-cache', $cacheControl, 'Should add no-cache');
assert_false(str_contains($cacheControl, 'public'), 'Should remove public');
echo "  ✓ Cache limiter restricts existing Cache-Control (prevents caching bug)\n";

echo "\nAll SessionMiddleware tests passed!\n";
