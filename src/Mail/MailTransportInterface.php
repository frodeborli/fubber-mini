<?php

namespace mini\Mail;

/**
 * Interface for sending emails
 *
 * Implementations receive an EmailInterface and envelope information.
 * The Mailer class wraps transports to handle Bcc stripping and envelope defaults.
 */
interface MailTransportInterface
{
    /**
     * Send an email
     *
     * @param EmailInterface $email The email to send
     * @param string $sender Envelope sender (MAIL FROM address)
     * @param array<string> $recipients Envelope recipients (RCPT TO addresses)
     * @throws MailTransportException on failure
     */
    public function send(EmailInterface $email, string $sender, array $recipients): void;
}
