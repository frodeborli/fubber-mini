<?php

namespace mini\Mail;

use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Streaming reader for MultipartMessage body
 *
 * Produces RFC 2046 compliant multipart content by streaming through
 * child parts without buffering entire bodies.
 *
 * For each part:
 * 1. Emit boundary delimiter
 * 2. Emit part's headers
 * 3. Emit blank line
 * 4. Forward reads to part's body stream
 * 5. Emit CRLF
 *
 * After all parts: emit closing boundary.
 *
 * @internal Used by MultipartMessage::getBody()
 */
class MultipartMessageStream implements StreamInterface
{
    private const CRLF = "\r\n";

    /** @var MessageInterface[] */
    private array $parts;

    private string $boundary;

    private int $partIndex = 0;
    private int $phase = self::PHASE_INIT;

    private const PHASE_INIT = 0;
    private const PHASE_BOUNDARY = 1;
    private const PHASE_HEADERS = 2;
    private const PHASE_BODY = 3;
    private const PHASE_AFTER_BODY = 4;
    private const PHASE_CLOSING = 5;
    private const PHASE_DONE = 6;

    private string $buffer = '';
    private ?StreamInterface $currentBody = null;
    private bool $detached = false;

    /**
     * @param MessageInterface[] $parts
     * @param string $boundary
     */
    public function __construct(array $parts, string $boundary)
    {
        $this->parts = array_values($parts);
        $this->boundary = $boundary;

        if (count($this->parts) > 0) {
            $this->phase = self::PHASE_BOUNDARY;
            $this->preparePhase();
        } else {
            $this->phase = self::PHASE_CLOSING;
            $this->preparePhase();
        }
    }

    private function preparePhase(): void
    {
        switch ($this->phase) {
            case self::PHASE_BOUNDARY:
                $this->buffer = '--' . $this->boundary . self::CRLF;
                break;

            case self::PHASE_HEADERS:
                $part = $this->parts[$this->partIndex];
                $headers = '';
                foreach ($part->getHeaders() as $name => $values) {
                    foreach ($values as $value) {
                        $headers .= "{$name}: {$value}" . self::CRLF;
                    }
                }
                $headers .= self::CRLF;
                $this->buffer = $headers;
                break;

            case self::PHASE_BODY:
                $part = $this->parts[$this->partIndex];
                $this->currentBody = $part->getBody();
                if ($this->currentBody->isSeekable()) {
                    $this->currentBody->rewind();
                }
                break;

            case self::PHASE_AFTER_BODY:
                $this->buffer = self::CRLF;
                $this->currentBody = null;
                break;

            case self::PHASE_CLOSING:
                $this->buffer = '--' . $this->boundary . '--' . self::CRLF;
                break;

            case self::PHASE_DONE:
                $this->buffer = '';
                $this->currentBody = null;
                break;
        }
    }

    private function advance(): void
    {
        switch ($this->phase) {
            case self::PHASE_BOUNDARY:
                $this->phase = self::PHASE_HEADERS;
                break;

            case self::PHASE_HEADERS:
                $this->phase = self::PHASE_BODY;
                break;

            case self::PHASE_BODY:
                $this->phase = self::PHASE_AFTER_BODY;
                break;

            case self::PHASE_AFTER_BODY:
                $this->partIndex++;
                if ($this->partIndex < count($this->parts)) {
                    $this->phase = self::PHASE_BOUNDARY;
                } else {
                    $this->phase = self::PHASE_CLOSING;
                }
                break;

            case self::PHASE_CLOSING:
                $this->phase = self::PHASE_DONE;
                break;
        }

        $this->preparePhase();
    }

    public function read(int $length): string
    {
        if ($this->detached) {
            throw new \RuntimeException('Stream is detached');
        }

        $result = '';
        $remaining = $length;

        while ($remaining > 0 && $this->phase !== self::PHASE_DONE) {
            if ($this->phase === self::PHASE_BODY) {
                if ($this->currentBody !== null && !$this->currentBody->eof()) {
                    $chunk = $this->currentBody->read($remaining);
                    $result .= $chunk;
                    $remaining -= strlen($chunk);
                }

                if ($this->currentBody === null || $this->currentBody->eof()) {
                    $this->advance();
                }
            } else {
                if ($this->buffer !== '') {
                    $chunk = substr($this->buffer, 0, $remaining);
                    $this->buffer = substr($this->buffer, strlen($chunk));
                    $result .= $chunk;
                    $remaining -= strlen($chunk);
                }

                if ($this->buffer === '') {
                    $this->advance();
                }
            }
        }

        return $result;
    }

    public function getContents(): string
    {
        if ($this->detached) {
            throw new \RuntimeException('Stream is detached');
        }

        $contents = '';
        while (!$this->eof()) {
            $contents .= $this->read(8192);
        }
        return $contents;
    }

    public function __toString(): string
    {
        try {
            // Create fresh instance to read from beginning
            $stream = new self($this->parts, $this->boundary);
            return $stream->getContents();
        } catch (\Throwable $e) {
            return '';
        }
    }

    public function eof(): bool
    {
        return $this->detached || $this->phase === self::PHASE_DONE;
    }

    public function tell(): int
    {
        throw new \RuntimeException('MultipartMessageStream does not support tell()');
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new \RuntimeException('MultipartMessageStream is not seekable');
    }

    public function rewind(): void
    {
        if ($this->detached) {
            throw new \RuntimeException('Stream is detached');
        }

        $this->partIndex = 0;
        $this->buffer = '';
        $this->currentBody = null;

        if (count($this->parts) > 0) {
            $this->phase = self::PHASE_BOUNDARY;
        } else {
            $this->phase = self::PHASE_CLOSING;
        }
        $this->preparePhase();
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new \RuntimeException('MultipartMessageStream is not writable');
    }

    public function isReadable(): bool
    {
        return !$this->detached;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function getMetadata(?string $key = null): mixed
    {
        $meta = [
            'seekable' => false,
            'eof' => $this->eof(),
        ];

        if ($key === null) {
            return $meta;
        }
        return $meta[$key] ?? null;
    }

    public function close(): void
    {
        $this->detached = true;
        $this->buffer = '';
        $this->currentBody = null;
    }

    public function detach()
    {
        $this->detached = true;
        $this->buffer = '';
        $this->currentBody = null;
        return null;
    }
}
