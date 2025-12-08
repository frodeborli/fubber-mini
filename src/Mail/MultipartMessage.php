<?php

namespace mini\Mail;

use ArrayAccess;
use Countable;
use IteratorAggregate;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\StreamInterface;
use Traversable;
use ArrayIterator;

/**
 * Multipart Message
 *
 * A PSR-7 MessageInterface that contains multiple child MessageInterfaces.
 * When read as a stream, produces RFC 2046 compliant boundary-delimited content.
 *
 * Accepts any MessageInterface implementation (Guzzle responses, PSR-7 messages, etc.)
 * without requiring special wrapper classes.
 *
 * Usage:
 * ```php
 * $message = new MultipartMessage(MultipartType::Mixed,
 *     new Message('text/plain', 'Hello'),
 *     new Message('text/html', '<h1>Hello</h1>'),
 *     $guzzleResponse  // Any MessageInterface works
 * );
 *
 * // Access parts
 * $part = $message->getPart(0);
 * $count = count($message);
 * foreach ($message as $part) { ... }
 *
 * // Modify (immutable)
 * $message = $message->withAddedPart($attachment);
 * $message = $message->withoutPart(2);
 *
 * // Filter
 * $htmlParts = $message->findParts(fn($p) =>
 *     str_starts_with($p->getHeaderLine('Content-Type'), 'text/html')
 * );
 *
 * // Stream the body
 * $body = $message->getBody();
 * while (!$body->eof()) {
 *     echo $body->read(8192);
 * }
 * ```
 *
 * @see https://datatracker.ietf.org/doc/html/rfc2046#section-5
 */
class MultipartMessage implements MessageInterface, Countable, IteratorAggregate, ArrayAccess
{
    protected string $protocolVersion = '1.1';
    protected array $headers = [];
    protected array $headerCases = [];

    /** @var MessageInterface[] */
    protected array $parts = [];

    protected string $boundary;

    /**
     * Create a multipart message
     *
     * @param MultipartType|string $type Multipart type
     * @param MessageInterface ...$parts Initial parts
     */
    public function __construct(
        MultipartType|string $type = MultipartType::Mixed,
        MessageInterface ...$parts
    ) {
        $this->boundary = $this->generateBoundary();
        $this->parts = $parts;

        $subtype = $type instanceof MultipartType ? $type->value : $type;
        $contentType = "multipart/{$subtype}; boundary=\"{$this->boundary}\"";
        $this->headers['content-type'] = [$contentType];
        $this->headerCases['content-type'] = 'Content-Type';
    }

    /**
     * Generate a unique boundary string
     */
    protected function generateBoundary(): string
    {
        return '=_Part_' . bin2hex(random_bytes(16));
    }

    // =========================================================================
    // Parts API (modeled after headers API)
    // =========================================================================

    /**
     * Get all parts
     *
     * @return MessageInterface[]
     */
    public function getParts(): array
    {
        return $this->parts;
    }

    /**
     * Get a specific part by index
     *
     * @param int $index Zero-based index
     * @return MessageInterface|null The part, or null if index out of bounds
     */
    public function getPart(int $index): ?MessageInterface
    {
        return $this->parts[$index] ?? null;
    }

    /**
     * Check if a part exists at the given index
     *
     * @param int $index Zero-based index
     * @return bool
     */
    public function hasPart(int $index): bool
    {
        return isset($this->parts[$index]);
    }

    /**
     * Return instance with part replaced at the specified index
     *
     * @param int $index Zero-based index
     * @param MessageInterface $part The replacement part
     * @return static
     * @throws \OutOfBoundsException If index is out of bounds
     */
    public function withPart(int $index, MessageInterface $part): static
    {
        if (!isset($this->parts[$index])) {
            throw new \OutOfBoundsException("Part index {$index} does not exist");
        }

        $clone = clone $this;
        $clone->parts[$index] = $part;
        return $clone;
    }

    /**
     * Return instance with an additional part appended
     *
     * @param MessageInterface $part The part to add
     * @return static
     */
    public function withAddedPart(MessageInterface $part): static
    {
        $clone = clone $this;
        $clone->parts[] = $part;
        return $clone;
    }

    /**
     * Return instance without the part at the specified index
     *
     * Remaining parts are re-indexed.
     *
     * @param int $index Zero-based index
     * @return static
     */
    public function withoutPart(int $index): static
    {
        $clone = clone $this;
        unset($clone->parts[$index]);
        $clone->parts = array_values($clone->parts); // Re-index
        return $clone;
    }

    // =========================================================================
    // Parts filtering and searching
    // =========================================================================

    /**
     * Find the first part matching the predicate
     *
     * @param callable(MessageInterface, int): bool $predicate
     * @return MessageInterface|null
     */
    public function findPart(callable $predicate): ?MessageInterface
    {
        foreach ($this->parts as $index => $part) {
            if ($predicate($part, $index)) {
                return $part;
            }
        }
        return null;
    }

    /**
     * Find all parts matching the predicate
     *
     * @param callable(MessageInterface, int): bool $predicate
     * @return MessageInterface[]
     */
    public function findParts(callable $predicate): array
    {
        $result = [];
        foreach ($this->parts as $index => $part) {
            if ($predicate($part, $index)) {
                $result[] = $part;
            }
        }
        return $result;
    }

