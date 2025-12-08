<?php
/**
 * Test Mailbox class - RFC 5322 mailbox handling
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use mini\Test;
use mini\Mail\Mailbox;

$test = new class extends Test {

    // ========================================
    // Construction
    // ========================================

    public function testConstructWithEmailOnly(): void
    {
        $mailbox = new Mailbox('test@example.com');
        $this->assertSame('test@example.com', $mailbox->getAddrSpec());
        $this->assertNull($mailbox->getDisplayName());
    }

    public function testConstructWithDisplayName(): void
    {
        $mailbox = new Mailbox('test@example.com', 'Test User');
        $this->assertSame('test@example.com', $mailbox->getAddrSpec());
        $this->assertSame('Test User', $mailbox->getDisplayName());
    }

    public function testConstructTrimsWhitespace(): void
    {
        $mailbox = new Mailbox('  test@example.com  ', '  Test User  ');
        $this->assertSame('test@example.com', $mailbox->getAddrSpec());
        $this->assertSame('Test User', $mailbox->getDisplayName());
    }

    public function testConstructEmptyDisplayNameBecomesNull(): void
    {
        $mailbox = new Mailbox('test@example.com', '   ');
        $this->assertNull($mailbox->getDisplayName());
    }

    public function testConstructRejectsInvalidEmail(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Mailbox('not-an-email');
    }

    public function testConstructRejectsEmptyEmail(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Mailbox('');
    }

    // ========================================
    // fromString parsing
    // ========================================

    public function testFromStringSimpleEmail(): void
    {
        $mailbox = Mailbox::fromString('test@example.com');
        $this->assertSame('test@example.com', $mailbox->getAddrSpec());
        $this->assertNull($mailbox->getDisplayName());
    }

    public function testFromStringWithDisplayName(): void
    {
        $mailbox = Mailbox::fromString('Test User <test@example.com>');
        $this->assertSame('test@example.com', $mailbox->getAddrSpec());
        $this->assertSame('Test User', $mailbox->getDisplayName());
    }

    public function testFromStringWithQuotedDisplayName(): void
    {
        $mailbox = Mailbox::fromString('"Test User" <test@example.com>');
        $this->assertSame('test@example.com', $mailbox->getAddrSpec());
        $this->assertSame('Test User', $mailbox->getDisplayName());
    }

    public function testFromStringWithSpecialCharsInQuotedName(): void
    {
        $mailbox = Mailbox::fromString('"User, Test" <test@example.com>');
        $this->assertSame('test@example.com', $mailbox->getAddrSpec());
        $this->assertSame('User, Test', $mailbox->getDisplayName());
    }

    public function testFromStringWithUnicode(): void
    {
        $mailbox = Mailbox::fromString('Frode Børli <frode@ennerd.com>');
        $this->assertSame('frode@ennerd.com', $mailbox->getAddrSpec());
        $this->assertSame('Frode Børli', $mailbox->getDisplayName());
    }

    public function testFromStringRejectsEmptyString(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Mailbox::fromString('');
    }

    // ========================================
    // Immutable modification
    // ========================================

    public function testWithAddrSpec(): void
    {
        $original = new Mailbox('old@example.com', 'Test User');
        $modified = $original->withAddrSpec('new@example.com');

        $this->assertSame('old@example.com', $original->getAddrSpec());
        $this->assertSame('new@example.com', $modified->getAddrSpec());
        $this->assertSame('Test User', $modified->getDisplayName());
    }

    public function testWithDisplayName(): void
    {
        $original = new Mailbox('test@example.com', 'Old Name');
        $modified = $original->withDisplayName('New Name');

        $this->assertSame('Old Name', $original->getDisplayName());
        $this->assertSame('New Name', $modified->getDisplayName());
    }

    public function testWithDisplayNameNull(): void
    {
        $original = new Mailbox('test@example.com', 'Test User');
        $modified = $original->withDisplayName(null);

        $this->assertSame('Test User', $original->getDisplayName());
        $this->assertNull($modified->getDisplayName());
    }

    // ========================================
    // String representation
    // ========================================

    public function testToStringEmailOnly(): void
    {
        $mailbox = new Mailbox('test@example.com');
        $this->assertSame('test@example.com', (string) $mailbox);
    }

    public function testToStringWithDisplayName(): void
    {
        $mailbox = new Mailbox('test@example.com', 'Test User');
        $this->assertSame('Test User <test@example.com>', (string) $mailbox);
    }

    public function testToStringQuotesSpecialChars(): void
    {
        $mailbox = new Mailbox('test@example.com', 'User, Test');
        $this->assertSame('"User, Test" <test@example.com>', (string) $mailbox);
    }

    public function testToStringEscapesQuotesInName(): void
    {
        $mailbox = new Mailbox('test@example.com', 'Test "Quoted" User');
        $str = (string) $mailbox;
        $this->assertStringContainsString('\"Quoted\"', $str);
    }

    // ========================================
    // Round-trip parsing
    // ========================================

    public function testRoundTripSimple(): void
    {
        $original = new Mailbox('test@example.com', 'Test User');
        $parsed = Mailbox::fromString((string) $original);

        $this->assertSame($original->getAddrSpec(), $parsed->getAddrSpec());
        $this->assertSame($original->getDisplayName(), $parsed->getDisplayName());
    }

    public function testRoundTripWithSpecialChars(): void
    {
        $original = new Mailbox('test@example.com', 'User, Test (VIP)');
        $parsed = Mailbox::fromString((string) $original);

        $this->assertSame($original->getAddrSpec(), $parsed->getAddrSpec());
        $this->assertSame($original->getDisplayName(), $parsed->getDisplayName());
    }
};

exit($test->run());
