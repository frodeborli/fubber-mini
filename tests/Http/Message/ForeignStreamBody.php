<?php
/**
 * A response or request body that is another library's PSR-7 stream is used as it is
 *
 * The invariant: `new Response($stream)` with any `StreamInterface` keeps that stream as the body. Stream::create()
 * (deprecated, used by the constructors) passed it through but declared `static` as its return type, so a stream
 * of another class (phasync's UnbufferedStream, for a body written while it is sent) was a TypeError.
 */

require __DIR__ . '/../../../ensure-autoloader.php';

use mini\Http\Message\Request;
use mini\Http\Message\Response;
use mini\Http\Message\Stream;
use mini\Test;
use Psr\Http\Message\StreamInterface;

$test = new class extends Test {

    private function foreign(): StreamInterface
    {
        return new class implements StreamInterface {
            public function __toString(): string { return 'streamed'; }
            public function close(): void {}
            public function detach() { return null; }
            public function getSize(): ?int { return null; }
            public function tell(): int { return 0; }
            public function eof(): bool { return false; }
            public function isSeekable(): bool { return false; }
            public function seek(int $offset, int $whence = SEEK_SET): void {}
            public function rewind(): void {}
            public function isWritable(): bool { return false; }
            public function write(string $string): int { return 0; }
            public function isReadable(): bool { return true; }
            public function read(int $length): string { return ''; }
            public function getContents(): string { return 'streamed'; }
            public function getMetadata(?string $key = null) { return null; }
        };
    }

    public function testAResponseKeepsAForeignStreamAsItsBody(): void
    {
        $body = $this->foreign();
        $this->assertSame($body, (new Response($body, ['Content-Type' => 'text/event-stream']))->getBody());
    }

    public function testARequestKeepsAForeignStreamAsItsBody(): void
    {
        $body = $this->foreign();
        $this->assertSame($body, (new Request('POST', '/x', $body))->getBody());
    }

    public function testCreateReturnsAForeignStreamAsItIs(): void
    {
        $body = $this->foreign();
        $this->assertSame($body, Stream::create($body));
    }
};

exit($test->run());
