<?php

namespace mini\Mail;

use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Email Composition Interface
 *
 * Provides a declarative API for composing RFC 5322 email messages with MIME support.
 * The implementation uses lazy compilation - you describe what you want (text, HTML,
 * attachments, inline images) and the correct MIME structure is built automatically.
 *
 * ## Basic Usage
 *
 * ```php
 * $email = (new Email())
 *     ->withFrom('sender@example.com')
 *     ->withTo('recipient@example.com')
 *     ->withSubject('Hello!')
 *     ->withTextBody('Plain text version')
 *     ->withHtmlBody('<h1>HTML version</h1>');
 *
 * // Cast to string for complete RFC 5322 message (headers + body)
 * $raw = (string) $email;
 *
 * // Or stream it (recommended for large emails with attachments)
 * while (!$email->eof()) {
 *     fwrite($pipe, $email->read(8192));
 * }
 *
 * // Pipe to sendmail
 * $pipe = popen('/usr/sbin/sendmail -t', 'w');
 * fwrite($pipe, (string) $email);
 * pclose($pipe);
 * ```
 *
 * ## Mailbox Addresses
 *
 * Address methods accept both strings and MailboxInterface:
 *
 * ```php
 * // Simple string
 * $email->withFrom('sender@example.com');
 *
 * // String with display name
 * $email->withFrom('Frode Børli <frode@ennerd.com>');
 *
 * // MailboxInterface
 * $email->withFrom(new Mailbox('frode@ennerd.com', 'Frode Børli'));
 *
 * // Parsed from string
 * $email->withFrom(Mailbox::fromString('Frode Børli <frode@ennerd.com>'));
 *
 * // Multiple recipients
 * $email->withTo('alice@example.com', 'bob@example.com', $carolMailbox);
 *
 * // Add recipients incrementally
 * $email->withTo('alice@example.com')
 *       ->withAddedTo('bob@example.com');
 * ```
 *
 * ## HTML with Inline Images
 *
 * Inline images are referenced in HTML using the `cid:` URL scheme.
 * The array keys become Content-IDs:
 *
 * ```php
 * $email = (new Email())
 *     ->withFrom('newsletter@example.com')
 *     ->withTo('subscriber@example.com')
 *     ->withSubject('Weekly Update')
 *     ->withTextBody('View this email in a browser for images.')
 *     ->withHtmlBody(
 *         '<html>
 *           <body>
 *             <img src="cid:header" alt="Header">
 *             <p>Hello!</p>
 *             <img src="cid:logo" alt="Logo">
 *           </body>
 *         </html>',
 *         [
 *             'header' => '/path/to/header.png',           // String = file path
 *             'logo' => Message::fromFile('logo.png'),    // Or MessageInterface
 *         ]
 *     );
 * ```
 *
 * ## Attachments
 *
 * Add file attachments with automatic MIME type detection:
 *
 * ```php
 * $email = (new Email())
 *     ->withFrom('reports@example.com')
 *     ->withTo('manager@example.com')
 *     ->withSubject('Monthly Report')
 *     ->withTextBody('Please find the report attached.')
 *     ->withAttachments([
 *         '/path/to/report.pdf',                    // Filename from path
 *         '/path/to/data.csv',
 *         Message::fromFile('/tmp/generated.xlsx'), // Filename from Message
 *     ]);
 * ```
 *
 * To override the filename shown to recipients, use string keys:
 *
 * ```php
 * $email->withAttachments([
 *     'Monthly Report.pdf' => '/tmp/report-2024-01.pdf',
 *     'Raw Data.csv' => $csvMessageInterface,
 * ]);
 * ```
 *
 * ## Complete Example
 *
 * An email with text alternative, HTML with inline images, and attachments:
 *
 * ```php
 * $email = (new Email())
 *     ->withFrom('Name <sender@example.com>')
 *     ->withTo('recipient@example.com')
 *     ->withCc('cc@example.com')
 *     ->withReplyTo('replies@example.com')
 *     ->withSubject('Project Update')
 *     ->withTextBody('Please view in HTML for the full experience.')
 *     ->withHtmlBody(
 *         file_get_contents('email-template.html'),
 *         [
 *             'logo' => '/assets/logo.png',
 *             'chart' => Message::fromFile('/tmp/chart.png'),
 *         ]
 *     )
 *     ->withAttachments([
 *         'Project Plan.pdf' => '/documents/plan.pdf',
 *         'meeting.ics' => $calendarInvite,
 *     ]);
 * ```
 *
 * ## MIME Structure
 *
 * The implementation automatically builds the correct nested MIME structure:
 *
 * ```
 * multipart/mixed
 * ├── multipart/alternative
 * │   ├── text/plain
 * │   └── multipart/related
 * │       ├── text/html
 * │       ├── image/png (Content-ID: <logo>)
 * │       └── image/png (Content-ID: <chart>)
 * ├── application/pdf (attachment: Project Plan.pdf)
 * └── text/calendar (attachment: meeting.ics)
 * ```
 *
 * ## Templating Pattern
 *
 * Create reusable templates with immutable composition:
 *
 * ```php
 * $template = (new Email())
 *     ->withFrom('noreply@example.com')
 *     ->withSubject('Welcome!')
 *     ->withHtmlBody('<h1>Welcome, {name}!</h1>', ['logo' => '/assets/logo.png']);
 *
 * foreach ($users as $user) {
 *     $email = $template
 *         ->withTo($user->email)
 *         ->withHtmlBody(
 *             str_replace('{name}', $user->name, $template->getHtmlBody()),
 *             $template->getInlines()
 *         );
 *
 *     $transport->send($email);
 * }
 * ```
 *
 * @see https://datatracker.ietf.org/doc/html/rfc5322 Internet Message Format
 * @see https://datatracker.ietf.org/doc/html/rfc2045 MIME Part One
 * @see https://datatracker.ietf.org/doc/html/rfc2046 MIME Part Two: Media Types
 */
