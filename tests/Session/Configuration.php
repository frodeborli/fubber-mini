<?php
/**
 * Test Session configuration via PHP session functions
 *
 * Tests that Session respects session_name() and session_cache_expire().
 * These sessions deliberately use the container-provided cache — that is the
 * production wiring whose configuration is under test here.
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Mini;
use mini\Session\Session;
use mini\Test;
use Psr\SimpleCache\CacheInterface;

$test = new class extends Test {

    protected function setUp(): void
    {
        // Clear any existing session data in cache
        Mini::$mini->get(CacheInterface::class)->clear();
    }

    public function testCookieNameMatchesSessionName(): void
    {
        $configuredName = session_name(); // Whatever php.ini says
        $session = new Session();
        $session->getId();
        $cookie = $session->getCookieToSet();

        $this->assertSame($configuredName, $cookie['name'], "Cookie name should match session_name()");
        $this->log("Cookie name: $configuredName");
    }

    public function testCookieTtlMatchesSessionCacheExpire(): void
    {
        $configuredExpireMinutes = session_cache_expire(); // Whatever php.ini says
        $expectedTtlSeconds = $configuredExpireMinutes * 60;
        $session2 = new Session();
        $session2->getId();
        $cookie = $session2->getCookieToSet();
        $actualTtl = $cookie['options']['expires'] - time();

        $this->assertTrue(
            abs($actualTtl - $expectedTtlSeconds) < 2,
            "TTL should match session_cache_expire()"
        );
        $this->log("Cookie TTL: {$configuredExpireMinutes} minutes");
    }

    public function testPhpSessionFunctionsAvailableInCli(): void
    {
        // Verifies these functions work without web SAPI
        $this->assertTrue(is_string(session_name()), 'session_name() should return string in CLI');
        $this->assertTrue(is_int(session_cache_expire()), 'session_cache_expire() should return int in CLI');
    }

    public function testSessionIdFormatIsValid(): void
    {
        $session3 = new Session();
        $id = $session3->getId();
        $this->assertTrue(preg_match('/^[a-f0-9]{64}$/', $id) === 1, 'Session ID should be 64 lowercase hex chars');
    }

    public function testDifferentSessionsGetDifferentIds(): void
    {
        $session4 = new Session();
        $session5 = new Session();
        $this->assertTrue($session4->getId() !== $session5->getId(), 'Different sessions should have different IDs');
    }

    public function testGetCacheTtlConvertsMinutesToSeconds(): void
    {
        $ref = new ReflectionMethod(Session::class, 'getCacheTtl');
        $ref->setAccessible(true);
        $session6 = new Session();
        $ttl = $ref->invoke($session6);

        $this->assertSame(session_cache_expire() * 60, $ttl, 'getCacheTtl() should return minutes * 60');
    }

    public function testGetCookieOptionsAppliesSecureDefaults(): void
    {
        // getCookieOptions() uses session_get_cookie_params() with secure defaults
        $ref = new ReflectionMethod(Session::class, 'getCookieOptions');
        $ref->setAccessible(true);
        $session7 = new Session();
        $options = $ref->invoke($session7);
        $params = session_get_cookie_params();

        // Path should match or default to /
        $this->assertSame($params['path'] ?: '/', $options['path'], 'Cookie path should match session config');
        // HttpOnly should be true (our secure default when php.ini is empty)
        $this->assertTrue($options['httponly'], 'Cookie httponly should default to true');
        // SameSite should be Lax (our secure default when php.ini is empty)
        $this->assertSame('Lax', $options['samesite'], 'Cookie samesite should default to Lax');
    }

    public function testIsStrictModeReadsFromIni(): void
    {
        $ref = new ReflectionMethod(Session::class, 'isStrictMode');
        $ref->setAccessible(true);
        $session8 = new Session();
        $strictMode = $ref->invoke($session8);

        $this->assertSame((bool) ini_get('session.use_strict_mode'), $strictMode, 'isStrictMode() should match ini setting');
    }
};

exit($test->run());
