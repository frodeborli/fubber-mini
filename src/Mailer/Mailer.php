<?php
namespace mini\Mailer;

use mini\Exceptions\MissingDependencyException;
use mini\Mini;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mime\Email;

/**
 * Email service using Symfony Mailer
 *
 * Follows Mini philosophy: Uses PHP's native mail configuration by default.
 *
 * Configuration priority:
 * 1. MINI_MAILER_DSN or MAILER_DSN env variable (MINI_ prefix takes precedence)
 * 2. PHP's sendmail_path from php.ini (production default)
 * 3. null://null transport (debug mode - logs but doesn't send)
 *
 * Environment variables (MINI_ prefix optional, but takes precedence):
 * - MINI_MAILER_DSN or MAILER_DSN: Transport DSN (optional)
 * - MINI_MAILER_FROM_EMAIL or MAILER_FROM_EMAIL: Default from email (optional)
 * - MINI_MAILER_FROM_NAME or MAILER_FROM_NAME: Default from name (optional)
 *
 * The MINI_ prefix allows Mini-specific config when using Symfony components
 * alongside Mini (e.g., different mailer configs for each).
 *
 * DSN Examples:
 *   MINI_MAILER_DSN=smtp://user:pass@smtp.example.com:587
 *   MAILER_DSN=sendmail://default
 *   MAILER_DSN=null://null  (log only, don't send)
 *
 * Usage:
 *   $email = (new Email())
 *       ->to('user@example.com')
 *       ->subject('Welcome!')
 *       ->text('Hello')
 *       ->html('<h1>Hello</h1>');
 *
 *   mailer()->send($email);
 */
class Mailer implements MailerInterface
{
    private SymfonyMailer $mailer;
    private ?string $defaultFromEmail;
    private ?string $defaultFromName;

    public function __construct()
    {
        // Check if symfony/mailer is installed
        if (!class_exists(\Symfony\Component\Mailer\Mailer::class)) {
            throw new MissingDependencyException(
                "symfony/mailer is not installed. Run: composer require symfony/mailer"
            );
        }

        // Get DSN from environment, or use PHP's native mail configuration
        // Check MINI_ prefix first, then fallback to standard names
        $dsn = $_ENV['MINI_MAILER_DSN'] ?? $_ENV['MAILER_DSN'] ?? null;

        if ($dsn === null) {
            // In debug mode, use null transport (log only, don't send)
            if (Mini::$mini->debug) {
                $dsn = 'null://null';
            } else {
                // Use PHP's native sendmail (respects php.ini sendmail_path)
                // This is the Mini philosophy: rely on PHP's configuration
                $dsn = 'sendmail://default';
            }
        }

        // Create transport and mailer
        $transport = Transport::fromDsn($dsn);
        $this->mailer = new SymfonyMailer($transport);

        // Store default from email/name (MINI_ prefix takes precedence)
        $this->defaultFromEmail = $_ENV['MINI_MAILER_FROM_EMAIL'] ?? $_ENV['MAILER_FROM_EMAIL'] ?? null;
        $this->defaultFromName = $_ENV['MINI_MAILER_FROM_NAME'] ?? $_ENV['MAILER_FROM_NAME'] ?? null;
    }

    /**
     * Send an email
     *
     * Automatically sets from address if not already set and defaults are configured.
     *
     * @param Email $email Symfony Email object
     * @return void
     */
    public function send(Email $email): void
    {
        // Auto-set from address if configured and not already set
        if ($this->defaultFromEmail && empty($email->getFrom())) {
            if ($this->defaultFromName) {
                $email->from("{$this->defaultFromName} <{$this->defaultFromEmail}>");
            } else {
                $email->from($this->defaultFromEmail);
            }
        }

        $this->mailer->send($email);
    }
}
