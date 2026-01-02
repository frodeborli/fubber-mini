<?php
/**
 * Test Base64Stream class - streaming base64 encoder
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Test;
use mini\Mail\Base64Stream;
use mini\Http\Message\Stream;

$test = new class extends Test {

    // ========================================
    // Basic encoding
    // ========================================

    public function testEncodesContent(): void
    {
        $source = Stream::cast('Hello World');
        $stream = new Base64Stream($source);

        $encoded = (string) $stream;

        // Should be valid base64
        $decoded = base64_decode(str_replace(["\r", "\n"], '', $encoded));
        $this->assertSame('Hello World', $decoded);
    }

    public function testEncodesEmptyContent(): void
    {
        $source = Stream::cast('');
        $stream = new Base64Stream($source);

        $encoded = (string) $stream;
        $this->assertSame('', trim($encoded));
    }

    public function testEncodesBinaryContent(): void
    {
        $binary = random_bytes(100);
        $source = Stream::cast($binary);
        $stream = new Base64Stream($source);

        $encoded = (string) $stream;
        $decoded = base64_decode(str_replace(["\r", "\n"], '', $encoded));
        $this->assertSame($binary, $decoded);
    }

    // ========================================
    // Line wrapping
    // ========================================

    public function testLineWrappingAt76Chars(): void
    {
        // Create content that will produce more than 76 chars base64
        $source = Stream::cast(str_repeat('A', 100));
        $stream = new Base64Stream($source);

        $encoded = (string) $stream;
        $lines = explode("\r\n", trim($encoded));

        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(76, strlen($line), "Line exceeds 76 chars: $line");
        }
    }

    public function testUsesCRLFLineEndings(): void
    {
        $source = Stream::cast(str_repeat('A', 100));
        $stream = new Base64Stream($source);

        $encoded = (string) $stream;
        $this->assertStringContainsString("\r\n", $encoded);
        $this->assertStringNotContainsString("\n\n", $encoded); // No bare LF
    }

    // ========================================
    // Streaming behavior
    // ========================================

    public function testReadInChunks(): void
    {
        $source = Stream::cast(str_repeat('A', 1000));
        $stream = new Base64Stream($source);

        $chunks = [];
        while (!$stream->eof()) {
            $chunk = $stream->read(50);
            if ($chunk !== '') {
                $chunks[] = $chunk;
            }
        }

        $this->assertGreaterThan(1, count($chunks));

        // Reassembled should be valid
        $encoded = implode('', $chunks);
        $decoded = base64_decode(str_replace(["\r", "\n"], '', $encoded));
        $this->assertSame(str_repeat('A', 1000), $decoded);
    }

    public function testEof(): void
    {
        $source = Stream::cast('Hello');
        $stream = new Base64Stream($source);

        $this->assertFalse($stream->eof());
        $stream->getContents();
        $this->assertTrue($stream->eof());
    }

    public function testRewind(): void
    {
        $source = Stream::cast('Hello');
        $stream = new Base64Stream($source);

        $first = (string) $stream;
        $stream->rewind();
        $second = (string) $stream;

        $this->assertSame($first, $second);
    }

    // ========================================
    // StreamInterface compliance
    // ========================================

    public function testIsReadable(): void
    {
        $source = Stream::cast('Hello');
        $stream = new Base64Stream($source);

        $this->assertTrue($stream->isReadable());
    }

    public function testIsNotWritable(): void
    {
        $source = Stream::cast('Hello');
        $stream = new Base64Stream($source);

        $this->assertFalse($stream->isWritable());
    }

    public function testIsNotSeekable(): void
    {
        $source = Stream::cast('Hello');
        $stream = new Base64Stream($source);

        $this->assertFalse($stream->isSeekable());
    }

    public function testWriteThrows(): void
    {
        $source = Stream::cast('Hello');
        $stream = new Base64Stream($source);

        $this->expectException(\RuntimeException::class);
        $stream->write('data');
    }

    public function testSeekThrows(): void
    {
        $source = Stream::cast('Hello');
        $stream = new Base64Stream($source);

        $this->expectException(\RuntimeException::class);
        $stream->seek(0);
    }

    public function testTellThrows(): void
    {
        $source = Stream::cast('Hello');
        $stream = new Base64Stream($source);

        $this->expectException(\RuntimeException::class);
        $stream->tell();
    }

    public function testGetSizeReturnsNull(): void
    {
        $source = Stream::cast('Hello');
        $stream = new Base64Stream($source);

        $this->assertNull($stream->getSize());
    }

    public function testGetMetadata(): void
    {
        $source = Stream::cast('Hello');
        $stream = new Base64Stream($source);

        $meta = $stream->getMetadata();
        $this->assertIsArray($meta);
        $this->assertFalse($meta['seekable']);
    }

    public function testClose(): void
    {
        $source = Stream::cast('Hello');
        $stream = new Base64Stream($source);

        $stream->close();
        $this->assertTrue($stream->eof());
    }

    public function testDetach(): void
    {
        $source = Stream::cast('Hello');
        $stream = new Base64Stream($source);

        $result = $stream->detach();
        $this->assertNull($result);
        $this->assertTrue($stream->eof());
    }

    public function testReadAfterDetachThrows(): void
    {
        $source = Stream::cast('Hello');
        $stream = new Base64Stream($source);

        $stream->detach();

        $this->expectException(\RuntimeException::class);
        $stream->read(10);
    }

    // ========================================
    // Edge cases
    // ========================================

    public function testHandlesChunkBoundaries(): void
    {
        // Test various sizes to ensure chunk boundary handling is correct
        $sizes = [1, 2, 3, 56, 57, 58, 570, 571, 572];

        foreach ($sizes as $size) {
            $data = str_repeat('X', $size);
            $source = Stream::cast($data);
            $stream = new Base64Stream($source);

            $encoded = (string) $stream;
            $decoded = base64_decode(str_replace(["\r", "\n"], '', $encoded));

            $this->assertSame($data, $decoded, "Failed for size $size");
        }
    }
};

exit($test->run());
