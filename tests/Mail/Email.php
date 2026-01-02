<?php
/**
 * Test Email class - high-level email composition
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Test;
use mini\Mail\Email;
use mini\Mail\Mailbox;
use mini\Mail\Message;

$test = new class extends Test {

    // ========================================
    // Address handling
    // ========================================

    public function testWithFromString(): void
    {
        $email = (new Email())->withFrom('sender@example.com');
        $from = $email->getFrom();

        $this->assertCount(1, $from);
        $this->assertSame('sender@example.com', $from[0]->getAddrSpec());
    }

    public function testWithFromStringWithDisplayName(): void
    {
        $email = (new Email())->withFrom('Test User <test@example.com>');
        $from = $email->getFrom();

        $this->assertSame('test@example.com', $from[0]->getAddrSpec());
        $this->assertSame('Test User', $from[0]->getDisplayName());
    }

    public function testWithFromMailboxInterface(): void
    {
        $mailbox = new Mailbox('test@example.com', 'Test User');
        $email = (new Email())->withFrom($mailbox);
        $from = $email->getFrom();

        $this->assertSame('test@example.com', $from[0]->getAddrSpec());
        $this->assertSame('Test User', $from[0]->getDisplayName());
    }

    public function testWithToMultiple(): void
    {
        $email = (new Email())->withTo('a@example.com', 'b@example.com');
        $to = $email->getTo();

        $this->assertCount(2, $to);
        $this->assertSame('a@example.com', $to[0]->getAddrSpec());
        $this->assertSame('b@example.com', $to[1]->getAddrSpec());
    }

    public function testWithAddedTo(): void
    {
        $email = (new Email())
            ->withTo('a@example.com')
            ->withAddedTo('b@example.com');
        $to = $email->getTo();

        $this->assertCount(2, $to);
    }

    public function testWithCc(): void
    {
        $email = (new Email())->withCc('cc@example.com');
        $this->assertCount(1, $email->getCc());
    }

    public function testWithBcc(): void
    {
        $email = (new Email())->withBcc('bcc@example.com');
        $this->assertCount(1, $email->getBcc());
    }

    public function testWithReplyTo(): void
    {
        $email = (new Email())->withReplyTo('reply@example.com');
        $this->assertCount(1, $email->getReplyTo());
    }

    // ========================================
    // Subject handling
    // ========================================

    public function testWithSubject(): void
    {
        $email = (new Email())->withSubject('Test Subject');
        $this->assertSame('Test Subject', $email->getSubject());
    }

    public function testSubjectSanitizesLineBreaks(): void
    {
        $email = (new Email())->withSubject("Line1\r\nLine2\nLine3");
        $this->assertSame('Line1 Line2 Line3', $email->getSubject());
    }

    public function testSubjectWithUnicodeInHeaders(): void
    {
        $email = (new Email())
            ->withFrom('sender@example.com')
            ->withSubject('Test æøå');

        $headers = $email->getHeaders();
        // Should be RFC 2047 encoded
        $this->assertStringContainsString('=?UTF-8?B?', $headers['Subject'][0]);
    }

    // ========================================
    // Date handling
    // ========================================

    public function testWithDateString(): void
    {
        $date = 'Mon, 01 Jan 2024 12:00:00 +0000';
        $email = (new Email())->withDate($date);
        $this->assertSame($date, $email->getDate());
    }

    public function testWithDateDateTime(): void
    {
        $date = new \DateTimeImmutable('2024-01-01 12:00:00', new \DateTimeZone('UTC'));
        $email = (new Email())->withDate($date);
        $this->assertStringContainsString('2024', $email->getDate());
    }

    public function testAutoDateGenerated(): void
    {
        $email = new Email();
        $headers = $email->getHeaders();
        $this->assertArrayHasKey('Date', $headers);
    }

    // ========================================
    // Body handling
    // ========================================

    public function testWithTextBody(): void
    {
        $email = (new Email())->withTextBody('Hello World');
        $this->assertSame('Hello World', $email->getTextBody());
    }

    public function testWithHtmlBody(): void
    {
        $email = (new Email())->withHtmlBody('<h1>Hello</h1>');
        $this->assertSame('<h1>Hello</h1>', $email->getHtmlBody());
    }

    public function testTextOnlyContentType(): void
    {
        $email = (new Email())
            ->withFrom('sender@example.com')
            ->withTextBody('Hello');

        $contentType = $email->getHeaderLine('Content-Type');
        $this->assertStringContainsString('text/plain', $contentType);
    }

    public function testHtmlOnlyContentType(): void
    {
        $email = (new Email())
            ->withFrom('sender@example.com')
            ->withHtmlBody('<h1>Hello</h1>');

        $contentType = $email->getHeaderLine('Content-Type');
        $this->assertStringContainsString('text/html', $contentType);
    }

    public function testTextAndHtmlContentType(): void
    {
        $email = (new Email())
            ->withFrom('sender@example.com')
            ->withTextBody('Hello')
            ->withHtmlBody('<h1>Hello</h1>');

        $contentType = $email->getHeaderLine('Content-Type');
        $this->assertStringContainsString('multipart/alternative', $contentType);
    }

    // ========================================
    // Attachments
    // ========================================

    public function testWithAttachmentsContentType(): void
    {
        // Create temp file
        $tmpFile = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($tmpFile, 'test content');

        try {
            $email = (new Email())
                ->withFrom('sender@example.com')
                ->withTextBody('See attached')
                ->withAttachments([$tmpFile]);

            $contentType = $email->getHeaderLine('Content-Type');
            $this->assertStringContainsString('multipart/mixed', $contentType);
        } finally {
            unlink($tmpFile);
        }
    }

    public function testAttachmentFilenameFromPath(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($tmpFile, 'test content');

        try {
            $email = (new Email())
                ->withFrom('sender@example.com')
                ->withTextBody('See attached')
                ->withAttachments([$tmpFile]);

            $body = (string) $email->getBody();
            $this->assertStringContainsString('filename=', $body);
        } finally {
            unlink($tmpFile);
        }
    }

    public function testAttachmentFilenameOverride(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($tmpFile, 'test content');

        try {
            $email = (new Email())
                ->withFrom('sender@example.com')
                ->withTextBody('See attached')
                ->withAttachments(['custom-name.txt' => $tmpFile]);

            $body = (string) $email->getBody();
            $this->assertStringContainsString('custom-name.txt', $body);
        } finally {
            unlink($tmpFile);
        }
    }

    // ========================================
    // Inline images
    // ========================================

    public function testInlineImagesContentType(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test') . '.png';
        file_put_contents($tmpFile, 'fake png content');

        try {
            $email = (new Email())
                ->withFrom('sender@example.com')
                ->withHtmlBody('<img src="cid:logo">', ['logo' => $tmpFile]);

            $contentType = $email->getHeaderLine('Content-Type');
            // HTML with inlines = multipart/related
            $this->assertStringContainsString('multipart/related', $contentType);
        } finally {
            unlink($tmpFile);
        }
    }

    public function testInlineContentId(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test') . '.png';
        file_put_contents($tmpFile, 'fake png content');

        try {
            $email = (new Email())
                ->withFrom('sender@example.com')
                ->withHtmlBody('<img src="cid:mylogo">', ['mylogo' => $tmpFile]);

            $body = (string) $email->getBody();
            $this->assertStringContainsString('Content-ID: <mylogo>', $body);
        } finally {
            unlink($tmpFile);
        }
    }

    // ========================================
    // Headers (PSR-7)
    // ========================================

    public function testGetHeaders(): void
    {
        $email = (new Email())
            ->withFrom('sender@example.com')
            ->withTo('recipient@example.com')
            ->withSubject('Test');

        $headers = $email->getHeaders();

        $this->assertArrayHasKey('From', $headers);
        $this->assertArrayHasKey('To', $headers);
        $this->assertArrayHasKey('Subject', $headers);
        $this->assertArrayHasKey('Date', $headers);
        $this->assertArrayHasKey('Message-ID', $headers);
        $this->assertArrayHasKey('MIME-Version', $headers);
        $this->assertArrayHasKey('Content-Type', $headers);
    }

    public function testHasHeader(): void
    {
        $email = (new Email())->withFrom('sender@example.com');
        $this->assertTrue($email->hasHeader('From'));
        $this->assertTrue($email->hasHeader('Date')); // Auto-generated
    }

    public function testWithHeader(): void
    {
        $email = (new Email())->withHeader('X-Custom', 'value');
        $this->assertSame(['value'], $email->getHeader('X-Custom'));
    }

    public function testWithAddedHeader(): void
    {
        $email = (new Email())
            ->withHeader('X-Custom', 'value1')
            ->withAddedHeader('X-Custom', 'value2');

        $this->assertSame(['value1', 'value2'], $email->getHeader('X-Custom'));
    }

    public function testWithoutHeader(): void
    {
        $email = (new Email())
            ->withHeader('X-Custom', 'value')
            ->withoutHeader('X-Custom');

        $this->assertSame([], $email->getHeader('X-Custom'));
    }

    // ========================================
    // Message-ID
    // ========================================

    public function testMessageIdGenerated(): void
    {
        $email = (new Email())->withFrom('sender@example.com');
        $messageId = $email->getHeaderLine('Message-ID');

        $this->assertStringStartsWith('<', $messageId);
        $this->assertStringEndsWith('>', $messageId);
        $this->assertStringContainsString('@example.com', $messageId);
    }

    public function testMessageIdCustom(): void
    {
        $email = (new Email())->withHeader('Message-ID', '<custom@example.com>');
        $this->assertSame('<custom@example.com>', $email->getHeaderLine('Message-ID'));
    }

    // ========================================
    // StreamInterface
    // ========================================

    public function testToString(): void
    {
        $email = (new Email())
            ->withFrom('sender@example.com')
            ->withTo('recipient@example.com')
            ->withSubject('Test')
            ->withTextBody('Hello');

        $raw = (string) $email;

        $this->assertStringContainsString('From:', $raw);
        $this->assertStringContainsString('To:', $raw);
        $this->assertStringContainsString('Subject:', $raw);
        $this->assertStringContainsString('Hello', $raw);
    }

    public function testReadStreaming(): void
    {
        $email = (new Email())
            ->withFrom('sender@example.com')
            ->withTo('recipient@example.com')
            ->withSubject('Test')
            ->withTextBody('Hello World');

        $chunks = [];
        while (!$email->eof()) {
            $chunks[] = $email->read(50);
        }

        $this->assertGreaterThan(1, count($chunks));
        $this->assertStringContainsString('Hello World', implode('', $chunks));
    }

    public function testRewind(): void
    {
        $email = (new Email())
            ->withFrom('sender@example.com')
            ->withTextBody('Hello');

        $first = (string) $email;
        $email->rewind();
        $second = (string) $email;

        $this->assertSame($first, $second);
    }

    public function testIsReadable(): void
    {
        $email = new Email();
        $this->assertTrue($email->isReadable());
    }

    public function testIsNotWritable(): void
    {
        $email = new Email();
        $this->assertFalse($email->isWritable());
    }

    public function testIsNotSeekable(): void
    {
        $email = new Email();
        $this->assertFalse($email->isSeekable());
    }

    // ========================================
    // Encoding
    // ========================================

    public function testNonAsciiDisplayNameEncoded(): void
    {
        $email = (new Email())->withFrom('Frode Børli <frode@example.com>');
        $headers = $email->getHeaders();

        // Should be RFC 2047 encoded
        $this->assertStringContainsString('=?UTF-8?B?', $headers['From'][0]);
    }

    public function testNonAsciiBodyQuotedPrintable(): void
    {
        $email = (new Email())
            ->withFrom('sender@example.com')
            ->withTextBody('Norwegian: æøå');

        // Full email stream includes headers
        $raw = (string) $email;
        $this->assertStringContainsString('Content-Transfer-Encoding: quoted-printable', $raw);
        // Body should be QP-encoded (æøå becomes hex sequences)
        $this->assertStringContainsString('=C3=A6', $raw);
    }

    // ========================================
    // Immutability
    // ========================================

    public function testWithMethodsReturnNewInstance(): void
    {
        $original = new Email();
        $modified = $original->withFrom('test@example.com');

        $this->assertNotSame($original, $modified);
        $this->assertCount(0, $original->getFrom());
        $this->assertCount(1, $modified->getFrom());
    }

    public function testCloneInvalidatesCache(): void
    {
        $email = (new Email())
            ->withFrom('sender@example.com')
            ->withTextBody('Hello');

        // Trigger compilation
        $body1 = (string) $email->getBody();

        // Clone and modify
        $clone = $email->withTextBody('Goodbye');
        $body2 = (string) $clone->getBody();

        $this->assertStringContainsString('Hello', $body1);
        $this->assertStringContainsString('Goodbye', $body2);
    }

    // ========================================
    // Complex email structure
    // ========================================

    public function testCompleteEmailStructure(): void
    {
        $tmpImage = tempnam(sys_get_temp_dir(), 'img') . '.png';
        $tmpAttachment = tempnam(sys_get_temp_dir(), 'att') . '.txt';
        file_put_contents($tmpImage, 'fake image');
        file_put_contents($tmpAttachment, 'attachment content');

        try {
            $email = (new Email())
                ->withFrom('Sender <sender@example.com>')
                ->withTo('recipient@example.com')
                ->withCc('cc@example.com')
                ->withSubject('Complete Test')
                ->withTextBody('Plain text version')
                ->withHtmlBody('<img src="cid:logo"><p>HTML version</p>', [
                    'logo' => $tmpImage,
                ])
                ->withAttachments([
                    'document.txt' => $tmpAttachment,
                ]);

            $raw = (string) $email;

            // Check headers present
            $this->assertStringContainsString('From:', $raw);
            $this->assertStringContainsString('To:', $raw);
            $this->assertStringContainsString('Cc:', $raw);
            $this->assertStringContainsString('Subject:', $raw);
            $this->assertStringContainsString('MIME-Version: 1.0', $raw);

            // Check structure
            $this->assertStringContainsString('multipart/mixed', $raw);
            $this->assertStringContainsString('multipart/alternative', $raw);
            $this->assertStringContainsString('multipart/related', $raw);

            // Check content
            $this->assertStringContainsString('Plain text version', $raw);
            $this->assertStringContainsString('HTML version', $raw);
            $this->assertStringContainsString('Content-ID: <logo>', $raw);
            $this->assertStringContainsString('document.txt', $raw);
        } finally {
            unlink($tmpImage);
            unlink($tmpAttachment);
        }
    }
};

exit($test->run());
