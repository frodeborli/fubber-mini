<?php
/**
 * Test QuotedPrintableStream class - streaming quoted-printable encoder
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Test;
use mini\Mail\QuotedPrintableStream;
use mini\Http\Message\Stream;

$test = new class extends Test {

    // ========================================
    // Basic encoding
    // ========================================

    public function testEncodesAsciiUnchanged(): void
    {
        $source = Stream::cast('Hello World');
        $stream = new QuotedPrintableStream($source);

        $encoded = (string) $stream;
        $this->assertStringContainsString('Hello World', $encoded);
    }

    public function testEncodesNonAscii(): void
    {
        $source = Stream::cast('æøå');
        $stream = new QuotedPrintableStream($source);

        $encoded = (string) $stream;

        // Should contain hex-encoded bytes
        $this->assertStringContainsString('=', $encoded);
        // Should decode back correctly
        $decoded = quoted_printable_decode($encoded);
        $this->assertSame('æøå', $decoded);
    }

    public function testEncodesEqualsSign(): void
    {
        $source = Stream::cast('a=b');
        $stream = new QuotedPrintableStream($source);

        $encoded = (string) $stream;
        $this->assertStringContainsString('=3D', $encoded);

        $decoded = quoted_printable_decode($encoded);
        $this->assertSame('a=b', $decoded);
    }

    public function testEncodesEmptyContent(): void
    {
        $source = Stream::cast('');
        $stream = new QuotedPrintableStream($source);

        $encoded = (string) $stream;
        $this->assertSame('', $encoded);
    }

    // ========================================
    // Line handling
    // ========================================

    public function testPreservesCRLF(): void
    {
        $source = Stream::cast("Line1\r\nLine2");
        $stream = new QuotedPrintableStream($source);

        $encoded = (string) $stream;
        $this->assertStringContainsString("\r\n", $encoded);

        $decoded = quoted_printable_decode($encoded);
        $this->assertSame("Line1\r\nLine2", $decoded);
    }

    public function testNormalizesLFtoCRLF(): void
    {
        $source = Stream::cast("Line1\nLine2");
        $stream = new QuotedPrintableStream($source);

        $encoded = (string) $stream;
        $this->assertStringContainsString("\r\n", $encoded);
    }

    public function testSoftLineBreaks(): void
    {
        // Long line should get soft breaks
        $source = Stream::cast(str_repeat('A', 100));
        $stream = new QuotedPrintableStream($source);

        $encoded = (string) $stream;

        // Should have soft line break (=\r\n)
        $this->assertStringContainsString("=\r\n", $encoded);

        // Lines should not exceed 76 chars
        $lines = explode("\r\n", $encoded);
        foreach ($lines as $line) {
            // Soft break lines end with =, so real line length is one less
            $effectiveLength = str_ends_with($line, '=') ? strlen($line) - 1 : strlen($line);
            $this->assertLessThanOrEqual(76, strlen($line), "Line too long: $line");
        }
    }

    // ========================================
    // Streaming behavior
    // ========================================

    public function testReadInChunks(): void
    {
        $source = Stream::cast(str_repeat('Hello ', 100));
        $stream = new QuotedPrintableStream($source);

        $chunks = [];
        while (!$stream->eof()) {
            $chunk = $stream->read(50);
            if ($chunk !== '') {
                $chunks[] = $chunk;
            }
        }

        $this->assertGreaterThan(1, count($chunks));

        // Reassembled should decode correctly
        $encoded = implode('', $chunks);
        $decoded = quoted_printable_decode($encoded);
        $this->assertSame(str_repeat('Hello ', 100), $decoded);
    }

    public function testEof(): void
    {
        $source = Stream::cast('Hello');
        $stream = new QuotedPrintableStream($source);

        $this->assertFalse($stream->eof());
        $stream->getContents();
        $this->assertTrue($stream->eof());
    }

    public function testRewind(): void
    {
        $source = Stream::cast('Hello');
        $stream = new QuotedPrintableStream($source);

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
        $stream = new QuotedPrintableStream($source);

        $this->assertTrue($stream->isReadable());
    }

    public function testIsNotWritable(): void
    {
        $source = Stream::cast('Hello');
        $stream = new QuotedPrintableStream($source);

        $this->assertFalse($stream->isWritable());
    }

    public function testIsNotSeekable(): void
    {
        $source = Stream::cast('Hello');
        $stream = new QuotedPrintableStream($source);

        $this->assertFalse($stream->isSeekable());
    }

    public function testWriteThrows(): void
    {
        $source = Stream::cast('Hello');
        $stream = new QuotedPrintableStream($source);

        $this->expectException(\RuntimeException::class);
        $stream->write('data');
    }

    public function testSeekThrows(): void
    {
        $source = Stream::cast('Hello');
        $stream = new QuotedPrintableStream($source);

        $this->expectException(\RuntimeException::class);
        $stream->seek(0);
    }

    public function testTellThrows(): void
    {
        $source = Stream::cast('Hello');
        $stream = new QuotedPrintableStream($source);

        $this->expectException(\RuntimeException::class);
        $stream->tell();
    }

    public function testGetSizeReturnsNull(): void
    {
        $source = Stream::cast('Hello');
        $stream = new QuotedPrintableStream($source);

        $this->assertNull($stream->getSize());
    }

    public function testClose(): void
    {
        $source = Stream::cast('Hello');
        $stream = new QuotedPrintableStream($source);

        $stream->close();
        $this->assertTrue($stream->eof());
    }

    public function testDetach(): void
    {
        $source = Stream::cast('Hello');
        $stream = new QuotedPrintableStream($source);

        $result = $stream->detach();
        $this->assertNull($result);
        $this->assertTrue($stream->eof());
    }

    // ========================================
    // Round-trip encoding
    // ========================================

    public function testRoundTripAscii(): void
    {
        $original = 'The quick brown fox jumps over the lazy dog.';
        $source = Stream::cast($original);
        $stream = new QuotedPrintableStream($source);

        $decoded = quoted_printable_decode((string) $stream);
        $this->assertSame($original, $decoded);
    }

    public function testRoundTripUnicode(): void
    {
        $original = 'Norwegian: æøå, German: äöü, French: éèê';
        $source = Stream::cast($original);
        $stream = new QuotedPrintableStream($source);

        $decoded = quoted_printable_decode((string) $stream);
        $this->assertSame($original, $decoded);
    }

    public function testRoundTripMixed(): void
    {
        $original = "Hello æøå!\r\nLine 2 with = sign\r\nLine 3";
        $source = Stream::cast($original);
        $stream = new QuotedPrintableStream($source);

        $decoded = quoted_printable_decode((string) $stream);
        $this->assertSame($original, $decoded);
    }
};

exit($test->run());
