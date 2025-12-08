<?php
/**
 * Test MultipartMessage class - MIME multipart container
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use mini\Test;
use mini\Mail\Message;
use mini\Mail\MultipartMessage;
use mini\Mail\MultipartType;

$test = new class extends Test {

    // ========================================
    // Construction
    // ========================================

    public function testConstructEmpty(): void
    {
        $mp = new MultipartMessage();

        $this->assertCount(0, $mp);
        $this->assertSame('mixed', $mp->getMultipartType());
    }

    public function testConstructWithType(): void
    {
        $mp = new MultipartMessage(MultipartType::Alternative);
        $this->assertSame('alternative', $mp->getMultipartType());
    }

    public function testConstructWithParts(): void
    {
        $part1 = new Message('text/plain', 'Hello');
        $part2 = new Message('text/html', '<p>Hello</p>');

        $mp = new MultipartMessage(MultipartType::Alternative, $part1, $part2);

        $this->assertCount(2, $mp);
    }

    public function testContentTypeHeader(): void
    {
        $mp = new MultipartMessage(MultipartType::Mixed);
        $contentType = $mp->getHeaderLine('Content-Type');

        $this->assertStringContainsString('multipart/mixed', $contentType);
        $this->assertStringContainsString('boundary=', $contentType);
    }

    // ========================================
    // Parts API
    // ========================================

    public function testGetParts(): void
    {
        $part = new Message('text/plain', 'Hello');
        $mp = new MultipartMessage(MultipartType::Mixed, $part);

        $parts = $mp->getParts();
        $this->assertCount(1, $parts);
        $this->assertSame($part, $parts[0]);
    }

    public function testGetPart(): void
    {
        $part = new Message('text/plain', 'Hello');
        $mp = new MultipartMessage(MultipartType::Mixed, $part);

        $this->assertSame($part, $mp->getPart(0));
        $this->assertNull($mp->getPart(99));
    }

    public function testHasPart(): void
    {
        $part = new Message('text/plain', 'Hello');
        $mp = new MultipartMessage(MultipartType::Mixed, $part);

        $this->assertTrue($mp->hasPart(0));
        $this->assertFalse($mp->hasPart(1));
    }

    public function testWithPart(): void
    {
        $part1 = new Message('text/plain', 'Original');
        $part2 = new Message('text/plain', 'Replacement');

        $mp = new MultipartMessage(MultipartType::Mixed, $part1);
        $modified = $mp->withPart(0, $part2);

        $this->assertSame('Original', (string) $mp->getPart(0)->getBody());
        $this->assertSame('Replacement', (string) $modified->getPart(0)->getBody());
    }

    public function testWithPartOutOfBounds(): void
    {
        $mp = new MultipartMessage();
        $this->expectException(\OutOfBoundsException::class);
        $mp->withPart(0, new Message('text/plain', 'Hello'));
    }

    public function testWithAddedPart(): void
    {
        $part1 = new Message('text/plain', 'First');
        $part2 = new Message('text/plain', 'Second');

        $mp = new MultipartMessage(MultipartType::Mixed, $part1);
        $modified = $mp->withAddedPart($part2);

        $this->assertCount(1, $mp);
        $this->assertCount(2, $modified);
    }

    public function testWithoutPart(): void
    {
        $part1 = new Message('text/plain', 'First');
        $part2 = new Message('text/plain', 'Second');

        $mp = new MultipartMessage(MultipartType::Mixed, $part1, $part2);
        $modified = $mp->withoutPart(0);

        $this->assertCount(2, $mp);
        $this->assertCount(1, $modified);
        $this->assertSame('Second', (string) $modified->getPart(0)->getBody());
    }

    // ========================================
    // Filtering
    // ========================================

    public function testFindPart(): void
    {
        $text = new Message('text/plain', 'Hello');
        $html = new Message('text/html', '<p>Hello</p>');

        $mp = new MultipartMessage(MultipartType::Alternative, $text, $html);

        $found = $mp->findPart(fn($p) => str_starts_with($p->getContentType(), 'text/html'));
        $this->assertSame($html, $found);

        $notFound = $mp->findPart(fn($p) => str_starts_with($p->getContentType(), 'image/'));
        $this->assertNull($notFound);
    }

    public function testFindParts(): void
    {
        $text1 = new Message('text/plain', 'One');
        $text2 = new Message('text/plain', 'Two');
        $html = new Message('text/html', '<p>HTML</p>');

        $mp = new MultipartMessage(MultipartType::Mixed, $text1, $text2, $html);

        $found = $mp->findParts(fn($p) => str_starts_with($p->getContentType(), 'text/plain'));
        $this->assertCount(2, $found);
    }

    public function testWithParts(): void
    {
        $text = new Message('text/plain', 'Text');
        $html = new Message('text/html', 'HTML');
        $pdf = new Message('application/pdf', 'PDF');

        $mp = new MultipartMessage(MultipartType::Mixed, $text, $html, $pdf);
        $textOnly = $mp->withParts(fn($p) => str_starts_with($p->getContentType(), 'text/'));

        $this->assertCount(3, $mp);
        $this->assertCount(2, $textOnly);
    }

    public function testWithoutParts(): void
    {
        $text = new Message('text/plain', 'Text');
        $pdf = new Message('application/pdf', 'PDF');

        $mp = new MultipartMessage(MultipartType::Mixed, $text, $pdf);
        $noPdf = $mp->withoutParts(fn($p) => str_starts_with($p->getContentType(), 'application/'));

        $this->assertCount(2, $mp);
        $this->assertCount(1, $noPdf);
    }

    // ========================================
    // Multipart-specific
    // ========================================

    public function testGetBoundary(): void
    {
        $mp = new MultipartMessage();
        $boundary = $mp->getBoundary();

        $this->assertNotEmpty($boundary);
        $this->assertStringStartsWith('=_Part_', $boundary);
    }

    public function testWithBoundary(): void
    {
        $mp = new MultipartMessage();
        $modified = $mp->withBoundary('custom-boundary');

        $this->assertSame('custom-boundary', $modified->getBoundary());
        $this->assertStringContainsString('custom-boundary', $modified->getHeaderLine('Content-Type'));
    }

    public function testGetMultipartType(): void
    {
        $mp = new MultipartMessage(MultipartType::Related);
        $this->assertSame('related', $mp->getMultipartType());
    }

    public function testWithMultipartType(): void
    {
        $mp = new MultipartMessage(MultipartType::Mixed);
        $modified = $mp->withMultipartType(MultipartType::Alternative);

        $this->assertSame('mixed', $mp->getMultipartType());
        $this->assertSame('alternative', $modified->getMultipartType());
    }

    // ========================================
    // Countable & IteratorAggregate
    // ========================================

    public function testCount(): void
    {
        $mp = new MultipartMessage(
            MultipartType::Mixed,
            new Message('text/plain', 'One'),
            new Message('text/plain', 'Two')
        );

        $this->assertCount(2, $mp);
    }

    public function testIterable(): void
    {
        $part1 = new Message('text/plain', 'One');
        $part2 = new Message('text/plain', 'Two');

        $mp = new MultipartMessage(MultipartType::Mixed, $part1, $part2);

        $collected = [];
        foreach ($mp as $part) {
            $collected[] = $part;
        }

        $this->assertCount(2, $collected);
        $this->assertSame($part1, $collected[0]);
        $this->assertSame($part2, $collected[1]);
    }

    // ========================================
    // ArrayAccess
    // ========================================

    public function testArrayAccessGet(): void
    {
        $part = new Message('text/plain', 'Hello');
        $mp = new MultipartMessage(MultipartType::Mixed, $part);

        $this->assertSame($part, $mp[0]);
        $this->assertNull($mp[99]);
    }

    public function testArrayAccessExists(): void
    {
        $part = new Message('text/plain', 'Hello');
        $mp = new MultipartMessage(MultipartType::Mixed, $part);

        $this->assertTrue(isset($mp[0]));
        $this->assertFalse(isset($mp[1]));
    }

    public function testArrayAccessSetThrows(): void
    {
        $mp = new MultipartMessage();
        $this->expectException(\RuntimeException::class);
        $mp[0] = new Message('text/plain', 'Hello');
    }

    public function testArrayAccessUnsetThrows(): void
    {
        $part = new Message('text/plain', 'Hello');
        $mp = new MultipartMessage(MultipartType::Mixed, $part);

        $this->expectException(\RuntimeException::class);
        unset($mp[0]);
    }

    // ========================================
    // Body streaming
    // ========================================

    public function testGetBody(): void
    {
        $part = new Message('text/plain', 'Hello');
        $mp = new MultipartMessage(MultipartType::Mixed, $part);

        $body = (string) $mp->getBody();

        $this->assertStringContainsString('--' . $mp->getBoundary(), $body);
        $this->assertStringContainsString('Hello', $body);
        $this->assertStringContainsString('--' . $mp->getBoundary() . '--', $body);
    }

    public function testBodyContainsPartHeaders(): void
    {
        $part = new Message('text/plain', 'Hello');
        $mp = new MultipartMessage(MultipartType::Mixed, $part);

        $body = (string) $mp->getBody();

        $this->assertStringContainsString('Content-Type: text/plain', $body);
    }

    public function testNestedMultipart(): void
    {
        $text = new Message('text/plain', 'Plain');
        $html = new Message('text/html', '<p>HTML</p>');
        $alternative = new MultipartMessage(MultipartType::Alternative, $text, $html);

        $attachment = new Message('application/pdf', 'PDF content');
        $mixed = new MultipartMessage(MultipartType::Mixed, $alternative, $attachment);

        $body = (string) $mixed->getBody();

        // Should have both boundaries
        $this->assertStringContainsString($mixed->getBoundary(), $body);
        $this->assertStringContainsString($alternative->getBoundary(), $body);

        // Should have all content
        $this->assertStringContainsString('Plain', $body);
        $this->assertStringContainsString('HTML', $body);
        $this->assertStringContainsString('PDF content', $body);
    }

    // ========================================
    // PSR-7 MessageInterface
    // ========================================

    public function testWithHeader(): void
    {
        $mp = new MultipartMessage();
        $modified = $mp->withHeader('X-Custom', 'value');

        $this->assertFalse($mp->hasHeader('X-Custom'));
        $this->assertTrue($modified->hasHeader('X-Custom'));
    }

    public function testWithBodyThrows(): void
    {
        $mp = new MultipartMessage();
        $this->expectException(\RuntimeException::class);
        $mp->withBody(new \mini\Http\Message\Stream(fopen('php://temp', 'r+')));
    }
};

exit($test->run());
