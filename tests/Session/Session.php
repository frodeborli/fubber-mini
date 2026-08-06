<?php
/**
 * Test Session implementation
 *
 * Test methods run in declaration order and the first ten of them deliberately
 * share one `$this->session`: cookie consumption, regenerate() and destroy()
 * are lifecycle steps whose meaning depends on what happened before them.
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Cache\TmpSqliteCache;
use mini\Session\Session;
use mini\Test;

$test = new class extends Test {

    private TmpSqliteCache $cache;
    private Session $session;

    protected function setUp(): void
    {
        // Sessions are tested against a private in-memory store. Resolving the
        // application cache would make these tests depend on which driver the host
        // machine happens to pick (and, for the /tmp SQLite driver, on a file that may
        // be owned by another user) — session semantics must be verified deterministically.
        $this->cache = new TmpSqliteCache(':memory:');
        $this->session = new Session($this->cache);
    }

    public function testNewSessionGeneratesIdAndCookie(): void
    {
        $id = $this->session->getId();
        $this->assertTrue(strlen($id) === 64, 'Session ID should be 64 chars (hex of 32 bytes)');
        $this->assertTrue(ctype_xdigit($id), 'Session ID should be hexadecimal');

        $cookie = $this->session->getCookieToSet();
        $this->assertNotNull($cookie, 'New session should have cookie to set');
        $this->assertSame(session_name(), $cookie['name'], 'Cookie name should match session_name()');
        $this->assertSame($id, $cookie['value'], 'Cookie value should be session ID');
        $this->assertTrue($cookie['options']['httponly'], 'Cookie should be HttpOnly');
        $this->assertSame('Lax', $cookie['options']['samesite'], 'Cookie should have SameSite=Lax');
        $this->assertSame('/', $cookie['options']['path'], 'Cookie path should be /');
    }

    public function testGetCookieToSetClearsPendingCookie(): void
    {
        // The pending cookie was consumed by the previous test.
        $cookie2 = $this->session->getCookieToSet();
        $this->assertNull($cookie2, 'Second getCookieToSet() should return null');
    }

    public function testSessionSetGet(): void
    {
        $this->session->set('user_id', 123);
        $this->assertSame(123, $this->session->get('user_id'));
        $this->assertSame('default', $this->session->get('nonexistent', 'default'));
    }

    public function testSessionHasRemove(): void
    {
        $this->assertTrue($this->session->has('user_id'));
        $this->assertFalse($this->session->has('nonexistent'));
        $this->session->remove('user_id');
        $this->assertFalse($this->session->has('user_id'));
    }

    public function testSessionAllClear(): void
    {
        $this->session->set('a', 1);
        $this->session->set('b', 2);
        $all = $this->session->all();
        $this->assertSame(1, $all['a']);
        $this->assertSame(2, $all['b']);
        $this->session->clear();
        $this->assertSame([], $this->session->all());
    }

    public function testArrayAccessInterface(): void
    {
        $this->session['key1'] = 'value1';
        $this->assertSame('value1', $this->session['key1']);
        $this->assertTrue(isset($this->session['key1']));
        unset($this->session['key1']);
        $this->assertFalse(isset($this->session['key1']));
    }

    public function testCountableInterface(): void
    {
        $this->session->clear();
        $this->session['a'] = 1;
        $this->session['b'] = 2;
        $this->session['c'] = 3;
        $this->assertSame(3, count($this->session));
    }

    public function testIteratorAggregateInterface(): void
    {
        $keys = [];
        foreach ($this->session as $key => $value) {
            $keys[] = $key;
        }
        $this->assertSame(['a', 'b', 'c'], $keys);
    }

    public function testSessionRegenerate(): void
    {
        $oldId = $this->session->getId();
        $this->session->regenerate();
        $newId = $this->session->getId();
        $this->assertTrue($oldId !== $newId, 'regenerate() should create new ID');
        $cookie = $this->session->getCookieToSet();
        $this->assertNotNull($cookie, 'regenerate() should set new cookie');
        $this->assertSame($newId, $cookie['value'], 'New cookie should have new ID');
    }

    public function testSessionDataPreservedAfterRegenerate(): void
    {
        $this->assertSame(1, $this->session['a'], 'Data should be preserved after regenerate');
    }

    public function testSessionDestroySetsExpirationCookie(): void
    {
        $this->session->destroy();
        $cookie = $this->session->getCookieToSet();
        $this->assertNotNull($cookie, 'destroy() should set expiration cookie');
        $this->assertSame('', $cookie['value'], 'Expiration cookie should have empty value');
        $this->assertSame(1, $cookie['options']['expires'], 'Expiration cookie should expire in past');
    }

    public function testIsStarted(): void
    {
        $freshSession = new Session($this->cache);
        $this->assertFalse($freshSession->isStarted(), 'Fresh session should not be started');
        $freshSession->get('anything');
        $this->assertTrue($freshSession->isStarted(), 'Session should be started after access');
    }

    public function testSessionTtlUsesSessionCacheExpire(): void
    {
        $expectedTtl = session_cache_expire() * 60;
        $session2 = new Session($this->cache);
        $session2->getId(); // Trigger cookie creation
        $cookie = $session2->getCookieToSet();
        $actualExpires = $cookie['options']['expires'];
        $now = time();
        $actualTtl = $actualExpires - $now;
        // Allow 2 second tolerance for test execution time
        $this->assertTrue(abs($actualTtl - $expectedTtl) < 2, "Cookie TTL should match session_cache_expire()");
    }

    public function testSessionIdValidation(): void
    {
        $ref = new ReflectionMethod(Session::class, 'isValidSessionId');
        $ref->setAccessible(true);
        $testSession = new Session($this->cache);

        $this->assertTrue($ref->invoke($testSession, 'abc123def456abc123def456abc123def456abc123def456abc123def456abcd1234'), 'Valid 64-char hex should pass');
        $this->assertTrue($ref->invoke($testSession, 'ABCDEF1234567890abcdef'), 'Mixed case alphanumeric should pass');
        $this->assertTrue($ref->invoke($testSession, 'session-id-with-dashes'), 'Dashes should be allowed');
        $this->assertTrue($ref->invoke($testSession, 'session,id,with,commas'), 'Commas should be allowed');
        $this->assertFalse($ref->invoke($testSession, 'short'), 'Too short should fail');
        $this->assertFalse($ref->invoke($testSession, 'invalid!chars'), 'Special chars should fail');
        $this->assertFalse($ref->invoke($testSession, ''), 'Empty should fail');
    }
};

exit($test->run());