interface EmailInterface extends MessageInterface, StreamInterface
{
    // =========================================================================
    // Address Headers
    // =========================================================================

    /**
     * Get the From addresses
     *
     * @return MailboxInterface[] Array of sender mailboxes
     */
    public function getFrom(): array;

    /**
     * Return instance with the specified From address(es)
     *
     * Replaces any existing From addresses.
     *
     * @param MailboxInterface|string ...$mailboxes One or more mailboxes
     * @return static
     * @throws \InvalidArgumentException If a mailbox string is invalid
     */
    public function withFrom(MailboxInterface|string ...$mailboxes): static;

    /**
     * Get the To addresses
     *
     * @return MailboxInterface[] Array of recipient mailboxes
     */
    public function getTo(): array;

    /**
     * Return instance with the specified To address(es)
     *
     * Replaces any existing To addresses.
     *
     * @param MailboxInterface|string ...$mailboxes One or more mailboxes
     * @return static
     * @throws \InvalidArgumentException If a mailbox string is invalid
     */
    public function withTo(MailboxInterface|string ...$mailboxes): static;

    /**
     * Return instance with additional To address(es)
     *
     * Adds to existing To addresses without replacing them.
     *
     * @param MailboxInterface|string ...$mailboxes One or more mailboxes to add
     * @return static
     * @throws \InvalidArgumentException If a mailbox string is invalid
     */
    public function withAddedTo(MailboxInterface|string ...$mailboxes): static;

    /**
     * Get the Cc addresses
     *
     * @return MailboxInterface[] Array of CC mailboxes
     */
    public function getCc(): array;

    /**
     * Return instance with the specified Cc address(es)
     *
     * Replaces any existing Cc addresses.
     *
     * @param MailboxInterface|string ...$mailboxes One or more mailboxes
     * @return static
     * @throws \InvalidArgumentException If a mailbox string is invalid
     */
    public function withCc(MailboxInterface|string ...$mailboxes): static;

    /**
     * Return instance with additional Cc address(es)
     *
     * Adds to existing Cc addresses without replacing them.
     *
     * @param MailboxInterface|string ...$mailboxes One or more mailboxes to add
     * @return static
     * @throws \InvalidArgumentException If a mailbox string is invalid
     */
    public function withAddedCc(MailboxInterface|string ...$mailboxes): static;

    /**
     * Get the Bcc addresses
     *
     * @return MailboxInterface[] Array of BCC mailboxes
     */
    public function getBcc(): array;

    /**
     * Return instance with the specified Bcc address(es)
     *
     * Replaces any existing Bcc addresses.
     *
     * @param MailboxInterface|string ...$mailboxes One or more mailboxes
     * @return static
     * @throws \InvalidArgumentException If a mailbox string is invalid
     */
    public function withBcc(MailboxInterface|string ...$mailboxes): static;

    /**
     * Return instance with additional Bcc address(es)
     *
     * Adds to existing Bcc addresses without replacing them.
     *
     * @param MailboxInterface|string ...$mailboxes One or more mailboxes to add
     * @return static
     * @throws \InvalidArgumentException If a mailbox string is invalid
     */
    public function withAddedBcc(MailboxInterface|string ...$mailboxes): static;

