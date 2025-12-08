<?php

namespace mini\Mail;

/**
 * Mailbox Interface
 *
 * Represents an RFC 5322 mailbox: a display name with an addr-spec (email address).
 * Implements Stringable to allow seamless use in string contexts.
 *
 * ## Terminology (RFC 5322)
 *
 * - **mailbox** = display name + addr-spec (e.g., `Frode Børli <frode@ennerd.com>`)
 * - **addr-spec** = local-part "@" domain (e.g., `frode@ennerd.com`)
 * - **display name** = human-readable name (e.g., `Frode Børli`)
 *
 * ## Usage
 *
 * ```php
 * // Parse from string
 * $mailbox = Mailbox::fromString('Frode Børli <frode@ennerd.com>');
 * $mailbox->getAddrSpec();     // 'frode@ennerd.com'
 * $mailbox->getDisplayName();  // 'Frode Børli'
 *
 * // Build programmatically
 * $mailbox = new Mailbox('frode@ennerd.com');
 * $mailbox = $mailbox->withDisplayName('Frode Børli');
 *
 * // Use in string context
 * echo $mailbox;  // 'Frode Børli <frode@ennerd.com>'
 *
 * // Email-only (no display name)
 * $mailbox = new Mailbox('noreply@example.com');
 * echo $mailbox;  // 'noreply@example.com'
 * ```
 *
 * ## Format
 *
 * The string representation follows RFC 5322:
 * - With display name: `Display Name <local@domain>`
 * - Without display name: `local@domain`
 *
 * Special characters in the display name are quoted as needed.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc5322#section-3.4
 */
interface MailboxInterface extends \Stringable
{
    /**
     * Get the addr-spec (email address)
     *
     * @return string The email address (e.g., 'frode@ennerd.com')
     */
    public function getAddrSpec(): string;

    /**
     * Get the display name
     *
     * @return string|null The display name, or null if not set
     */
    public function getDisplayName(): ?string;

    /**
     * Return instance with the specified addr-spec
     *
     * @param string $addrSpec The email address
     * @return static
     * @throws \InvalidArgumentException If the addr-spec is invalid
     */
    public function withAddrSpec(string $addrSpec): static;

    /**
     * Return instance with the specified display name
     *
     * @param string|null $displayName The display name, or null to remove
     * @return static
     */
    public function withDisplayName(?string $displayName): static;

    /**
     * Get the RFC 5322 formatted mailbox string
     *
     * Returns:
     * - `Display Name <local@domain>` if display name is set
     * - `local@domain` if no display name
     *
     * @return string
     */
    public function __toString(): string;
}
