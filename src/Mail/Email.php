<?php

namespace mini\Mail;

use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Email - High-level email composition with lazy MIME compilation
 *
 * Provides a declarative API for building emails. The MIME structure is
 * compiled lazily when getBody() is called, and cached until mutation.
 *
 * Headers are the single source of truth - address methods like getFrom()
 * parse the header values on demand.
 *
 * @see EmailInterface for full API documentation and usage examples
 */
class Email implements EmailInterface
{
    protected string $protocolVersion = '1.1';

    /** @var array<string, string[]> Headers (lowercase key => values) */
    protected array $headers = [];

    /** @var array<string, string> Header case preservation */
    protected array $headerCases = [];

    protected ?string $textBody = null;
    protected ?string $htmlBody = null;

    /** @var array<string, MessageInterface> Content-ID => Message */
    protected array $inlines = [];

    /** @var MessageInterface[] */
    protected array $attachments = [];

    /** @var MessageInterface|null Cached compiled message */
    protected ?MessageInterface $compiled = null;

    // =========================================================================
    // Address Headers
    // =========================================================================

    public function getFrom(): array
    {
        return $this->parseMailboxHeader('From');
    }

    public function withFrom(MailboxInterface|string ...$mailboxes): static
    {
        return $this->withMailboxHeader('From', $mailboxes);
    }

    public function getTo(): array
    {
        return $this->parseMailboxHeader('To');
    }

    public function withTo(MailboxInterface|string ...$mailboxes): static
    {
        return $this->withMailboxHeader('To', $mailboxes);
    }

    public function withAddedTo(MailboxInterface|string ...$mailboxes): static
    {
        return $this->withAddedMailboxHeader('To', $mailboxes);
    }

    public function getCc(): array
    {
        return $this->parseMailboxHeader('Cc');
    }

    public function withCc(MailboxInterface|string ...$mailboxes): static
    {
        return $this->withMailboxHeader('Cc', $mailboxes);
    }

    public function withAddedCc(MailboxInterface|string ...$mailboxes): static
    {
        return $this->withAddedMailboxHeader('Cc', $mailboxes);
    }

    public function getBcc(): array
    {
        return $this->parseMailboxHeader('Bcc');
    }

    public function withBcc(MailboxInterface|string ...$mailboxes): static
    {
        return $this->withMailboxHeader('Bcc', $mailboxes);
    }

    public function withAddedBcc(MailboxInterface|string ...$mailboxes): static
    {
        return $this->withAddedMailboxHeader('Bcc', $mailboxes);
    }

    public function getReplyTo(): array
    {
        return $this->parseMailboxHeader('Reply-To');
    }

    public function withReplyTo(MailboxInterface|string ...$mailboxes): static
    {
        return $this->withMailboxHeader('Reply-To', $mailboxes);
    }

    public function withAddedReplyTo(MailboxInterface|string ...$mailboxes): static
    {
        return $this->withAddedMailboxHeader('Reply-To', $mailboxes);
    }

    /**
     * Parse a header into MailboxInterface array
     *
     * @return MailboxInterface[]
     */
    protected function parseMailboxHeader(string $name): array
    {
        $key = strtolower($name);
        if (!isset($this->headers[$key])) {
            return [];
        }

        $result = [];
        foreach ($this->headers[$key] as $value) {
            // Split by comma, but respect quoted strings and angle brackets
            foreach ($this->splitMailboxList($value) as $mailboxStr) {
                $mailboxStr = trim($mailboxStr);
                if ($mailboxStr !== '') {
                    $result[] = Mailbox::fromString($mailboxStr);
                }
            }
        }
        return $result;
    }

    /**
     * Split a comma-separated mailbox list, respecting quotes and brackets
     *
     * @return string[]
     */
    protected function splitMailboxList(string $value): array
    {
        $result = [];
        $current = '';
        $inQuotes = false;
        $inBrackets = false;
        $len = strlen($value);

        for ($i = 0; $i < $len; $i++) {
            $char = $value[$i];

            if ($char === '"' && ($i === 0 || $value[$i - 1] !== '\\')) {
                $inQuotes = !$inQuotes;
                $current .= $char;
            } elseif ($char === '<' && !$inQuotes) {
                $inBrackets = true;
                $current .= $char;
            } elseif ($char === '>' && !$inQuotes) {
                $inBrackets = false;
                $current .= $char;
            } elseif ($char === ',' && !$inQuotes && !$inBrackets) {
                $result[] = $current;
                $current = '';
            } else {
                $current .= $char;
            }
        }

        if ($current !== '') {
            $result[] = $current;
        }

        return $result;
    }

