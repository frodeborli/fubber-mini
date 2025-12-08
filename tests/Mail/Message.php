<?php
/**
 * Test Message class - MIME message part
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use mini\Test;
use mini\Mail\Message;
use mini\Http\Message\Stream;

$test = new class extends Test {

    // ========================================
    // Construction
    // ========================================

    public function testConstructWithString(): void
    {
        $msg = new Message('text/plain', 'Hello World');

        $this->assertSame('text/plain', $msg->getContentType());
        $this->assertSame('Hello World', (string) $msg->getBody());
    }

    public function testConstructWithResource(): void
    {
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, 'Hello');
        rewind($stream);

        $msg = new Message('text/plain', $stream);
        $this->assertSame('Hello', (string) $msg->getBody());
    }

    public function testConstructWithStreamInterface(): void
    {
        $stream = Stream::cast('Hello');
        $msg = new Message('text/plain', $stream);
        $this->assertSame('Hello', (string) $msg->getBody());
    }

    public function testConstructWithNull(): void
    {
        $msg = new Message('text/plain');
        $this->assertSame('', (string) $msg->getBody());
    }

    public function testConstructWithAdditionalHeaders(): void
    {
        $msg = new Message('text/plain', 'Hello', [
            'Content-Disposition' => 'attachment',
            'X-Custom' => 'value',
        ]);

        $this->assertSame('attachment', $msg->getHeaderLine('Content-Disposition'));
        $this->assertSame('value', $msg->getHeaderLine('X-Custom'));
    }

    // ========================================
    // fromFile
    // ========================================

    public function testFromFileText(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test') . '.txt';
        file_put_contents($tmpFile, 'File content');

        try {
            $msg = Message::fromFile($tmpFile);

            $this->assertSame('text/plain', $msg->getContentType());
            $this->assertSame('File content', (string) $msg->getBody());
        } finally {
            unlink($tmpFile);
        }
    }

    public function testFromFileDetectsMimeType(): void
    {
        $tests = [
            '.html' => 'text/html',
            '.json' => 'application/json',
            '.pdf' => 'application/pdf',
            '.png' => 'image/png',
            '.jpg' => 'image/jpeg',
        ];

        foreach ($tests as $ext => $expected) {
            $tmpFile = tempnam(sys_get_temp_dir(), 'test') . $ext;
            file_put_contents($tmpFile, 'content');

            try {
                $msg = Message::fromFile($tmpFile);
                $this->assertSame($expected, $msg->getContentType(), "Failed for $ext");
            } finally {
                unlink($tmpFile);
            }
        }
    }

    public function testFromFileMimeTypeOverride(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test') . '.txt';
        file_put_contents($tmpFile, 'content');

        try {
            $msg = Message::fromFile($tmpFile, 'application/octet-stream');
            $this->assertSame('application/octet-stream', $msg->getContentType());
        } finally {
            unlink($tmpFile);
        }
    }

    public function testFromFileStoresFilename(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test') . '.txt';
        file_put_contents($tmpFile, 'content');

        try {
            $msg = Message::fromFile($tmpFile);
            $filename = $msg->getHeaderLine('X-Mini-Filename');
            $this->assertNotEmpty($filename);
        } finally {
            unlink($tmpFile);
        }
    }

    public function testFromFileNotFound(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Message::fromFile('/nonexistent/file.txt');
    }

    // ========================================
    // Headers (PSR-7 MessageInterface)
    // ========================================

    public function testGetHeaders(): void
    {
        $msg = new Message('text/plain', 'Hello');
        $headers = $msg->getHeaders();

        $this->assertArrayHasKey('Content-Type', $headers);
    }

    public function testHasHeader(): void
    {
        $msg = new Message('text/plain', 'Hello');

        $this->assertTrue($msg->hasHeader('Content-Type'));
        $this->assertTrue($msg->hasHeader('content-type')); // case-insensitive
        $this->assertFalse($msg->hasHeader('X-Missing'));
    }

    public function testGetHeader(): void
    {
        $msg = new Message('text/plain', 'Hello');

        $this->assertSame(['text/plain'], $msg->getHeader('Content-Type'));
        $this->assertSame([], $msg->getHeader('X-Missing'));
    }

    public function testGetHeaderLine(): void
    {
        $msg = new Message('text/plain', 'Hello', [
            'X-Multi' => ['value1', 'value2'],
        ]);

        $this->assertSame('text/plain', $msg->getHeaderLine('Content-Type'));
        $this->assertSame('value1, value2', $msg->getHeaderLine('X-Multi'));
    }

    public function testWithHeader(): void
    {
        $msg = new Message('text/plain', 'Hello');
        $modified = $msg->withHeader('X-Custom', 'value');

        $this->assertSame([], $msg->getHeader('X-Custom'));
        $this->assertSame(['value'], $modified->getHeader('X-Custom'));
    }

    public function testWithAddedHeader(): void
    {
        $msg = new Message('text/plain', 'Hello', ['X-Custom' => 'value1']);
        $modified = $msg->withAddedHeader('X-Custom', 'value2');

        $this->assertSame(['value1'], $msg->getHeader('X-Custom'));
        $this->assertSame(['value1', 'value2'], $modified->getHeader('X-Custom'));
    }

    public function testWithoutHeader(): void
    {
        $msg = new Message('text/plain', 'Hello', ['X-Custom' => 'value']);
        $modified = $msg->withoutHeader('X-Custom');

        $this->assertTrue($msg->hasHeader('X-Custom'));
        $this->assertFalse($modified->hasHeader('X-Custom'));
    }

    // ========================================
    // Body (PSR-7 MessageInterface)
    // ========================================

    public function testGetBody(): void
    {
        $msg = new Message('text/plain', 'Hello');
        $body = $msg->getBody();

        $this->assertInstanceOf(\Psr\Http\Message\StreamInterface::class, $body);
    }

    public function testWithBody(): void
    {
        $msg = new Message('text/plain', 'Hello');
        $newBody = Stream::cast('Goodbye');
        $modified = $msg->withBody($newBody);

        $this->assertSame('Hello', (string) $msg->getBody());
        $this->assertSame('Goodbye', (string) $modified->getBody());
    }

    // ========================================
    // Content-Type helpers
    // ========================================

    public function testGetContentType(): void
    {
        $msg = new Message('text/plain; charset=utf-8', 'Hello');
        $this->assertSame('text/plain; charset=utf-8', $msg->getContentType());
    }

    public function testWithContentType(): void
    {
        $msg = new Message('text/plain', 'Hello');
        $modified = $msg->withContentType('text/html');

        $this->assertSame('text/plain', $msg->getContentType());
        $this->assertSame('text/html', $modified->getContentType());
    }

    public function testWithContentTypeAndParams(): void
    {
        $msg = new Message('text/plain', 'Hello');
        $modified = $msg->withContentType('text/plain', ['charset' => 'utf-8']);

        $this->assertSame('text/plain; charset=utf-8', $modified->getContentType());
    }

    // ========================================
    // Protocol version
    // ========================================

    public function testGetProtocolVersion(): void
    {
        $msg = new Message('text/plain', 'Hello');
        $this->assertSame('1.0', $msg->getProtocolVersion());
    }

    public function testWithProtocolVersion(): void
    {
        $msg = new Message('text/plain', 'Hello');
        $modified = $msg->withProtocolVersion('1.1');

        $this->assertSame('1.0', $msg->getProtocolVersion());
        $this->assertSame('1.1', $modified->getProtocolVersion());
    }

    // ========================================
    // Immutability
    // ========================================

    public function testImmutable(): void
    {
        $msg = new Message('text/plain', 'Hello');
        $modified = $msg->withHeader('X-Test', 'value');

        $this->assertNotSame($msg, $modified);
    }

    // ========================================
    // Stream passthrough (no materialization)
    // ========================================

    public function testStreamPassthrough(): void
    {
        // Create a custom stream
        $customStream = Stream::cast('Custom content');

        // When passed a StreamInterface, Message should store it directly
        $msg = new Message('text/plain', $customStream);

        // The body should be the same stream instance (not copied)
        $this->assertSame($customStream, $msg->getBody());
    }
};

exit($test->run());
