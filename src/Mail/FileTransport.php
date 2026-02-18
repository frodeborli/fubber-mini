<?php

namespace mini\Mail;

/**
 * File-based mail transport for development and testing
 *
 * Logs complete emails (headers + body) to a file instead of sending them.
 * Useful for development when you don't have SMTP configured, and for
 * testing email functionality without sending real emails.
 *
 * Usage:
 *   $transport = new FileTransport('data/mail.log');
 *   $mailer = new Mailer($transport);
 *   $mailer->send($email);
 *
 *   // Then check data/mail.log for the email content
 */
final class FileTransport implements MailTransportInterface
{
    private string $logPath;

    /**
     * @param string $logPath Path to the log file (relative paths are resolved from getcwd())
     */
    public function __construct(string $logPath = 'data/mail.log')
    {
        $this->logPath = $logPath;
    }

    /**
     * Log an email to the file
     *
     * @param EmailInterface $email The email to log
     * @param string $sender Envelope sender (MAIL FROM address)
     * @param array<string> $recipients Envelope recipients (RCPT TO addresses)
     * @throws MailTransportException on failure
     */
    public function send(EmailInterface $email, string $sender, array $recipients): void
    {
        // Resolve relative path
        $path = $this->logPath;
        if (!str_starts_with($path, '/')) {
            $path = getcwd() . '/' . $path;
        }

        // Ensure directory exists
        $dir = dirname($path);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                throw new MailTransportException("Failed to create directory: {$dir}");
            }
        }

        // Build log entry
        $timestamp = date('Y-m-d H:i:s');
        $separator = str_repeat('=', 78);

        $entry = "{$separator}\n";
        $entry .= "LOGGED EMAIL - {$timestamp}\n";
        $entry .= "{$separator}\n";
        $entry .= "Envelope-From: {$sender}\n";
        $entry .= "Envelope-To: " . implode(', ', $recipients) . "\n";
        $entry .= "{$separator}\n";

        // Get complete email content (headers + body)
        $entry .= (string) $email;
        $entry .= "\n\n";

        // Append to file
        if (file_put_contents($path, $entry, FILE_APPEND | LOCK_EX) === false) {
            throw new MailTransportException("Failed to write to mail log: {$path}");
        }
    }

    /**
     * Get the configured log path
     */
    public function getLogPath(): string
    {
        return $this->logPath;
    }
}