    /**
     * Set a mailbox header (replaces existing)
     */
    protected function withMailboxHeader(string $name, array $mailboxes): static
    {
        if (empty($mailboxes)) {
            return $this->withoutHeader($name);
        }

        $formatted = [];
        foreach ($mailboxes as $mailbox) {
            $formatted[] = $this->formatMailbox($mailbox);
        }

        return $this->withHeader($name, implode(', ', $formatted));
    }

    /**
     * Add to a mailbox header
     */
    protected function withAddedMailboxHeader(string $name, array $mailboxes): static
    {
        if (empty($mailboxes)) {
            return $this;
        }

        $formatted = [];
        foreach ($mailboxes as $mailbox) {
            $formatted[] = $this->formatMailbox($mailbox);
        }

        $key = strtolower($name);
        $clone = clone $this;

        if (!isset($clone->headers[$key])) {
            $clone->headers[$key] = [];
            $clone->headerCases[$key] = $name;
        }

        // Append to existing value or add new
        $newValue = implode(', ', $formatted);
        if (!empty($clone->headers[$key])) {
            $clone->headers[$key][0] .= ', ' . $newValue;
        } else {
            $clone->headers[$key][] = $newValue;
        }

        $clone->invalidateCache();
        return $clone;
    }

    /**
     * Format a mailbox for header storage
     */
    protected function formatMailbox(MailboxInterface|string $mailbox): string
    {
        if (is_string($mailbox)) {
            $mailbox = Mailbox::fromString($mailbox);
        }

        $displayName = $mailbox->getDisplayName();
        $addrSpec = $mailbox->getAddrSpec();

        if ($displayName === null) {
            return $addrSpec;
        }

        // Check if display name needs quoting
        if (preg_match('/[()<>@,;:\\\\".\[\]]/', $displayName)) {
            $escaped = addcslashes($displayName, '"\\');
            return "\"{$escaped}\" <{$addrSpec}>";
        }

        return "{$displayName} <{$addrSpec}>";
    }

    // =========================================================================
    // Subject and Date
    // =========================================================================

    public function getSubject(): ?string
    {
        $key = strtolower('Subject');
        if (!isset($this->headers[$key])) {
            return null;
        }
        return $this->headers[$key][0] ?? null;
    }

    public function withSubject(string $subject): static
    {
        // Sanitize to prevent header injection
        $subject = preg_replace("/\r\n|\r|\n/", ' ', $subject);
        return $this->withHeader('Subject', $subject);
    }

    public function getDate(): ?string
    {
        $key = strtolower('Date');
        if (!isset($this->headers[$key])) {
            return null;
        }
        return $this->headers[$key][0] ?? null;
    }

    public function withDate(string|\DateTimeInterface $date): static
    {
        if ($date instanceof \DateTimeInterface) {
            $date = $date->format(\DateTimeInterface::RFC2822);
        }
        return $this->withHeader('Date', $date);
    }

    // =========================================================================
    // Body Content
    // =========================================================================

    public function getTextBody(): ?string
    {
        return $this->textBody;
    }

    public function withTextBody(string $text): static
    {
        $clone = clone $this;
        $clone->textBody = $text;
        $clone->invalidateCache();
        return $clone;
    }

    public function getHtmlBody(): ?string
    {
        return $this->htmlBody;
    }

    public function withHtmlBody(string $html, array $inlines = []): static
    {
        $clone = clone $this;
        $clone->htmlBody = $html;
        $clone->inlines = [];

        foreach ($inlines as $contentId => $inline) {
            if (is_string($inline)) {
                $clone->inlines[$contentId] = Message::fromFile($inline);
            } elseif ($inline instanceof MessageInterface) {
                $clone->inlines[$contentId] = $inline;
            } else {
                throw new \InvalidArgumentException(
                    "Inline '$contentId' must be a file path or MessageInterface"
                );
            }
        }

        $clone->invalidateCache();
        return $clone;
    }

    public function getInlines(): array
    {
        return $this->inlines;
    }

    // =========================================================================
    // Attachments
    // =========================================================================

    public function getAttachments(): array
    {
        return $this->attachments;
    }

