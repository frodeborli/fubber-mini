<?php
namespace mini\Mailer;

use Symfony\Component\Mime\Email as SymfonyEmail;

/**
 * Email class with convenient send() method
 *
 * Extends Symfony's Email class and adds a send() method for fluent API:
 *
 * Usage:
 *   mail()
 *       ->to('user@example.com')
 *       ->subject('Welcome!')
 *       ->text('Plain text body')
 *       ->html('<h1>HTML body</h1>')
 *       ->send();
 *
 * All Symfony Email methods are available (to, cc, bcc, attach, etc.)
 */
class Email extends SymfonyEmail
{
    /**
     * Send this email
     *
     * Convenience method that sends this email using the configured mailer.
     * Equivalent to: mailer()->send($email)
     *
     * @return void
     * @throws \mini\Exceptions\MissingDependencyException If symfony/mailer is not installed
     * @throws \RuntimeException If MINI_MAILER_DSN is not configured
     */
    public function send(): void
    {
        \mini\mailer()->send($this);
    }
}
