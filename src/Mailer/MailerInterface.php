<?php
namespace mini\Mailer;

use Symfony\Component\Mime\Email;

/**
 * Mail sending interface
 *
 * Thin wrapper around Symfony Mailer configured via environment variables.
 */
interface MailerInterface
{
    /**
     * Send an email
     *
     * @param Email $email Symfony Email object
     * @return void
     */
    public function send(Email $email): void;
}
