<?php
/**
 * Test SessionMiddleware cookie formatting
 *
 * Both `formatCookie()` and `applyCacheLimiter()` are protected, so they are
 * exercised through reflection exactly as production traffic would hit them.
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Session\SessionMiddleware;
use mini\Test;

$test = new class extends Test {

    private SessionMiddleware $middleware;
    private ReflectionMethod $formatCookie;
    private ReflectionMethod $applyCacheLimiter;

    protected function setUp(): void
    {
        $this->middleware = new SessionMiddleware();

        $this->formatCookie = new ReflectionMethod($this->middleware, 'formatCookie');
        $this->formatCookie->setAccessible(true);

        $this->applyCacheLimiter = new ReflectionMethod($this->middleware, 'applyCacheLimiter');
        $this->applyCacheLimiter->setAccessible(true);
    }

    /** The cookie the other formatting cases start from. */
    private function baseCookie(): array
    {
        return [
            'name' => 'PHPSESSID',
            'value' => 'abc123',
            'options' => [
                'expires' => strtotime('2026-01-15 12:00:00 UTC'),
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
            ],
        ];
    }

    private function format(array $cookie): string
    {
        return $this->formatCookie->invoke($this->middleware, $cookie);
    }

    /** Minimal PSR-7 response so applyCacheLimiter() can be tested without the HTTP stack. */
    private function mockResponse(): \Psr\Http\Message\ResponseInterface
    {
        return new class implements \Psr\Http\Message\ResponseInterface {
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
    }

    public function testBasicCookieFormatting(): void
    {
        $result = $this->format($this->baseCookie());

        $this->assertContains('PHPSESSID=abc123', $result);
        $this->assertContains('Path=/', $result);
        $this->assertContains('HttpOnly', $result);
        $this->assertContains('SameSite=Lax', $result);
        $this->assertContains('Expires=Thu, 15 Jan 2026 12:00:00 GMT', $result);
    }

    public function testDomainAttribute(): void
    {
        $cookie = $this->baseCookie();
        $cookie['options']['domain'] = '.example.com';

        $result = $this->format($cookie);
        $this->assertContains('Domain=.example.com', $result);
    }

    public function testSecureAttribute(): void
    {
        $cookie = $this->baseCookie();
        unset($cookie['options']['domain']);
        $cookie['options']['secure'] = true;

        $result = $this->format($cookie);
        $this->assertContains('Secure', $result);
    }

    public function testCookieNameAndValueUrlEncoding(): void
    {
        $result = $this->format([
            'name' => 'session id',  // Space in name (unusual but test encoding)
            'value' => 'value with spaces & special=chars',
            'options' => ['path' => '/'],
        ]);

        $this->assertContains('session%20id=', $result);
        $this->assertContains('value%20with%20spaces', $result);
    }

    public function testMinimalCookie(): void
    {
        $result = $this->format([
            'name' => 'simple',
            'value' => 'test',
            'options' => [],
        ]);

        $this->assertSame('simple=test', $result);
    }

    public function testExpirationCookieFormatting(): void
    {
        $result = $this->format([
            'name' => 'PHPSESSID',
            'value' => '',
            'options' => [
                'expires' => 1,
                'path' => '/',
            ],
        ]);

        $this->assertContains('PHPSESSID=', $result);
        $this->assertContains('Expires=Thu, 01 Jan 1970 00:00:01 GMT', $result);
    }

    public function testCacheLimiterNocacheSetsCorrectHeaders(): void
    {
        $result = $this->applyCacheLimiter->invoke($this->middleware, $this->mockResponse());

        $this->assertTrue($result->hasHeader('Cache-Control'), 'nocache should set Cache-Control');
        $this->assertContains('no-store', $result->getHeaderLine('Cache-Control'));
        $this->assertContains('no-cache', $result->getHeaderLine('Cache-Control'));
        $this->assertTrue($result->hasHeader('Pragma'), 'nocache should set Pragma for HTTP/1.0');
        $this->assertTrue($result->hasHeader('Expires'), 'nocache should set Expires');
    }

    public function testCacheLimiterRestrictsExistingCacheControl(): void
    {
        // session + caching = bug
        $responseWithCache = $this->mockResponse()->withHeader('Cache-Control', 'public, max-age=3600');
        $result = $this->applyCacheLimiter->invoke($this->middleware, $responseWithCache);
        $cacheControl = $result->getHeaderLine('Cache-Control');

        $this->assertContains('no-store', $cacheControl, 'Should restrict to no-store');
        $this->assertContains('no-cache', $cacheControl, 'Should add no-cache');
        $this->assertFalse(str_contains($cacheControl, 'public'), 'Should remove public');
    }
};

exit($test->run());