    /**
     * Return instance with only parts matching the predicate
     *
     * @param callable(MessageInterface, int): bool $predicate
     * @return static
     */
    public function withParts(callable $predicate): static
    {
        $clone = clone $this;
        $clone->parts = array_values(array_filter(
            $this->parts,
            $predicate,
            ARRAY_FILTER_USE_BOTH
        ));
        return $clone;
    }

    /**
     * Return instance without parts matching the predicate
     *
     * @param callable(MessageInterface, int): bool $predicate
     * @return static
     */
    public function withoutParts(callable $predicate): static
    {
        return $this->withParts(fn($part, $index) => !$predicate($part, $index));
    }

    // =========================================================================
    // Multipart-specific accessors
    // =========================================================================

    /**
     * Get the boundary string
     *
     * @return string
     */
    public function getBoundary(): string
    {
        return $this->boundary;
    }

    /**
     * Get the multipart subtype (mixed, alternative, related, etc.)
     *
     * @return string
     */
    public function getMultipartType(): string
    {
        $contentType = $this->getHeaderLine('Content-Type');
        if (preg_match('#^multipart/([^;\s]+)#i', $contentType, $matches)) {
            return strtolower($matches[1]);
        }
        return 'mixed';
    }

    /**
     * Return instance with a different boundary
     *
     * @param string $boundary
     * @return static
     */
    public function withBoundary(string $boundary): static
    {
        $clone = clone $this;
        $clone->boundary = $boundary;
        $subtype = $clone->getMultipartType();
        $clone->headers['content-type'] = ["multipart/{$subtype}; boundary=\"{$boundary}\""];
        return $clone;
    }

    /**
     * Return instance with a different multipart type
     *
     * @param MultipartType|string $type
     * @return static
     */
    public function withMultipartType(MultipartType|string $type): static
    {
        $subtype = $type instanceof MultipartType ? $type->value : $type;
        if (!preg_match('/^[a-z0-9-]+$/i', $subtype)) {
            throw new \InvalidArgumentException("Invalid multipart subtype: {$subtype}");
        }

        $clone = clone $this;
        $clone->headers['content-type'] = ["multipart/{$subtype}; boundary=\"{$clone->boundary}\""];
        return $clone;
    }

    // =========================================================================
    // Countable & IteratorAggregate
    // =========================================================================

    public function count(): int
    {
        return count($this->parts);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->parts);
    }

    // =========================================================================
    // ArrayAccess implementation (read-only)
    // =========================================================================

    /**
     * @param int $offset
     */
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->parts[$offset]);
    }

    /**
     * @param int $offset
     */
    public function offsetGet(mixed $offset): ?MessageInterface
    {
        return $this->parts[$offset] ?? null;
    }

    /**
     * @throws \RuntimeException Always - MultipartMessage is immutable
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \RuntimeException(
            'MultipartMessage is immutable. Use withPart() or withAddedPart() instead.'
        );
    }

    /**
     * @throws \RuntimeException Always - MultipartMessage is immutable
     */
    public function offsetUnset(mixed $offset): void
    {
        throw new \RuntimeException(
            'MultipartMessage is immutable. Use withoutPart() instead.'
        );
    }

    // =========================================================================
    // PSR-7 MessageInterface implementation
    // =========================================================================

    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    public function withProtocolVersion(string $version): static
    {
        $clone = clone $this;
        $clone->protocolVersion = $version;
        return $clone;
    }

    public function getHeaders(): array
    {
        $result = [];
        foreach ($this->headers as $key => $values) {
            $result[$this->headerCases[$key]] = $values;
        }
        return $result;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    public function getHeader(string $name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }

    public function getHeaderLine(string $name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    public function withHeader(string $name, $value): static
    {
        $clone = clone $this;
        $key = strtolower($name);
        $clone->headers[$key] = is_array($value) ? $value : [(string) $value];
        $clone->headerCases[$key] = $name;
        return $clone;
    }

    public function withAddedHeader(string $name, $value): static
    {
        $clone = clone $this;
        $key = strtolower($name);
        if (!isset($clone->headers[$key])) {
            $clone->headers[$key] = [];
            $clone->headerCases[$key] = $name;
        }
        if (is_array($value)) {
            array_push($clone->headers[$key], ...$value);
        } else {
            $clone->headers[$key][] = (string) $value;
        }
        return $clone;
    }

    public function withoutHeader(string $name): static
    {
        $clone = clone $this;
        $key = strtolower($name);
        unset($clone->headers[$key], $clone->headerCases[$key]);
        return $clone;
    }

    /**
     * Get the message body
     *
     * Returns a stream that produces RFC 2046 compliant multipart content.
     * The stream reads through child parts without buffering entire bodies.
     *
     * @return StreamInterface
     */
    public function getBody(): StreamInterface
    {
        return new MultipartMessageStream($this->parts, $this->boundary);
    }

    /**
     * Return instance with the specified body
     *
     * For multipart messages, if the body is a MessageInterface, it replaces
     * all parts with that single part. Otherwise throws.
     *
     * @param StreamInterface $body
     * @return static
     */
    public function withBody(StreamInterface $body): static
    {
        // This is awkward for multipart - you'd typically use withPart/withAddedPart
        // But to satisfy the interface, we can wrap a stream as a single part
        throw new \RuntimeException(
            'Use withPart() or withAddedPart() to modify MultipartMessage contents'
        );
    }

    /**
     * Clone handler
     */
    public function __clone(): void
    {
        // Note: We don't deep clone parts - they're MessageInterface and should be immutable
        // If someone needs deep cloning, they should do it explicitly
    }
}
