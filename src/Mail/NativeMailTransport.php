<?php

namespace mini\Mail;

/**
 * Mail transport using PHP's native mail() function
 *
 * Note: mail() has a peculiar API where headers and body are separate,
 * and the To/Subject headers must NOT be in the headers parameter.
 * This transport handles that conversion.
 *
 * The -f flag is used to set the envelope sender, which requires
 * safe_mode to be off or the sender to be in safe_mode_allowed_env_vars.
 */
class NativeMailTransport implements MailTransportInterface
{
    /**
     * @param string|null $additionalParams Additional parameters for mail() (e.g., '-f sender@example.com')
     */
    public function __construct(
        private ?string $additionalParams = null
    ) {}

    public function send(EmailInterface $email, string $sender, array $recipients): void
    {
        // mail() requires To header as first parameter
        $to = implode(', ', $recipients);

        // mail() requires Subject as second parameter
        $subject = $email->getSubject() ?? '';

        // Build headers string (excluding To and Subject)
        $headers = $this->buildHeaders($email);

        // Get body content
        $body = (string) $email->getBody();

        // Build additional parameters (envelope sender)
        $params = $this->additionalParams ?? '';
        if ($params === '') {
            $params = '-f' . escapeshellarg($sender);
        }

        $result = mail($to, $subject, $body, $headers, $params);

        if ($result === false) {
            throw new MailTransportException('mail() returned false');
        }
    }

    /**
     * Build headers string for mail(), excluding To and Subject
     */
    private function buildHeaders(EmailInterface $email): string
    {
        $lines = [];

        foreach ($email->getHeaders() as $name => $values) {
            // mail() handles To and Subject separately
            $lower = strtolower($name);
            if ($lower === 'to' || $lower === 'subject') {
                continue;
            }

            foreach ($values as $value) {
                $lines[] = "{$name}: {$value}";
            }
        }

        return implode("\r\n", $lines);
    }
}
