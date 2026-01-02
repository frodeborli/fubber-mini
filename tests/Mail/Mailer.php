<?php
/**
 * Test Mailer class - envelope handling and Bcc stripping
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Test;
use mini\Mail\Email;
use mini\Mail\EmailInterface;
use mini\Mail\Mailer;
use mini\Mail\MailTransportInterface;

/**
 * Mock transport that records calls for testing
 */
class MockTransport implements MailTransportInterface
{
    public array $calls = [];

    public function send(EmailInterface $email, string $sender, array $recipients): void
    {
        $this->calls[] = [
            'email' => $email,
            'sender' => $sender,
            'recipients' => $recipients,
        ];
    }
}

$test = new class extends Test {

    // ========================================
    // Sender resolution
    // ========================================

    public function testUsesExplicitSender(): void
    {
        $transport = new MockTransport();
        $mailer = new Mailer($transport);

        $email = (new Email())
            ->withFrom('from@example.com')
            ->withTo('to@example.com');

        $mailer->send($email, 'explicit@example.com');

        $this->assertSame('explicit@example.com', $transport->calls[0]['sender']);
    }

    public function testUsesDefaultSenderFromConfig(): void
    {
        $transport = new MockTransport();
        $mailer = new Mailer($transport, 'default@example.com');

        $email = (new Email())
            ->withFrom('from@example.com')
            ->withTo('to@example.com');

        $mailer->send($email);

        $this->assertSame('default@example.com', $transport->calls[0]['sender']);
    }

    public function testFallsBackToFromHeader(): void
    {
        $transport = new MockTransport();
        $mailer = new Mailer($transport);

        $email = (new Email())
            ->withFrom('from@example.com')
            ->withTo('to@example.com');

        $mailer->send($email);

        $this->assertSame('from@example.com', $transport->calls[0]['sender']);
    }

    public function testExtractsAddressFromDisplayName(): void
    {
        $transport = new MockTransport();
        $mailer = new Mailer($transport, 'Sender Name <sender@example.com>');

        $email = (new Email())
            ->withFrom('from@example.com')
            ->withTo('to@example.com');

        $mailer->send($email);

        $this->assertSame('sender@example.com', $transport->calls[0]['sender']);
    }

    public function testThrowsIfNoSender(): void
    {
        $transport = new MockTransport();
        $mailer = new Mailer($transport);

        $email = (new Email())->withTo('to@example.com');

        $this->expectException(\InvalidArgumentException::class);
        $mailer->send($email);
    }

    // ========================================
    // Recipient collection
    // ========================================

    public function testCollectsRecipientsFromTo(): void
    {
        $transport = new MockTransport();
        $mailer = new Mailer($transport);

        $email = (new Email())
            ->withFrom('from@example.com')
            ->withTo('a@example.com', 'b@example.com');

        $mailer->send($email);

        $this->assertSame(['a@example.com', 'b@example.com'], $transport->calls[0]['recipients']);
    }

    public function testCollectsRecipientsFromCc(): void
    {
        $transport = new MockTransport();
        $mailer = new Mailer($transport);

        $email = (new Email())
            ->withFrom('from@example.com')
            ->withTo('to@example.com')
            ->withCc('cc@example.com');

        $mailer->send($email);

        $recipients = $transport->calls[0]['recipients'];
        $this->assertTrue(in_array('to@example.com', $recipients));
        $this->assertTrue(in_array('cc@example.com', $recipients));
    }

    public function testCollectsRecipientsFromBcc(): void
    {
        $transport = new MockTransport();
        $mailer = new Mailer($transport);

        $email = (new Email())
            ->withFrom('from@example.com')
            ->withTo('to@example.com')
            ->withBcc('bcc@example.com');

        $mailer->send($email);

        $recipients = $transport->calls[0]['recipients'];
        $this->assertTrue(in_array('to@example.com', $recipients));
        $this->assertTrue(in_array('bcc@example.com', $recipients));
    }

    public function testDeduplicatesRecipients(): void
    {
        $transport = new MockTransport();
        $mailer = new Mailer($transport);

        $email = (new Email())
            ->withFrom('from@example.com')
            ->withTo('same@example.com')
            ->withCc('same@example.com');

        $mailer->send($email);

        $this->assertCount(1, $transport->calls[0]['recipients']);
    }

    public function testUsesExplicitRecipients(): void
    {
        $transport = new MockTransport();
        $mailer = new Mailer($transport);

        $email = (new Email())
            ->withFrom('from@example.com')
            ->withTo('to@example.com');

        $mailer->send($email, '', ['explicit@example.com']);

        $this->assertSame(['explicit@example.com'], $transport->calls[0]['recipients']);
    }

    public function testThrowsIfNoRecipients(): void
    {
        $transport = new MockTransport();
        $mailer = new Mailer($transport);

        $email = (new Email())->withFrom('from@example.com');

        $this->expectException(\InvalidArgumentException::class);
        $mailer->send($email);
    }

    // ========================================
    // Bcc stripping
    // ========================================

    public function testStripsBccHeader(): void
    {
        $transport = new MockTransport();
        $mailer = new Mailer($transport);

        $email = (new Email())
            ->withFrom('from@example.com')
            ->withTo('to@example.com')
            ->withBcc('secret@example.com');

        $mailer->send($email);

        $sentEmail = $transport->calls[0]['email'];
        $this->assertFalse($sentEmail->hasHeader('Bcc'));
    }

    public function testBccRecipientStillReceivesEmail(): void
    {
        $transport = new MockTransport();
        $mailer = new Mailer($transport);

        $email = (new Email())
            ->withFrom('from@example.com')
            ->withTo('to@example.com')
            ->withBcc('secret@example.com');

        $mailer->send($email);

        // Bcc recipient should be in envelope recipients
        $recipients = $transport->calls[0]['recipients'];
        $this->assertTrue(in_array('secret@example.com', $recipients));

        // But Bcc header should not be in message
        $sentEmail = $transport->calls[0]['email'];
        $this->assertFalse($sentEmail->hasHeader('Bcc'));
    }

    // ========================================
    // Original email unchanged
    // ========================================

    public function testOriginalEmailUnchanged(): void
    {
        $transport = new MockTransport();
        $mailer = new Mailer($transport);

        $email = (new Email())
            ->withFrom('from@example.com')
            ->withTo('to@example.com')
            ->withBcc('secret@example.com');

        $mailer->send($email);

        // Original email should still have Bcc
        $this->assertTrue($email->hasHeader('Bcc'));
    }

    // ========================================
    // Integration
    // ========================================

    public function testCompleteEmailFlow(): void
    {
        $transport = new MockTransport();
        $mailer = new Mailer($transport, 'default@example.com');

        $email = (new Email())
            ->withFrom('Sender <sender@example.com>')
            ->withTo('Recipient <recipient@example.com>')
            ->withCc('CC User <cc@example.com>')
            ->withBcc('secret@example.com')
            ->withSubject('Test Subject')
            ->withTextBody('Test body');

        $mailer->send($email);

        $call = $transport->calls[0];

        // Sender from config (takes precedence over From header)
        $this->assertSame('default@example.com', $call['sender']);

        // All recipients collected
        $this->assertCount(3, $call['recipients']);
        $this->assertTrue(in_array('recipient@example.com', $call['recipients']));
        $this->assertTrue(in_array('cc@example.com', $call['recipients']));
        $this->assertTrue(in_array('secret@example.com', $call['recipients']));

        // Bcc stripped from email
        $this->assertFalse($call['email']->hasHeader('Bcc'));

        // Other headers preserved
        $this->assertTrue($call['email']->hasHeader('From'));
        $this->assertTrue($call['email']->hasHeader('To'));
        $this->assertTrue($call['email']->hasHeader('Cc'));
    }
};

exit($test->run());
