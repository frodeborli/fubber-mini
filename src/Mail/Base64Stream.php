<?php

namespace mini\Mail;

use Psr\Http\Message\StreamInterface;

/**
 * Base64 Encoding Stream
 *
 * Wraps a StreamInterface and encodes its content as base64 on-the-fly.
 * Produces output with line breaks every 76 characters per RFC 2045.
 *
 * This is a read-only, forward-only stream that encodes data as it's read.
 *
 * @internal Used by Email::compile() for encoding binary attachments
 */
class Base64Stream implements StreamInterface
{
    private const LINE_LENGTH = 76;
    private const CRLF = "\r\n";

    private StreamInterface $source;
    private string $buffer = '';
    private string $remainder = '';
    private bool $sourceExhausted = false;
    private bool $detached = false;

    /**
     * @param StreamInterface $source The source stream to encode
     */
    public function __construct(StreamInterface $source)
    {
        $this->source = $source;

        if ($source->isSeekable()) {
            $source->rewind();
        }
    }

    /**
     * Read encoded data from the stream
     *
     * @param int $length Maximum bytes to read
     * @return string Base64-encoded data with line breaks
     */
    public function read(int $length): string
    {
        if ($this->detached) {
            throw new \RuntimeException('Stream is detached');
        }

        // Fill buffer if needed
        while (strlen($this->buffer) < $length && !$this->sourceExhausted) {
            $this->fillBuffer();
        }

        // Return requested amount
        $result = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, strlen($result));

        return $result;
    }

    /**
     * Fill the internal buffer with more encoded data
     */
    private function fillBuffer(): void
    {
        // Read raw bytes from source
        // We need multiples of 3 bytes for clean base64 encoding (3 bytes -> 4 chars)
        // Read enough to produce several lines
        $chunkSize = 57 * 10; // 57 bytes = 76 chars after base64, read 10 lines worth

        $raw = $this->remainder;

        if (!$this->source->eof()) {
            $raw .= $this->source->read($chunkSize);
        }

        if ($raw === '' && $this->source->eof()) {
            $this->sourceExhausted = true;
            return;
        }

        // Keep remainder that doesn't divide evenly by 3
        $usableLength = (int) (floor(strlen($raw) / 3) * 3);

        if ($this->source->eof()) {
            // At end, encode everything including padding
            $usableLength = strlen($raw);
            $this->sourceExhausted = true;
            $this->remainder = '';
        } else {
            $this->remainder = substr($raw, $usableLength);
            $raw = substr($raw, 0, $usableLength);
        }

        if ($raw === '') {
            return;
        }

        // Encode and add line breaks
        $encoded = base64_encode($raw);
        $lines = str_split($encoded, self::LINE_LENGTH);
        $this->buffer .= implode(self::CRLF, $lines);

        // Add final CRLF if not at end, or if we have output
        if (!$this->sourceExhausted || $encoded !== '') {
            $this->buffer .= self::CRLF;
        }
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
            if ($this->source->isSeekable()) {
                $this->source->rewind();
                $this->buffer = '';
                $this->remainder = '';
                $this->sourceExhausted = false;
            }
            return $this->getContents();
        } catch (\Throwable $e) {
            return '';
        }
    }

    public function eof(): bool
    {
        return $this->detached || ($this->sourceExhausted && $this->buffer === '');
    }

    public function tell(): int
    {
        throw new \RuntimeException('Base64Stream does not support tell()');
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new \RuntimeException('Base64Stream is not seekable');
    }

    public function rewind(): void
    {
        if ($this->detached) {
            throw new \RuntimeException('Stream is detached');
        }

        if (!$this->source->isSeekable()) {
            throw new \RuntimeException('Cannot rewind: source stream is not seekable');
        }

        $this->source->rewind();
        $this->buffer = '';
        $this->remainder = '';
        $this->sourceExhausted = false;
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
        throw new \RuntimeException('Base64Stream is not writable');
    }

    public function isReadable(): bool
    {
        return !$this->detached;
    }

    public function getSize(): ?int
    {
        // Size is unpredictable due to encoding expansion and line breaks
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
        $this->remainder = '';
    }

    public function detach()
    {
        $this->detached = true;
        $this->buffer = '';
        $this->remainder = '';
        return null;
    }
}
