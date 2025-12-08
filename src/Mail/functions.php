<?php

namespace mini;

use mini\Mail\MailTransportInterface;

/**
 * Mail Feature - Global Helper Functions
 *
 * Provides the public API for sending emails via Mini framework.
 */

// Register MailTransportInterface service
Mini::$mini->addService(MailTransportInterface::class, Lifetime::Singleton, fn() => Mini::$mini->loadServiceConfig(MailTransportInterface::class));

/**
 * Get the mailer instance for sending emails
 *
 * Returns a MailTransportInterface (by default a Mailer wrapping NativeMailTransport).
 * The default Mailer handles:
 * - Automatic Bcc header stripping
 * - Envelope sender resolution (explicit > From header)
 * - Envelope recipient collection from To + Cc + Bcc
 *
 * Usage:
 *   use function mini\mailer;
 *
 *   $email = (new Email())
 *       ->withFrom('sender@example.com')
 *       ->withTo('recipient@example.com')
 *       ->withSubject('Hello')
 *       ->withTextBody('Hello World');
 *
 *   mailer()->send($email);
 *
 * Configuration:
 *   Override by creating _config/mini/Mail/MailTransportInterface.php:
 *
 *     use mini\Mail\Mailer;
 *     use mini\Mail\SendmailTransport;
 *     return new Mailer(new SendmailTransport());
 *
 * @return MailTransportInterface
 */
function mailer(): MailTransportInterface
{
    return Mini::$mini->get(MailTransportInterface::class);
}
