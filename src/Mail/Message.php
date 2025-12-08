<?php

namespace mini\Mail;

use mini\Http\Message\Stream;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\StreamInterface;

/**
 * MIME Message - Single-part message (text, HTML, attachment, etc.)
 *
 * Implements PSR-7 MessageInterface with a StreamInterface body.
 *
 * Usage:
 * ```php
 * // Simple text message
 * $msg = new Message('text/plain', 'Hello, World!');
 *
 * // HTML message
 * $msg = new Message('text/html', '<h1>Hello</h1>');
 *
 * // From file (auto-detects MIME type)
 * $msg = Message::fromFile('document.pdf');
 *
 * // From stream resource
 * $msg = new Message('image/png', fopen('image.png', 'rb'));
 *
 * // From existing StreamInterface (e.g., Base64Stream)
 * $msg = new Message('image/png', new Base64Stream($rawStream));
 * ```
 *
 * @see https://datatracker.ietf.org/doc/html/rfc2045
 * @see https://datatracker.ietf.org/doc/html/rfc2046
 */
class Message implements MessageInterface
{
    /**
     * Common MIME types by extension (subset for when config not available)
     */
    private const MIME_TYPES = [
        'txt' => 'text/plain',
        'html' => 'text/html',
        'htm' => 'text/html',
        'css' => 'text/css',
        'js' => 'application/javascript',
        'json' => 'application/json',
        'xml' => 'application/xml',
        'pdf' => 'application/pdf',
        'zip' => 'application/zip',
        'gz' => 'application/gzip',
        'tar' => 'application/x-tar',
        'gif' => 'image/gif',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'otf' => 'font/otf',
        'csv' => 'text/csv',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    /**
     * Create a MIME message from a file
     *
     * Automatically detects MIME type from extension. Stores the original
     * filename in X-Mini-Filename header for later use (e.g., by withAttachment).
     * Does not set Content-Disposition - that's the caller's responsibility.
     *
     * @param string $path Path to the file
     * @param string|null $mimeType Override MIME type (auto-detected if null)
     * @return static
     * @throws \InvalidArgumentException If file doesn't exist or isn't readable
     */
    public static function fromFile(string $path, ?string $mimeType = null): static
    {
        if (!file_exists($path)) {
            throw new \InvalidArgumentException("File not found: {$path}");
        }
        if (!is_readable($path)) {
            throw new \InvalidArgumentException("File not readable: {$path}");
        }

        // Detect MIME type
        if ($mimeType === null) {
            $mimeType = self::detectMimeType($path);
        }

        // Open file as stream
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new \InvalidArgumentException("Failed to open file: {$path}");
        }

        $message = new static($mimeType, $stream);

        // Store original filename for later use (e.g., withAttachment)
        $filename = basename($path);
        return $message->withHeader('X-Mini-Filename', $filename);
    }

    /**
     * Detect MIME type from file path
     *
     * Uses extension-based detection (reliable for known types).
     * Falls back to application/octet-stream for unknown extensions.
     *
     * @param string $path File path
     * @return string MIME type
     */
    protected static function detectMimeType(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($extension === '') {
            return 'application/octet-stream';
        }

        // Try framework config first (if Mini is bootstrapped)
        if (class_exists(\mini\Mini::class) && isset(\mini\Mini::$mini)) {
            try {
                $mimeTypes = \mini\Mini::$mini->loadConfig('mimeTypes.php');
                if (isset($mimeTypes[$extension])) {
                    return $mimeTypes[$extension];
                }
            } catch (\Throwable $e) {
                // Config not available, fall through
            }
        }

        // Use built-in mapping
        return self::MIME_TYPES[$extension] ?? 'application/octet-stream';
    }

    protected string $protocolVersion = '1.0';
    protected array $headers = [];
    protected array $headerCases = [];
    protected StreamInterface $body;

    /**
     * Create a MIME message
     *
     * @param string $contentType MIME type (e.g., 'text/plain', 'text/html', 'application/pdf')
     * @param StreamInterface|resource|string|null $content Message content
     * @param array<string, string|string[]> $headers Additional headers
     */
    public function __construct(
        string $contentType = 'text/plain',
        mixed $content = null,
        array $headers = []
    ) {
        // Use Stream::cast() to normalize content to StreamInterface
        $this->body = Stream::cast($content ?? '');

        // Set Content-Type header first, then merge in additional headers
        $headers = array_merge(['Content-Type' => $contentType], $headers);

        // Initialize headers
        foreach ($headers as $name => $values) {
            $key = strtolower($name);
            $this->headerCases[$key] = $name;
            $this->headers[$key] = is_array($values) ? $values : [$values];
        }
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
     * @return StreamInterface
     */
    public function getBody(): StreamInterface
    {
        return $this->body;
    }

    /**
     * Return an instance with the specified message body
     *
     * @param StreamInterface $body New body content
     * @return static
     */
    public function withBody(StreamInterface $body): static
    {
        $clone = clone $this;
        $clone->body = $body;
        return $clone;
    }

    // =========================================================================
    // MIME-specific methods
    // =========================================================================

    /**
     * Get the Content-Type header value
     *
     * @return string The MIME type (e.g., 'text/plain; charset=utf-8')
     */
    public function getContentType(): string
    {
        return $this->getHeaderLine('Content-Type') ?: 'application/octet-stream';
    }

    /**
     * Return instance with specified Content-Type
     *
     * @param string $contentType MIME type
     * @param array<string, string> $params Additional parameters (e.g., ['charset' => 'utf-8'])
     * @return static
     */
    public function withContentType(string $contentType, array $params = []): static
    {
        if (!empty($params)) {
            $paramStr = '';
            foreach ($params as $key => $value) {
                // Quote values containing special characters
                if (preg_match('/[()<>@,;:\\"\/\[\]?=\s]/', $value)) {
                    $value = '"' . addslashes($value) . '"';
                }
                $paramStr .= "; {$key}={$value}";
            }
            $contentType .= $paramStr;
        }
        return $this->withHeader('Content-Type', $contentType);
    }
}