    /**
     * Get the Reply-To addresses
     *
     * @return MailboxInterface[] Array of Reply-To mailboxes
     */
    public function getReplyTo(): array;

    /**
     * Return instance with the specified Reply-To address(es)
     *
     * Replaces any existing Reply-To addresses.
     *
     * @param MailboxInterface|string ...$mailboxes One or more mailboxes
     * @return static
     * @throws \InvalidArgumentException If a mailbox string is invalid
     */
    public function withReplyTo(MailboxInterface|string ...$mailboxes): static;

    /**
     * Return instance with additional Reply-To address(es)
     *
     * Adds to existing Reply-To addresses without replacing them.
     *
     * @param MailboxInterface|string ...$mailboxes One or more mailboxes to add
     * @return static
     * @throws \InvalidArgumentException If a mailbox string is invalid
     */
    public function withAddedReplyTo(MailboxInterface|string ...$mailboxes): static;

    // =========================================================================
    // Subject and Date
    // =========================================================================

    /**
     * Get the subject line
     *
     * @return string|null The subject, or null if not set
     */
    public function getSubject(): ?string;

    /**
     * Return instance with the specified subject
     *
     * @param string $subject The subject line
     * @return static
     */
    public function withSubject(string $subject): static;

    /**
     * Get the Date header
     *
     * @return string|null RFC 5322 formatted date, or null if not set
     */
    public function getDate(): ?string;

    /**
     * Return instance with the specified Date
     *
     * If not set, the current date/time is used when the email is compiled.
     *
     * @param string|\DateTimeInterface $date RFC 5322 date string or DateTimeInterface
     * @return static
     */
    public function withDate(string|\DateTimeInterface $date): static;

    // =========================================================================
    // Body Content
    // =========================================================================

    /**
     * Get the plain text body
     *
     * @return string|null The text body, or null if not set
     */
    public function getTextBody(): ?string;

    /**
     * Return instance with the specified plain text body
     *
     * The text body serves as a fallback for email clients that don't support HTML.
     *
     * @param string $text Plain text content
     * @return static
     */
    public function withTextBody(string $text): static;

    /**
     * Get the HTML body
     *
     * @return string|null The HTML body, or null if not set
     */
    public function getHtmlBody(): ?string;

    /**
     * Return instance with the specified HTML body and optional inline images
     *
     * Inline images are referenced in HTML using `cid:` URLs. The array keys
     * become the Content-ID values:
     *
     * ```php
     * ->withHtmlBody(
     *     '<img src="cid:logo">',
     *     ['logo' => '/path/to/logo.png']
     * )
     * ```
     *
     * @param string $html HTML content
     * @param array<string, MessageInterface|string> $inlines Inline images keyed by Content-ID.
     *        Values can be file paths (string) or MessageInterface instances.
     * @return static
     * @throws \InvalidArgumentException If an inline value is not a valid file path or MessageInterface
     */
    public function withHtmlBody(string $html, array $inlines = []): static;

    /**
     * Get the inline images
     *
     * @return array<string, MessageInterface> Inline images keyed by Content-ID
     */
    public function getInlines(): array;

    // =========================================================================
    // Attachments
    // =========================================================================

    /**
     * Get the attachments
     *
     * @return MessageInterface[] Array of attachment parts
     */
    public function getAttachments(): array;

    /**
     * Return instance with the specified attachments
     *
     * Attachments can be specified as file paths or MessageInterface instances.
     * Use string keys to override the displayed filename:
     *
     * ```php
     * ->withAttachments([
     *     '/path/to/file.pdf',                    // Filename: file.pdf
     *     'Report.pdf' => '/tmp/generated.pdf',   // Filename: Report.pdf
     *     Message::fromFile('data.csv'),          // Filename from Message
     * ])
     * ```
     *
     * This method replaces any existing attachments.
     *
     * @param array<int|string, MessageInterface|string> $attachments
     *        Numeric keys: filename derived from path or Message.
     *        String keys: override the displayed filename.
     * @return static
     * @throws \InvalidArgumentException If an attachment value is not a valid file path or MessageInterface
     */
    public function withAttachments(array $attachments): static;

    // =========================================================================
    // Output
    // =========================================================================

    /**
     * Get the compiled message body as a stream
     *
     * Returns the MIME body (without top-level headers) as a StreamInterface.
     * The MIME structure is built automatically based on the content:
     *
     * - Text only: text/plain
     * - HTML only: text/html (or multipart/related if inlines present)
     * - Both: multipart/alternative
     * - With attachments: wrapped in multipart/mixed
     *
     * @return StreamInterface
     */
    public function getBody(): StreamInterface;
}