    public function withAttachments(array $attachments): static
    {
        $clone = clone $this;
        $clone->attachments = [];

        foreach ($attachments as $key => $attachment) {
            $filename = is_string($key) ? $key : null;

            if (is_string($attachment)) {
                $message = Message::fromFile($attachment);
                if ($filename === null) {
                    $filename = basename($attachment);
                }
            } elseif ($attachment instanceof MessageInterface) {
                $message = $attachment;
                if ($filename === null) {
                    $storedFilename = $attachment->getHeader('X-Mini-Filename');
                    if (!empty($storedFilename)) {
                        $filename = $storedFilename[0];
                    }
                }
            } else {
                throw new \InvalidArgumentException(
                    'Attachment must be a file path or MessageInterface'
                );
            }

            if ($message->hasHeader('X-Mini-Filename')) {
                $message = $message->withoutHeader('X-Mini-Filename');
            }

            $disposition = 'attachment';
            if ($filename !== null) {
                if (preg_match('/[^\x20-\x7E]|["\\\\\x00-\x1f]/', $filename)) {
                    $encoded = rawurlencode($filename);
                    $disposition .= "; filename*=UTF-8''{$encoded}";
                } else {
                    $disposition .= "; filename=\"{$filename}\"";
                }
            }
            $message = $message->withHeader('Content-Disposition', $disposition);

            $clone->attachments[] = $message;
        }

        $clone->invalidateCache();
        return $clone;
    }

    // =========================================================================
    // PSR-7 MessageInterface
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

        // Add Date if not set
        if (!isset($this->headers['date'])) {
            $result['Date'] = [date(\DateTimeInterface::RFC2822)];
        }

        // Add Message-ID if not set
        if (!isset($this->headers['message-id'])) {
            $result['Message-ID'] = [$this->generateMessageId()];
        }

        // MIME-Version
        $result['MIME-Version'] = ['1.0'];

        // Content-Type and Content-Transfer-Encoding from compiled body
        $compiled = $this->compile();
        $result['Content-Type'] = $compiled->getHeader('Content-Type');

        if ($compiled->hasHeader('Content-Transfer-Encoding')) {
            $result['Content-Transfer-Encoding'] = $compiled->getHeader('Content-Transfer-Encoding');
        }

        // All stored headers (with proper casing and encoding)
        foreach ($this->headers as $key => $values) {
            $name = $this->headerCases[$key] ?? $key;

            // Encode headers that need it
            if ($this->isAddressHeader($key)) {
                // Address headers - encode display names if needed
                $result[$name] = array_map([$this, 'encodeAddressHeader'], $values);
            } elseif ($key === 'subject') {
                // Subject - encode if non-ASCII
                $result[$name] = array_map([$this, 'encodeHeader'], $values);
            } else {
                $result[$name] = $values;
            }
        }

