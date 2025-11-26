<?php

namespace mini\Mail;

/**
 * Email Message Trait
 *
 * Provides convenient email-specific methods for MIME messages.
 * All methods internally map to PSR-7 header operations - no additional state.
 *
 * This trait is designed for immutable message composition, making it ideal
 * for sending large batches of similar emails:
 *
 * Example:
 * ```php
 * $template = (new Message())
 *     ->withFrom('noreply@example.com')
 *     ->withSubject('Welcome!')
 *     ->withTextBody('Welcome to our service');
 *
 * foreach ($users as $user) {
 *     $message = $template->withTo($user->email);
 *     mail()->send($message);
 * }
 * ```
 *
 * Based on RFC 5322: Internet Message Format
 *
 * @see https://datatracker.ietf.org/doc/html/rfc5322
 */
trait EmailMessageTrait
{
    /**
     * Get To addresses
     *
     * Internally maps to: $this->getHeader('To')
     *
     * @return string[] Array of email addresses
     */
    public function getTo(): array
    {
        $header = $this->getHeader('To');
        if (empty($header)) {
            return [];
        }
        return is_array($header) ? $header : array_map('trim', explode(',', $header[0]));
    }

    /**
     * Return instance with specified To addresses
     *
     * Internally maps to: $this->withHeader('To', ...)
     *
     * @param string ...$addresses Email addresses
     * @return static
     */
    public function withTo(string ...$addresses): static
    {
        return $this->withHeader('To', implode(', ', $addresses));
    }

    /**
     * Get From addresses
     *
     * Internally maps to: $this->getHeader('From')
     *
     * @return string[] Array of email addresses
     */
    public function getFrom(): array
    {
        $header = $this->getHeader('From');
        if (empty($header)) {
            return [];
        }
        return is_array($header) ? $header : array_map('trim', explode(',', $header[0]));
    }

    /**
     * Return instance with specified From addresses
     *
     * Internally maps to: $this->withHeader('From', ...)
     *
     * @param string ...$addresses Email addresses
     * @return static
     */
    public function withFrom(string ...$addresses): static
    {
        return $this->withHeader('From', implode(', ', $addresses));
    }

    /**
     * Get Cc addresses
     *
     * Internally maps to: $this->getHeader('Cc')
     *
     * @return string[] Array of email addresses
     */
    public function getCc(): array
    {
        $header = $this->getHeader('Cc');
        if (empty($header)) {
            return [];
        }
        return is_array($header) ? $header : array_map('trim', explode(',', $header[0]));
    }

    /**
     * Return instance with specified Cc addresses
     *
     * Internally maps to: $this->withHeader('Cc', ...)
     *
     * @param string ...$addresses Email addresses
     * @return static
     */
    public function withCc(string ...$addresses): static
    {
        return $this->withHeader('Cc', implode(', ', $addresses));
    }

    /**
     * Get Bcc addresses
     *
     * Internally maps to: $this->getHeader('Bcc')
     *
     * @return string[] Array of email addresses
     */
    public function getBcc(): array
    {
        $header = $this->getHeader('Bcc');
        if (empty($header)) {
            return [];
        }
        return is_array($header) ? $header : array_map('trim', explode(',', $header[0]));
    }

    /**
     * Return instance with specified Bcc addresses
     *
     * Internally maps to: $this->withHeader('Bcc', ...)
     *
     * @param string ...$addresses Email addresses
     * @return static
     */
    public function withBcc(string ...$addresses): static
    {
        return $this->withHeader('Bcc', implode(', ', $addresses));
    }

    /**
     * Get Reply-To addresses
     *
     * Internally maps to: $this->getHeader('Reply-To')
     *
     * @return string[] Array of email addresses
     */
    public function getReplyTo(): array
    {
        $header = $this->getHeader('Reply-To');
        if (empty($header)) {
            return [];
        }
        return is_array($header) ? $header : array_map('trim', explode(',', $header[0]));
    }

    /**
     * Return instance with specified Reply-To addresses
     *
     * Internally maps to: $this->withHeader('Reply-To', ...)
     *
     * @param string ...$addresses Email addresses
     * @return static
     */
    public function withReplyTo(string ...$addresses): static
    {
        return $this->withHeader('Reply-To', implode(', ', $addresses));
    }

    /**
     * Get subject
     *
     * Internally maps to: $this->getHeader('Subject')
     *
     * @return string|null The subject line, or null if not set
     */
    public function getSubject(): ?string
    {
        $header = $this->getHeader('Subject');
        if (empty($header)) {
            return null;
        }
        return is_array($header) ? $header[0] : $header;
    }

    /**
     * Return instance with specified subject
     *
     * Internally maps to: $this->withHeader('Subject', ...)
     *
     * @param string $subject The subject line
     * @return static
     */
    public function withSubject(string $subject): static
    {
        return $this->withHeader('Subject', $subject);
    }

    /**
     * Get Date header
     *
     * Internally maps to: $this->getHeader('Date')
     *
     * @return string|null RFC 5322 formatted date, or null if not set
     */
    public function getDate(): ?string
    {
        $header = $this->getHeader('Date');
        if (empty($header)) {
            return null;
        }
        return is_array($header) ? $header[0] : $header;
    }

    /**
     * Return instance with specified Date header
     *
     * Internally maps to: $this->withHeader('Date', ...)
     *
     * @param string|\DateTimeInterface $date RFC 5322 date string or DateTimeInterface
     * @return static
     */
    public function withDate(string|\DateTimeInterface $date): static
    {
        if ($date instanceof \DateTimeInterface) {
            $date = $date->format(\DateTimeInterface::RFC2822);
        }
        return $this->withHeader('Date', $date);
    }

    /**
     * Return instance with text and/or HTML body
     *
     * This is a convenience method that handles the common case of creating
     * multipart/alternative messages for email.
     *
     * Internally creates:
     * - Single text/plain message if only $textBody provided
     * - Single text/html message if only $htmlBody provided
     * - multipart/alternative with both if both provided
     *
     * Then maps to: $this->withBody(...)
     *
     * @param string|null $textBody Plain text version of the message
     * @param string|null $htmlBody HTML version of the message
     * @return static
     * @throws \InvalidArgumentException if both parameters are null
     */
    public function withMailBody(?string $textBody = null, ?string $htmlBody = null): static
    {
        if ($textBody === null && $htmlBody === null) {
            throw new \InvalidArgumentException('At least one of $textBody or $htmlBody must be provided');
        }

        // Single part message
        if ($textBody !== null && $htmlBody === null) {
            $body = new \mini\Mime\Message('text/plain', $textBody);
            return $this->withBody($body);
        }

        if ($textBody === null && $htmlBody !== null) {
            $body = new \mini\Mime\Message('text/html', $htmlBody);
            return $this->withBody($body);
        }

        // Multipart alternative (both provided)
        $textPart = new \mini\Mime\Message('text/plain', $textBody);
        $htmlPart = new \mini\Mime\Message('text/html', $htmlBody);
        $body = new \mini\Mime\Multipart('alternative', $textPart, $htmlPart);

        return $this->withBody($body);
    }

    /**
     * Abstract methods that must be provided by the implementing class
     * (from PSR-7 MessageInterface)
     */
    abstract public function getHeader(string $name): array;
    abstract public function withHeader(string $name, $value): static;
    abstract public function withBody(\mini\Mime\MimeMessageInterface $body): static;
}
