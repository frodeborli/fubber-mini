<?php
/**
 * Test Session strict mode (session.use_strict_mode)
 *
 * Strict mode rejects unknown session IDs and creates new sessions.
 * This prevents session fixation attacks.
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Cache\TmpSqliteCache;
use mini\Session\Session;
use mini\Test;

// =============================================================================
// Helper to create session with mocked request cookies
// =============================================================================

class StrictModeTestSession extends Session
{
    private array $mockCookies = [];
    private bool $strict = false;

    public function setMockCookies(array $cookies): void
    {
        $this->mockCookies = $cookies;
    }

    public function setStrictMode(bool $strict): void
    {
        $this->strict = $strict;
    }

    protected function getCookies(): array
    {
        return $this->mockCookies;
    }

    // ini_set() refuses session directives once output has started, so the
    // policy — not the branch logic under test — is stubbed here.
    protected function isStrictMode(): bool
    {
        return $this->strict;
    }
}

$test = new class extends Test {

    private TmpSqliteCache $cache;
    private Session $session;
    /** ID of a session that really exists in $this->cache. */
    private string $knownId;
    private string $cacheKey;

    protected function setUp(): void
    {
        // Private in-memory store: strict mode is about what is (not) in the session
        // cache, so the test must own that cache rather than share the application one.
        $this->cache = new TmpSqliteCache(':memory:');
        $this->session = new Session($this->cache);

        // A session that is genuinely persisted — the set() is what saves it.
        $known = new Session($this->cache);
        $this->knownId = $known->getId();
        $known->set('test', 'value');
        $this->cacheKey = 'session.' . $this->knownId;
    }

    public function testIsStrictModeReadsIniSetting(): void
    {
        $ref = new ReflectionMethod($this->session, 'isStrictMode');
        $ref->setAccessible(true);

        $currentSetting = (bool) ini_get('session.use_strict_mode');
        $this->assertSame($currentSetting, $ref->invoke($this->session), 'isStrictMode() should match ini setting');
        $this->log('session.use_strict_mode is currently ' . ($currentSetting ? 'ON' : 'OFF'));
    }

    public function testSessionIdValidation(): void
    {
        $ref = new ReflectionMethod($this->session, 'isValidSessionId');
        $ref->setAccessible(true);

        // Valid session IDs (must be 22-256 chars, alphanumeric plus dash and comma)
        $this->assertTrue($ref->invoke($this->session, str_repeat('a', 64)), '64 hex chars should be valid');
        $this->assertTrue($ref->invoke($this->session, str_repeat('A', 32)), '32 uppercase hex chars should be valid');
        $this->assertTrue($ref->invoke($this->session, 'abc-def-123,456-abc-def'), '22+ chars with dashes and commas should be valid');

        // Invalid session IDs
        $this->assertFalse($ref->invoke($this->session, 'short'), 'Too short should be invalid');
        $this->assertFalse($ref->invoke($this->session, str_repeat('a', 300)), 'Too long should be invalid');
        $this->assertFalse($ref->invoke($this->session, 'has spaces'), 'Spaces should be invalid');
        $this->assertFalse($ref->invoke($this->session, 'has!special'), 'Special chars should be invalid');
        $this->assertFalse($ref->invoke($this->session, ''), 'Empty should be invalid');
    }

    public function testSessionsAreSavedToCache(): void
    {
        // Required for strict mode to have anything to check against.
        $this->assertTrue($this->cache->has($this->cacheKey), 'Session should be saved to cache after write');
    }

    public function testCacheHasCanValidateSessionExistence(): void
    {
        $this->assertTrue($this->cache->has($this->cacheKey), 'Cache has() should find existing session');
        $this->assertFalse($this->cache->has('session.nonexistent123456789012345678901234567890123456789012345678901234'), 'Cache has() should not find nonexistent session');
    }

    public function testSessionCacheKeysArePsr16Safe(): void
    {
        // PSR-16 reserves {}()/\@: in keys
        $this->assertFalse((bool) preg_match('/[{}()\/\\\\@:]/', $this->cacheKey), 'Session cache key must not use PSR-16 reserved characters');
    }

    public function testStrictModeAcceptsSessionIdsPresentInCache(): void
    {
        $known = new StrictModeTestSession($this->cache);
        $known->setStrictMode(true);
        $known->setMockCookies([session_name() => $this->knownId]);

        $this->assertSame($this->knownId, $known->getId(), 'Strict mode should accept a session ID that exists in cache');
        $this->assertSame('value', $known->get('test'), 'Accepted session should expose its stored data');
        $this->assertNull($known->getCookieToSet(), 'Accepted session should not issue a new cookie');
    }

    public function testStrictModeRejectsUnknownSessionIds(): void
    {
        // Session fixation protection.
        $unknownId = str_repeat('f', 64);
        $fixated = new StrictModeTestSession($this->cache);
        $fixated->setStrictMode(true);
        $fixated->setMockCookies([session_name() => $unknownId]);

        $newId = $fixated->getId();
        $this->assertTrue($newId !== $unknownId, 'Strict mode should reject a session ID not present in cache');
        $cookie = $fixated->getCookieToSet();
        $this->assertNotNull($cookie, 'Rejected session should issue a fresh cookie');
        $this->assertSame($newId, $cookie['value'], 'Fresh cookie should carry the newly generated ID');
    }

    public function testWithoutStrictModeWellFormedCookieIdsAreAdopted(): void
    {
        $unknownId = str_repeat('f', 64);
        $lax = new StrictModeTestSession($this->cache);
        $lax->setStrictMode(false);
        $lax->setMockCookies([session_name() => $unknownId]);

        $this->assertSame($unknownId, $lax->getId(), 'Without strict mode, a well-formed cookie ID is adopted');
    }

    public function testMalformedCookieIdsAreNeverAdopted(): void
    {
        // Rejected regardless of strict mode.
        $malformed = new StrictModeTestSession($this->cache);
        $malformed->setStrictMode(false);
        $malformed->setMockCookies([session_name() => 'not a valid id!']);

        $this->assertTrue($malformed->getId() !== 'not a valid id!', 'Malformed cookie ID must never be adopted');
    }
};

exit($test->run());