        return $result;
    }

    /**
     * Check if header is an address header
     */
    protected function isAddressHeader(string $key): bool
    {
        return in_array($key, ['from', 'to', 'cc', 'bcc', 'reply-to']);
    }

    /**
     * Encode address header value (RFC 2047 for display names)
     */
    protected function encodeAddressHeader(string $value): string
    {
        // Parse and re-encode each mailbox
        $result = [];
        foreach ($this->splitMailboxList($value) as $mailboxStr) {
            $mailboxStr = trim($mailboxStr);
            if ($mailboxStr === '') {
                continue;
            }

            $mailbox = Mailbox::fromString($mailboxStr);
            $displayName = $mailbox->getDisplayName();
            $addrSpec = $mailbox->getAddrSpec();

            if ($displayName === null) {
                $result[] = $addrSpec;
            } elseif (preg_match('/[\x80-\xFF]/', $displayName)) {
                $encoded = $this->encodeRfc2047($displayName);
                $result[] = "{$encoded} <{$addrSpec}>";
            } elseif (preg_match('/[()<>@,;:\\\\".\[\]]/', $displayName)) {
                $escaped = addcslashes($displayName, '"\\');
                $result[] = "\"{$escaped}\" <{$addrSpec}>";
            } else {
                $result[] = "{$displayName} <{$addrSpec}>";
            }
        }
        return implode(', ', $result);
    }

    public function hasHeader(string $name): bool
    {
        $key = strtolower($name);

        // Built-in headers that are always present
        if (in_array($key, ['date', 'message-id', 'mime-version', 'content-type'])) {
            return true;
        }

        return isset($this->headers[$key]);
    }

    public function getHeader(string $name): array
    {
        $headers = $this->getHeaders();
        foreach ($headers as $headerName => $values) {
            if (strtolower($headerName) === strtolower($name)) {
                return $values;
            }
        }
        return [];
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
        $clone->invalidateCache();
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
        $clone->invalidateCache();
        return $clone;
    }

    public function withoutHeader(string $name): static
    {
        $clone = clone $this;
        $key = strtolower($name);
        unset($clone->headers[$key], $clone->headerCases[$key]);
        $clone->invalidateCache();
        return $clone;
    }

    public function getBody(): StreamInterface
    {
        return $this->compile()->getBody();
    }

    public function withBody(StreamInterface $body): static
    {
        throw new \RuntimeException(
            'Email body cannot be set directly. Use withTextBody(), withHtmlBody(), or withAttachments().'
        );
    }

    // =========================================================================
    // Compilation
    // =========================================================================

    protected function compile(): MessageInterface
    {
        if ($this->compiled !== null) {
            return $this->compiled;
        }

        $this->compiled = $this->buildMimeStructure();
        return $this->compiled;
    }

    protected function invalidateCache(): void
    {
        $this->compiled = null;
    }

    protected function buildMimeStructure(): MessageInterface
    {
        $textPart = null;
        $htmlPart = null;
        $contentPart = null;

        if ($this->textBody !== null) {
            $textPart = $this->createTextPart($this->textBody, 'text/plain');
        }

        if ($this->htmlBody !== null) {
            $htmlPart = $this->createTextPart($this->htmlBody, 'text/html');

            if (!empty($this->inlines)) {
                $parts = [$htmlPart];
                foreach ($this->inlines as $contentId => $inline) {
                    $parts[] = $this->prepareInline($inline, $contentId);
                }
                $htmlPart = new MultipartMessage(MultipartType::Related, ...$parts);
            }
        }

        if ($textPart !== null && $htmlPart !== null) {
            $contentPart = new MultipartMessage(MultipartType::Alternative, $textPart, $htmlPart);
        } elseif ($textPart !== null) {
            $contentPart = $textPart;
        } elseif ($htmlPart !== null) {
            $contentPart = $htmlPart;
        } else {
            $contentPart = new Message('text/plain', '');
        }

        if (!empty($this->attachments)) {
            $parts = [$contentPart];
            foreach ($this->attachments as $attachment) {
                $parts[] = $this->prepareAttachment($attachment);
            }
            return new MultipartMessage(MultipartType::Mixed, ...$parts);
        }

        return $contentPart;
    }

    protected function createTextPart(string $content, string $mimeType): Message
    {
        $content = str_replace(["\r\n", "\r", "\n"], "\r\n", $content);
        $needsEncoding = $this->needsEncoding($content);

        if ($needsEncoding) {
            $encoded = quoted_printable_encode($content);
            return new Message(
                "{$mimeType}; charset=UTF-8",
                $encoded,
                ['Content-Transfer-Encoding' => 'quoted-printable']
            );
        }

        return new Message("{$mimeType}; charset=UTF-8", $content);
    }

    protected function needsEncoding(string $content): bool
    {
        if (preg_match('/[\x80-\xFF]/', $content)) {
            return true;
        }

        $lines = explode("\r\n", $content);
        foreach ($lines as $line) {
            if (strlen($line) > 998) {
                return true;
            }
        }

        return false;
    }

    protected function prepareInline(MessageInterface $inline, string $contentId): MessageInterface
    {
        if ($inline->hasHeader('X-Mini-Filename')) {
            $inline = $inline->withoutHeader('X-Mini-Filename');
        }

        $inline = $inline
            ->withHeader('Content-Disposition', 'inline')
            ->withHeader('Content-ID', "<{$contentId}>");

        return $this->prepareAttachment($inline);
    }

    protected function prepareAttachment(MessageInterface $attachment): MessageInterface
    {
        if ($attachment->hasHeader('Content-Transfer-Encoding')) {
            return $attachment;
        }

        $contentType = $attachment->getHeaderLine('Content-Type');
        $isBinary = !str_starts_with($contentType, 'text/');

        if ($isBinary) {
            // Wrap body in Base64Stream for lazy streaming encoding
            $body = new Base64Stream($attachment->getBody());

            return new Message(
                $contentType,
                $body,
                array_merge(
                    $this->extractHeaders($attachment),
                    ['Content-Transfer-Encoding' => 'base64']
                )
            );
        }

        // For text content, wrap in QuotedPrintableStream for lazy encoding
        $body = new QuotedPrintableStream($attachment->getBody());

        return new Message(
            $contentType,
            $body,
            array_merge(
                $this->extractHeaders($attachment),
                ['Content-Transfer-Encoding' => 'quoted-printable']
            )
        );
    }

    protected function extractHeaders(MessageInterface $message): array
    {
        $result = [];
        foreach ($message->getHeaders() as $name => $values) {
            if (strtolower($name) !== 'content-type') {
                $result[$name] = $values;
            }
        }
        return $result;
    }

    // =========================================================================
    // Encoding Helpers
    // =========================================================================

    protected function encodeHeader(string $value): string
    {
        if (preg_match('/[\x80-\xFF]/', $value)) {
            return $this->encodeRfc2047($value);
        }
        return $value;
    }

    protected function encodeRfc2047(string $value): string
    {
        $encoded = base64_encode($value);
        return "=?UTF-8?B?{$encoded}?=";
    }

    protected function generateMessageId(): string
    {
        $random = bin2hex(random_bytes(16));
        $domain = 'localhost';

        // Try to get domain from From address
        $from = $this->getFrom();
        if (!empty($from)) {
            $addrSpec = $from[0]->getAddrSpec();
            if (preg_match('/@(.+)$/', $addrSpec, $matches)) {
                $domain = $matches[1];
            }
        }

        return "<{$random}@{$domain}>";
    }

    // =========================================================================
    // StreamInterface implementation
    // =========================================================================

    /** @var StreamInterface|null Cached stream for reading */
    protected ?StreamInterface $stream = null;

    /**
     * Build the complete email stream (headers + CRLF + body)
     */
    protected function buildStream(): StreamInterface
    {
        if ($this->stream !== null) {
            return $this->stream;
        }

        // Build headers string
        $headerLines = '';
        foreach ($this->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $headerLines .= "{$name}: {$value}\r\n";
            }
        }
        $headerLines .= "\r\n"; // Blank line between headers and body

        // Create a composite stream: headers + body
        $this->stream = new class($headerLines, $this->getBody()) implements StreamInterface {
            private string $headers;
            private int $headerPos = 0;
            private StreamInterface $body;
            private bool $headersDone = false;
            private bool $detached = false;

            public function __construct(string $headers, StreamInterface $body)
            {
                $this->headers = $headers;
                $this->body = $body;
            }

            public function read(int $length): string
            {
                if ($this->detached) {
                    throw new \RuntimeException('Stream is detached');
                }

                $result = '';
                $remaining = $length;

                // First, read from headers
                if (!$this->headersDone) {
                    $headerRemaining = strlen($this->headers) - $this->headerPos;
                    $toRead = min($remaining, $headerRemaining);
                    $result .= substr($this->headers, $this->headerPos, $toRead);
                    $this->headerPos += $toRead;
                    $remaining -= $toRead;

                    if ($this->headerPos >= strlen($this->headers)) {
                        $this->headersDone = true;
                    }
                }

                // Then read from body
                if ($remaining > 0 && $this->headersDone && !$this->body->eof()) {
                    $result .= $this->body->read($remaining);
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
                    $this->rewind();
                    return $this->getContents();
                } catch (\Throwable $e) {
                    return '';
                }
            }

            public function eof(): bool
            {
                return $this->detached || ($this->headersDone && $this->body->eof());
            }

            public function tell(): int
            {
                throw new \RuntimeException('Email stream does not support tell()');
            }

            public function seek(int $offset, int $whence = SEEK_SET): void
            {
                throw new \RuntimeException('Email stream is not seekable');
            }

            public function rewind(): void
            {
                if ($this->detached) {
                    throw new \RuntimeException('Stream is detached');
                }

                $this->headerPos = 0;
                $this->headersDone = false;
                if ($this->body->isSeekable()) {
                    $this->body->rewind();
                }
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
                throw new \RuntimeException('Email stream is not writable');
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
                $meta = ['seekable' => false, 'eof' => $this->eof()];
                return $key === null ? $meta : ($meta[$key] ?? null);
            }

            public function close(): void
            {
                $this->detached = true;
            }

            public function detach()
            {
                $this->detached = true;
                return null;
            }
        };

        return $this->stream;
    }

    public function __toString(): string
    {
        try {
            return (string) $this->buildStream();
        } catch (\Throwable $e) {
            return '';
        }
    }

    public function close(): void
    {
        $this->buildStream()->close();
    }

    public function detach()
    {
        return $this->buildStream()->detach();
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        return $this->buildStream()->tell();
    }

    public function eof(): bool
    {
        return $this->buildStream()->eof();
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->buildStream()->seek($offset, $whence);
    }

    public function rewind(): void
    {
        $this->buildStream()->rewind();
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new \RuntimeException('Email stream is not writable');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read(int $length): string
    {
        return $this->buildStream()->read($length);
    }

    public function getContents(): string
    {
        return $this->buildStream()->getContents();
    }

    public function getMetadata(?string $key = null): mixed
    {
        return $this->buildStream()->getMetadata($key);
    }

    // =========================================================================
    // Clone
    // =========================================================================

    public function __clone(): void
    {
        $this->compiled = null;
        $this->stream = null;
    }
}
