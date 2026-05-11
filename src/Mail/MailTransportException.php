<?php

namespace mini\Mail;

/**
 * Exception thrown when mail transport fails
 */
class MailTransportException extends \RuntimeException
{
    public function __construct(string|\Stringable $message = "", int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct((string) $message, $code, $previous);
    }
}
