<?php
/**
 * Test CacheControlHeader utility class
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Test;
use mini\Util\CacheControlHeader;

$test = new class extends Test {

    // ─────────────────────────────────────────────────────────────────────────
    // Parsing
    // ─────────────────────────────────────────────────────────────────────────

    public function testHandlesNullEmptyHeader(): void
    {
        $cc = new CacheControlHeader(null);
        $this->assertTrue($cc->isEmpty(), 'Null header should be empty');
        $this->assertSame('', (string) $cc, 'Empty header should render as empty string');
    }

    public function testParsesFlagDirectives(): void
    {
        $cc = new CacheControlHeader('no-cache, no-store, must-revalidate');
        $this->assertTrue($cc->has('no-cache'));
        $this->assertTrue($cc->has('no-store'));
        $this->assertTrue($cc->has('must-revalidate'));
        $this->assertFalse($cc->has('public'));
        $this->assertSame(true, $cc->get('no-cache'), 'Flag directives should return true');
    }

    public function testParsesValueDirectives(): void
    {
        $cc = new CacheControlHeader('public, max-age=3600, s-maxage=60');
        $this->assertTrue($cc->has('public'));
        $this->assertTrue($cc->has('max-age'));
        $this->assertSame('3600', $cc->get('max-age'));
        $this->assertSame('60', $cc->get('s-maxage'));
    }

    public function testCaseInsensitive(): void
    {
        $cc = new CacheControlHeader('Public, Max-Age=100');
        $this->assertTrue($cc->has('public'));
        $this->assertTrue($cc->has('PUBLIC'));
        $this->assertSame('100', $cc->get('MAX-AGE'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Modification
    // ─────────────────────────────────────────────────────────────────────────

    public function testWithIsImmutableAndAddsDirectives(): void
    {
        $cc = new CacheControlHeader('public');
        $cc2 = $cc->with('max-age', '3600');
        $this->assertFalse($cc->has('max-age'), 'Original should be unchanged');
        $this->assertTrue($cc2->has('max-age'), 'New instance should have directive');
        $this->assertSame('3600', $cc2->get('max-age'));
    }

    public function testWithWorksForFlagDirectives(): void
    {
        $cc = new CacheControlHeader();
        $cc = $cc->with('private');
        $this->assertTrue($cc->has('private'));
        $this->assertSame(true, $cc->get('private'));
    }

    public function testWithoutIsImmutableAndRemovesDirectives(): void
    {
        $cc = new CacheControlHeader('public, max-age=3600');
        $cc2 = $cc->without('max-age');
        $this->assertTrue($cc->has('max-age'), 'Original should be unchanged');
        $this->assertFalse($cc2->has('max-age'), 'New instance should not have directive');
        $this->assertTrue($cc2->has('public'), 'Other directives should remain');
    }

    public function testWithoutReturnsSameInstanceIfDirectiveNotPresent(): void
    {
        $cc = new CacheControlHeader('public');
        $cc2 = $cc->without('nonexistent');
        $this->assertTrue($cc === $cc2, 'Should return same instance if nothing to remove');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Visibility restriction
    // ─────────────────────────────────────────────────────────────────────────

    public function testWithRestrictedVisibilityChangesPublicToPrivate(): void
    {
        $cc = new CacheControlHeader('public, max-age=3600');
        $cc2 = $cc->withRestrictedVisibility('private');
        $this->assertFalse($cc2->has('public'), 'public should be removed');
        $this->assertTrue($cc2->has('private'), 'private should be added');
        $this->assertTrue($cc2->has('max-age'), 'Other directives should remain');
    }

    public function testWithRestrictedVisibilityCanMakeMoreRestrictive(): void
    {
        $cc = new CacheControlHeader('private');
        $cc2 = $cc->withRestrictedVisibility('no-cache');
        $this->assertFalse($cc2->has('private'));
        $this->assertTrue($cc2->has('no-cache'));
    }

    public function testWithRestrictedVisibilityDoesNotLoosenRestrictions(): void
    {
        $cc = new CacheControlHeader('no-store');
        $cc2 = $cc->withRestrictedVisibility('private');
        $this->assertTrue($cc2->has('no-store'), 'no-store should remain (more restrictive)');
        $this->assertFalse($cc2->has('private'), 'Should not add less restrictive');
    }

    public function testWithPrivateWorks(): void
    {
        $cc = new CacheControlHeader('public');
        $cc2 = $cc->withPrivate();
        $this->assertTrue($cc2->has('private'));
        $this->assertFalse($cc2->has('public'));
    }

    public function testWithNoStoreSetsFullNoCachePolicy(): void
    {
        $cc = new CacheControlHeader('public, max-age=3600');
        $cc2 = $cc->withNoStore();
        $this->assertTrue($cc2->has('no-store'));
        $this->assertTrue($cc2->has('no-cache'));
        $this->assertTrue($cc2->has('must-revalidate'));
        $this->assertFalse($cc2->has('public'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TTL restriction
    // ─────────────────────────────────────────────────────────────────────────

    public function testWithMaxTtlCapsHigherValues(): void
    {
        $cc = new CacheControlHeader('max-age=3600');
        $cc2 = $cc->withMaxTtl(60);
        $this->assertSame('60', $cc2->get('max-age'));
    }

    public function testWithMaxTtlKeepsLowerValues(): void
    {
        $cc = new CacheControlHeader('max-age=30');
        $cc2 = $cc->withMaxTtl(3600);
        $this->assertSame('30', $cc2->get('max-age'), 'Should keep lower value');
    }

    public function testWithMaxTtlSetsValueWhenNotPresent(): void
    {
        $cc = new CacheControlHeader('public');
        $cc2 = $cc->withMaxTtl(60);
        $this->assertSame('60', $cc2->get('max-age'));
    }

    public function testWithMaxSharedTtlWorks(): void
    {
        $cc = new CacheControlHeader('s-maxage=3600');
        $cc2 = $cc->withMaxSharedTtl(60);
        $this->assertSame('60', $cc2->get('s-maxage'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Rendering
    // ─────────────────────────────────────────────────────────────────────────

    public function testRendersCorrectly(): void
    {
        $cc = new CacheControlHeader();
        $cc = $cc->with('private')->with('max-age', '3600')->with('must-revalidate');
        $str = (string) $cc;
        $this->assertStringContainsString('private', $str);
        $this->assertStringContainsString('max-age=3600', $str);
        $this->assertStringContainsString('must-revalidate', $str);
    }

    public function testThrowsOnInvalidVisibility(): void
    {
        $cc = new CacheControlHeader();
        $this->assertThrows(
            fn() => $cc->withRestrictedVisibility('invalid'),
            \InvalidArgumentException::class,
            'Should throw on invalid visibility'
        );
    }
};

exit($test->run());
