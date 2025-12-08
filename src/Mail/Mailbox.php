<?php

namespace mini\Mail;

/**
 * Mailbox - RFC 5322 email address with optional display name
 *
 * Represents a mailbox which is a display name + addr-spec combination.
 * Immutable value object with validation.
 *
 * Usage:
 * ```php
 * // Simple email
 * $mailbox = new Mailbox('frode@ennerd.com');
 *
 * // With display name
 * $mailbox = new Mailbox('frode@ennerd.com', 'Frode Børli');
 *
 * // Parse from string
 * $mailbox = Mailbox::fromString('Frode Børli <frode@ennerd.com>');
 * $mailbox = Mailbox::fromString('frode@ennerd.com');
 *
 * // Modify (immutable)
 * $mailbox = $mailbox->withDisplayName('New Name');
 *
 * // Use in string context
 * echo $mailbox;  // "Frode Børli <frode@ennerd.com>"
 * ```
 *
 * @see https://datatracker.ietf.org/doc/html/rfc5322#section-3.4
 */
class Mailbox implements MailboxInterface
{
    private string $addrSpec;
    private ?string $displayName;

    /**
     * Create a mailbox
     *
     * @param string $addrSpec The email address (local@domain)
     * @param string|null $displayName Optional display name
     * @throws \InvalidArgumentException If the addr-spec is invalid
     */
    public function __construct(string $addrSpec, ?string $displayName = null)
    {
        $addrSpec = trim($addrSpec);

        if (!self::isValidAddrSpec($addrSpec)) {
            throw new \InvalidArgumentException("Invalid email address: {$addrSpec}");
        }

        $this->addrSpec = $addrSpec;
        $this->displayName = $displayName !== null ? trim($displayName) : null;

        if ($this->displayName === '') {
            $this->displayName = null;
        }
    }

    /**
     * Parse a mailbox from a string
     *
     * Accepts formats:
     * - `email@domain.com`
     * - `Display Name <email@domain.com>`
     * - `"Display Name" <email@domain.com>`
     *
     * @param string $string The mailbox string to parse
     * @return static
     * @throws \InvalidArgumentException If the string cannot be parsed
     */
    public static function fromString(string $string): static
    {
        $string = trim($string);

        if ($string === '') {
            throw new \InvalidArgumentException('Mailbox string cannot be empty');
        }

        // Try to match "Display Name <email>" or <email> format
        if (preg_match('/^(.+?)\s*<([^>]+)>$/', $string, $matches)) {
            $displayName = trim($matches[1]);
            $addrSpec = trim($matches[2]);

            // Remove surrounding quotes from display name if present
            if (preg_match('/^"(.+)"$/', $displayName, $quotedMatches)) {
                $displayName = $quotedMatches[1];
                // Unescape quoted pairs
                $displayName = stripslashes($displayName);
            }

            return new static($addrSpec, $displayName ?: null);
        }

        // Just an email address
        return new static($string);
    }

    /**
     * Validate an addr-spec (email address)
     *
     * Uses a simplified validation that covers the vast majority of valid emails.
     * Full RFC 5322 validation is complex; this covers practical cases.
     *
     * @param string $addrSpec
     * @return bool
     */
    private static function isValidAddrSpec(string $addrSpec): bool
    {
        if ($addrSpec === '') {
            return false;
        }

        // Use PHP's built-in filter for basic validation
        if (filter_var($addrSpec, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getAddrSpec(): string
    {
        return $this->addrSpec;
    }

    /**
     * {@inheritdoc}
     */
    public function getDisplayName(): ?string
    {
        return $this->displayName;
    }

    /**
     * {@inheritdoc}
     */
    public function withAddrSpec(string $addrSpec): static
    {
        $addrSpec = trim($addrSpec);

        if (!self::isValidAddrSpec($addrSpec)) {
            throw new \InvalidArgumentException("Invalid email address: {$addrSpec}");
        }

        $clone = clone $this;
        $clone->addrSpec = $addrSpec;
        return $clone;
    }

    /**
     * {@inheritdoc}
     */
    public function withDisplayName(?string $displayName): static
    {
        $clone = clone $this;
        $clone->displayName = $displayName !== null ? trim($displayName) : null;

        if ($clone->displayName === '') {
            $clone->displayName = null;
        }

        return $clone;
    }

    /**
     * {@inheritdoc}
     */
    public function __toString(): string
    {
        if ($this->displayName === null) {
            return $this->addrSpec;
        }

        // Check if display name needs quoting
        // Quote if it contains specials: ()<>@,;:\".[]
        if (preg_match('/[()<>@,;:\\\\".\[\]]/', $this->displayName)) {
            // Escape quotes and backslashes, wrap in quotes
            $escaped = addcslashes($this->displayName, '"\\');
            return "\"{$escaped}\" <{$this->addrSpec}>";
        }

        return "{$this->displayName} <{$this->addrSpec}>";
    }
}
