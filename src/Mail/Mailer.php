<?php

namespace mini\Mail;

/**
 * Mail sending with envelope handling and Bcc stripping
 *
 * This class wraps a MailTransportInterface and provides:
 * - Automatic envelope sender resolution (explicit > default > From header)
 * - Automatic recipient collection from To + Cc + Bcc headers
 * - Bcc header stripping before sending (transport never sees Bcc)
 *
 * Usage:
 *   $mailer = new Mailer($transport);
 *   $mailer->send($email);
 *
 *   // Or with explicit envelope:
 *   $mailer->send($email, 'bounce@example.com', ['recipient@example.com']);
 */
final class Mailer implements MailTransportInterface
{
    public function __construct(
        private MailTransportInterface $transport,
        private ?string $defaultSender = null
    ) {}

    /**
     * Send an email
     *
     * @param EmailInterface $email The email to send
     * @param string $sender Envelope sender - if empty, uses defaultSender or From header
     * @param array<string> $recipients Envelope recipients - if empty, collects from To+Cc+Bcc
     * @throws \InvalidArgumentException if no sender can be determined
     * @throws MailTransportException on transport failure
     */
    public function send(EmailInterface $email, string $sender = '', array $recipients = []): void
    {
        // Resolve envelope sender
        if ($sender === '') {
            $sender = $this->resolveSender($email);
        }

        // Resolve envelope recipients
        if (empty($recipients)) {
            $recipients = $this->collectRecipients($email);
        }

        if (empty($recipients)) {
            throw new \InvalidArgumentException('No recipients specified');
        }

        // Strip Bcc header before sending
        $email = $email->withoutHeader('Bcc');

        // Delegate to transport
        $this->transport->send($email, $sender, $recipients);
    }

    /**
     * Resolve envelope sender address
     */
    private function resolveSender(EmailInterface $email): string
    {
        // Try default sender from config
        if ($this->defaultSender !== null && $this->defaultSender !== '') {
            return $this->extractAddress($this->defaultSender);
        }

        // Try From header
        $from = $email->getFrom();
        if (!empty($from)) {
            return $from[0]->getAddrSpec();
        }

        throw new \InvalidArgumentException(
            'No sender specified and no From header in email'
        );
    }

    /**
     * Collect all recipient addresses from To, Cc, and Bcc headers
     *
     * @return array<string>
     */
    private function collectRecipients(EmailInterface $email): array
    {
        $recipients = [];

        foreach ($email->getTo() as $mailbox) {
            $recipients[] = $mailbox->getAddrSpec();
        }

        foreach ($email->getCc() as $mailbox) {
            $recipients[] = $mailbox->getAddrSpec();
        }

        foreach ($email->getBcc() as $mailbox) {
            $recipients[] = $mailbox->getAddrSpec();
        }

        return array_unique($recipients);
    }

    /**
     * Extract bare email address from a mailbox string
     */
    private function extractAddress(string $mailbox): string
    {
        return Mailbox::fromString($mailbox)->getAddrSpec();
    }
}
