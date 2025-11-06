<?php

namespace mini;

use mini\Mailer\Mailer;
use mini\Mailer\MailerInterface;

/**
 * Mailer Feature - Global Helper Functions
 *
 * These functions provide the public API for the mini\Mailer feature.
 */

// Register Mailer service when this file is loaded (after bootstrap.php)
// Only register if not already registered (allows app to override)
if (!Mini::$mini->has(MailerInterface::class)) {
    Mini::$mini->addService(MailerInterface::class, Lifetime::Singleton, fn() => new Mailer());
}

/**
 * Create a new email message
 *
 * Returns a fluent email builder with send() method for convenient one-line emails.
 * Requires symfony/mailer to be installed (composer require symfony/mailer).
 *
 * Mini philosophy: Works out-of-the-box using PHP's native mail configuration.
 * - Production: Uses sendmail_path from php.ini
 * - Debug mode: Logs emails but doesn't send (null transport)
 * - Override: Set MINI_MAILER_DSN or MAILER_DSN for custom SMTP
 *
 * Configuration via .env (optional, MINI_ prefix takes precedence):
 *   MINI_MAILER_DSN=smtp://user:pass@smtp.example.com:587
 *   MINI_MAILER_FROM_EMAIL=noreply@example.com
 *   MINI_MAILER_FROM_NAME="My Application"
 *
 *   # Or use standard Symfony names (MINI_ prefix checked first):
 *   MAILER_DSN=smtp://user:pass@smtp.example.com:587
 *   MAILER_FROM_EMAIL=noreply@example.com
 *
 * Simple usage:
 *   mail()
 *       ->to('user@example.com')
 *       ->subject('Welcome!')
 *       ->text('Plain text body')
 *       ->html('<h1>HTML body</h1>')
 *       ->send();
 *
 * With attachments:
 *   mail()
 *       ->to('user@example.com')
 *       ->subject('Invoice')
 *       ->text('See attached invoice')
 *       ->attachFromPath('/path/to/invoice.pdf')
 *       ->send();
 *
 * @return Email Email builder instance
 * @throws \mini\Exceptions\MissingDependencyException If symfony/mailer is not installed
 */
function mail(): \mini\Mailer\Email
{
    return new \mini\Mailer\Email();
}

/**
 * Get mailer instance for advanced usage
 *
 * Returns the underlying Symfony Mailer for batch sending, custom envelopes,
 * or other advanced use cases. For simple email sending, use mail() instead.
 *
 * Advanced usage (custom envelope):
 *   $email = new \Symfony\Component\Mime\Email();
 *   $email->to('user@example.com')->subject('Test')->text('Body');
 *
 *   $envelope = new \Symfony\Component\Mailer\Envelope(
 *       new \Symfony\Component\Mime\Address('sender@example.com'),
 *       [new \Symfony\Component\Mime\Address('recipient@example.com')]
 *   );
 *
 *   mailer()->send($email, $envelope);
 *
 * @return MailerInterface Mailer instance (singleton)
 * @throws \mini\Exceptions\MissingDependencyException If symfony/mailer is not installed
 * @throws \RuntimeException If MINI_MAILER_DSN is not configured (and not in debug mode)
 */
function mailer(): MailerInterface
{
    return Mini::$mini->get(MailerInterface::class);
}
