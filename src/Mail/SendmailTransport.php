<?php

namespace mini\Mail;

/**
 * Mail transport using the sendmail binary
 *
 * This transport pipes the complete RFC 5322 message directly to sendmail.
 * The Email's StreamInterface provides headers + body in wire format.
 *
 * Usage:
 *   $transport = new SendmailTransport(); // Uses /usr/sbin/sendmail
 *   $transport = new SendmailTransport('/usr/local/bin/sendmail');
 */
class SendmailTransport implements MailTransportInterface
{
    private string $command;

    /**
     * @param string $sendmailPath Path to sendmail binary
     */
    public function __construct(string $sendmailPath = '/usr/sbin/sendmail')
    {
        $this->command = $sendmailPath;
    }

    public function send(EmailInterface $email, string $sender, array $recipients): void
    {
        // Build sendmail command with envelope sender and recipients
        // -oi: don't treat a line with only . as end of input
        // -f: envelope sender
        // -t could be used to read recipients from headers, but we pass them explicitly
        $cmd = sprintf(
            '%s -oi -f %s -- %s',
            escapeshellcmd($this->command),
            escapeshellarg($sender),
            implode(' ', array_map('escapeshellarg', $recipients))
        );

        $descriptors = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        $process = proc_open($cmd, $descriptors, $pipes);

        if (!is_resource($process)) {
            throw new MailTransportException("Failed to open sendmail process: $cmd");
        }

        try {
            // Stream the email to sendmail's stdin
            while (!$email->eof()) {
                $chunk = $email->read(8192);
                if ($chunk !== '') {
                    fwrite($pipes[0], $chunk);
                }
            }
            fclose($pipes[0]);

            // Read any output
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            $exitCode = proc_close($process);

            if ($exitCode !== 0) {
                $error = trim($stderr ?: $stdout ?: "Exit code: $exitCode");
                throw new MailTransportException("sendmail failed: $error");
            }
        } catch (MailTransportException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Ensure process is closed on error
            if (is_resource($pipes[0])) fclose($pipes[0]);
            if (is_resource($pipes[1])) fclose($pipes[1]);
            if (is_resource($pipes[2])) fclose($pipes[2]);
            proc_close($process);
            throw new MailTransportException("sendmail failed: " . $e->getMessage(), 0, $e);
        }
    }
}
