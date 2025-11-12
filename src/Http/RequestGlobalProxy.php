<?php

namespace mini\Http;

use mini\Mini;
use Psr\Http\Message\ServerRequestInterface;

/**
 * ArrayAccess proxy for request globals ($_GET, $_POST, $_COOKIE)
 *
 * Transparently proxies array access to PSR-7 ServerRequest methods, enabling
 * fiber-safe concurrent request handling without code changes.
 *
 * Traditional PHP behavior:
 * ```php
 * $id = $_GET['id'];           // Direct superglobal access
 * $name = $_POST['name'];      // Process-wide variables
 * ```
 *
 * With RequestGlobalProxy:
 * ```php
 * $_GET = new RequestGlobalProxy('query');     // Install once at startup
 * $_POST = new RequestGlobalProxy('post');
 *
 * // User code unchanged - transparently uses current request
 * $id = $_GET['id'];           // → gets from current ServerRequest
 * $name = $_POST['name'];      // → gets from current ServerRequest
 * ```
 *
 * Benefits:
 * - Zero code changes needed - existing $_GET['id'] works unchanged
 * - Fiber-safe by default - each fiber gets its own request context
 * - Works with all SAPIs (FPM, CGI, mod_php, Swoole, ReactPHP, phasync)
 * - Consistent architecture - no special adapters needed
 *
 * Limitations:
 * - Cannot modify request globals ($_GET['x'] = 'y' throws exception)
 * - Use PSR-7 withQueryParams() to modify request instead
 * - is_array($_GET) returns false (use isset(), array access works fine)
 *
 * @implements \ArrayAccess<string, mixed>
 * @implements \IteratorAggregate<string, mixed>
 */
class RequestGlobalProxy implements \ArrayAccess, \Countable, \IteratorAggregate
{
    /**
     * @param string $source Source type: 'query' ($_GET), 'post' ($_POST), 'cookie' ($_COOKIE)
     */
    public function __construct(
        private readonly string $source
    ) {}

    /**
     * Get value from current request context
     */
    public function offsetGet(mixed $offset): mixed
    {
        $data = $this->getData();
        return $data[$offset] ?? null;
    }

    /**
     * Check if key exists in current request context
     */
    public function offsetExists(mixed $offset): bool
    {
        $data = $this->getData();
        return isset($data[$offset]);
    }

    /**
     * Setting values not supported - use PSR-7 methods instead
     *
     * @throws \RuntimeException
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \RuntimeException(
            'Cannot modify request globals directly. ' .
            'Use PSR-7 methods: $request->withQueryParams(), ->withParsedBody(), etc.'
        );
    }

    /**
     * Unsetting values not supported - use PSR-7 methods instead
     *
     * @throws \RuntimeException
     */
    public function offsetUnset(mixed $offset): void
    {
        throw new \RuntimeException(
            'Cannot modify request globals directly. ' .
            'Use PSR-7 methods: $request->withQueryParams(), ->withParsedBody(), etc.'
        );
    }

    /**
     * Count elements in current request context
     */
    public function count(): int
    {
        return count($this->getData());
    }

    /**
     * Get iterator for current request context
     *
     * @return \ArrayIterator<string, mixed>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->getData());
    }

    /**
     * Get data from current request's ServerRequest
     *
     * @return array<string, mixed>
     */
    private function getData(): array
    {
        try {
            $request = Mini::$mini->get(ServerRequestInterface::class);
        } catch (\Throwable $e) {
            // No request available yet - return empty array
            // This can happen during bootstrap before dispatch() is called
            return [];
        }

        return match($this->source) {
            'query' => $request->getQueryParams(),
            'post' => $request->getParsedBody() ?: [],
            'cookie' => $request->getCookieParams(),
            default => throw new \RuntimeException("Invalid request global source: {$this->source}"),
        };
    }

    /**
     * Debug info for var_dump()
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'source' => $this->source,
            'data' => $this->getData(),
        ];
    }
}
