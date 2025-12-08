<?php
/**
 * Default Mail Transport configuration for Mini framework
 *
 * Returns a Mailer wrapping NativeMailTransport (PHP's mail() function).
 * Applications can override by creating _config/mini/Mail/MailTransportInterface.php
 */

use mini\Mail\Mailer;
use mini\Mail\NativeMailTransport;

return new Mailer(new NativeMailTransport());
