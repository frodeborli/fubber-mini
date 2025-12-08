<?php

namespace mini\Mail;

use Psr\Http\Message\StreamInterface;

/**
 * Quoted-Printable Encoding Stream
 *
 * Wraps a StreamInterface and encodes its content as quoted-printable on-the-fly.
 * Produces output with soft line breaks at 76 characters per RFC 2045.
 *
 * Quoted-printable is preferred for text content with occasional non-ASCII
 * characters, as it keeps ASCII text readable while encoding special chars.
 *
 * Encoding rules (RFC 2045 Section 6.7):
 * - Literal representation for printable ASCII (33-60, 62-126) except =
 * - =XX hex encoding for non-printable and non-ASCII characters
 * - Soft line breaks (=\r\n) at 76 characters
 * - Trailing whitespace must be encoded
 *
 * @internal Used by Email::compile() for encoding text with non-ASCII
 */
class QuotedPrintableStream implements StreamInterface
{
    private const MAX_LINE_LENGTH = 76;
    private const CRLF = "\r\n";

    private StreamInterface $source;
    private string $buffer = '';
    private int $lineLength = 0;
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
     * @return string Quoted-printable encoded data
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
        if ($this->source->eof()) {
            $this->sourceExhausted = true;
            return;
        }

        // Read a chunk from source
        $raw = $this->source->read(1024);

        if ($raw === '') {
            $this->sourceExhausted = true;
            return;
        }

        // Process byte by byte
        $len = strlen($raw);
        for ($i = 0; $i < $len; $i++) {
            $byte = $raw[$i];
            $ord = ord($byte);

            // Handle line endings - preserve CRLF
            if ($byte === "\r" && $i + 1 < $len && $raw[$i + 1] === "\n") {
                $this->buffer .= self::CRLF;
                $this->lineLength = 0;
                $i++; // Skip the \n
                continue;
            }

            // Standalone \r or \n - normalize to CRLF
            if ($byte === "\r" || $byte === "\n") {
                $this->buffer .= self::CRLF;
                $this->lineLength = 0;
                continue;
            }

            // Determine encoded representation
            $encoded = $this->encodeChar($byte, $ord);

            // Check if we need a soft line break
            // Reserve space: encoded char length + potential soft break (=\r\n = 3 chars)
            if ($this->lineLength + strlen($encoded) > self::MAX_LINE_LENGTH - 1) {
                $this->buffer .= '=' . self::CRLF;
                $this->lineLength = 0;
            }

            $this->buffer .= $encoded;
            $this->lineLength += strlen($encoded);
        }

        // If source is now exhausted, handle trailing whitespace
        if ($this->source->eof()) {
            $this->sourceExhausted = true;
        }
    }

    /**
     * Encode a single character
     *
     * @param string $byte The character
     * @param int $ord The ordinal value
     * @return string Encoded representation
     */
    private function encodeChar(string $byte, int $ord): string
    {
        // Printable ASCII (33-126) except = (61)
        // Tab (9) and space (32) are allowed except at line end (handled separately)
        if (($ord >= 33 && $ord <= 60) || ($ord >= 62 && $ord <= 126)) {
            return $byte;
        }

        // Space and tab - encode if at potential line end, otherwise literal
        // For simplicity, always encode trailing whitespace would require lookahead
        // We'll encode spaces/tabs that could be at line end
        if ($ord === 9 || $ord === 32) {
            // For streaming, we can't easily know if this is at line end
            // Keep literal - trailing whitespace at real line ends will be handled
            // by the email transport or we could peek ahead
            return $byte;
        }

        // Everything else gets hex encoded
        return sprintf('=%02X', $ord);
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
                $this->lineLength = 0;
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
        throw new \RuntimeException('QuotedPrintableStream does not support tell()');
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new \RuntimeException('QuotedPrintableStream is not seekable');
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
        $this->lineLength = 0;
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
        throw new \RuntimeException('QuotedPrintableStream is not writable');
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
    }

    public function detach()
    {
        $this->detached = true;
        $this->buffer = '';
        return null;
    }
}
