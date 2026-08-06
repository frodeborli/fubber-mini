<?php
/**
 * Test Session behaviour when the cache backend reports a failed write or delete.
 *
 * Two distinct contracts are covered:
 *
 * 1. A refused *write* is always fatal. The next read reloads from cache, so
 *    swallowing it would discard the data the caller just wrote.
 * 2. A falsy *delete* is only fatal when the data actually survived. PSR-16 does
 *    not distinguish "nothing to delete" from "delete refused", and
 *    `apcu_delete()` — hence `mini\Cache\ApcuCache`, the driver Mini's default
 *    config prefers first — returns false for a key that is merely absent.
 *    Destroying an unpersisted session, logging out twice, or logging out after
 *    eviction must not raise a 500.
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Session\Session;
use mini\Test;
use Psr\SimpleCache\CacheInterface;

// =============================================================================
// Stubs
// =============================================================================

/** In-memory PSR-16 store whose set()/delete() return values are steerable. */
class StubCache implements CacheInterface
{
    public array $store = [];

    /** When false, set() refuses the write and reports failure. */
    public bool $writable = true;

    /**
     * When true, delete() reports failure but leaves the value in place
     * (a genuinely refused delete — session data stays readable).
     */
    public bool $deleteRefusedAndKept = false;

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->store[$key] ?? $default;
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        if (!$this->writable) {
            return false;
        }
        $this->store[$key] = $value;
        return true;
    }

    /** Mirrors ApcuCache::delete() exactly: apcu_delete() is false for absent keys. */
    public function delete(string $key): bool
    {
        if ($this->deleteRefusedAndKept) {
            return false;
        }
        if (!array_key_exists($key, $this->store)) {
            return false; // absent, not refused
        }
        unset($this->store[$key]);
        return true;
    }

    public function clear(): bool
    {
        $this->store = [];
        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->get($key, $default);
        }
        return $out;
    }

    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        $ok = true;
        foreach ($values as $key => $value) {
            $ok = $this->set((string) $key, $value, $ttl) && $ok;
        }
        return $ok;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $ok = true;
        foreach ($keys as $key) {
            $ok = $this->delete((string) $key) && $ok;
        }
        return $ok;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->store);
    }
}

$test = new class extends Test {

    /** The cache `new Session()` (no argument) must find in the container. */
    private StubCache $containerCache;

    protected function setUp(): void
    {
        // Registered before bootstrap so the container-default constructor test
        // exercises the exact path config/mini/Session/SessionInterface.php ships.
        $this->containerCache = new StubCache();
        \mini\Mini::$mini->set(CacheInterface::class, $this->containerCache);
    }

    // =========================================================================
    // A refused write must throw, never silently drop data
    // =========================================================================

    public function testRefusedWriteThrowsAndLeavesNoTrace(): void
    {
        $cache = new StubCache();
        $cache->writable = false;

        $session = new Session($cache);
        $this->assertThrows(
            fn() => $session->set('user_id', 123),
            RuntimeException::class,
            'set() must throw when the cache refuses the write'
        );

        // The throw must also leave no trace in memory. Session keeps an in-memory copy
        // for READ_CACHE_TTL (100ms); if the refused write stayed in it, a handler that
        // catches the RuntimeException — or any code running later in the same request —
        // would read back a session that was never stored and could authorize on it.
        $this->assertSame(null, $session->get('user_id'), 'A refused write must not be readable afterwards');
        $this->assertSame([], $session->all(), 'all() must not expose data that was never stored');
        $this->assertFalse($session->has('user_id'), 'has() must not report a refused write');
        $this->assertSame(0, count($session), 'count() must not include a refused write');

        $this->assertThrows(
            fn() => $session->clear(),
            RuntimeException::class,
            'clear() must throw when the cache refuses the write'
        );
        $this->assertThrows(
            fn() => $session->remove('user_id'),
            RuntimeException::class,
            'remove() must throw when the cache refuses the write'
        );
        $this->assertThrows(
            fn() => $session->regenerate(),
            RuntimeException::class,
            'regenerate() must throw when the new session cannot be stored'
        );

        // A refused write left nothing behind
        $this->assertSame([], $cache->store, 'Nothing should have been stored by a refused write');
    }

    public function testWritesSucceedOnceTheBackendAcceptsThem(): void
    {
        $cache = new StubCache();
        $ok = new Session($cache);
        $ok->set('user_id', 123);

        $this->assertSame(123, $ok->get('user_id'), 'Accepted write should be readable');
        $this->assertTrue($cache->has('session.' . $ok->getId()), 'Accepted write should reach the cache');
    }

    public function testRefusedWriteFallsBackToLastPersistedState(): void
    {
        $cache = new StubCache();
        $ok = new Session($cache);
        $ok->set('user_id', 123);

        // After a refused write the session must fall back to what the store actually
        // holds — not to the rejected value, and not to nothing.
        $cache->writable = false;
        $this->assertThrows(
            fn() => $ok->set('user_id', 999),
            RuntimeException::class,
            'set() must throw once the backend stops accepting writes'
        );
        $this->assertSame(123, $ok->get('user_id'), 'A refused write must leave the last persisted value visible');
        $this->assertSame(['user_id' => 123], $ok->all(), 'all() must reflect the store, not the refused write');
        $cache->writable = true;
    }

    // =========================================================================
    // A falsy delete is NOT an error when the key is simply absent
    // (this is exactly what ApcuCache::delete() returns for a missing key)
    // =========================================================================

    public function testDestroyOnNeverPersistedSessionDoesNotThrow(): void
    {
        $never = new Session(new StubCache());
        $this->assertTrue($never->destroy(), 'destroy() on a never-persisted session must succeed');
    }

    public function testDestroyIsIdempotent(): void
    {
        // Double logout / session evicted by the store between requests.
        $cache = new StubCache();
        $twice = new Session($cache);
        $twice->set('user_id', 7);
        $key = 'session.' . $twice->getId();

        $this->assertTrue($cache->has($key), 'Session should be in cache before destroy()');
        $this->assertTrue($twice->destroy(), 'First destroy() should succeed');
        $this->assertFalse($cache->has($key), 'destroy() should remove the session data');
        $this->assertTrue($twice->destroy(), 'Second destroy() (double logout) must not throw');
    }

    public function testRegenerateToleratesAnUnpersistedOldSession(): void
    {
        // regenerate(deleteOldSession: true) when the old session was never stored
        $cache = new StubCache();
        $regen = new Session($cache);
        $oldId = $regen->getId();

        $this->assertFalse($cache->has('session.' . $oldId), 'Old session was never persisted');
        $this->assertTrue($regen->regenerate(deleteOldSession: true), 'regenerate() must not throw on an unpersisted old session');
        $this->assertTrue($regen->getId() !== $oldId, 'regenerate() should produce a new ID');
    }

    // =========================================================================
    // A delete that genuinely leaves the data readable must still throw
    // =========================================================================

    public function testDestroyThrowsWhenRefusedDeleteKeepsSessionReadable(): void
    {
        $hostile = new StubCache();
        $live = new Session($hostile);
        $live->set('user_id', 42);
        $liveKey = 'session.' . $live->getId();
        $this->assertTrue($hostile->has($liveKey), 'Session should be stored before the refused delete');

        $hostile->deleteRefusedAndKept = true;
        $this->assertThrows(
            fn() => $live->destroy(),
            RuntimeException::class,
            'destroy() must throw when the session data survives the delete'
        );
        $this->assertTrue($hostile->has($liveKey), 'The refused delete left the session readable — that is the failure');
    }

    public function testRegenerateThrowsWhenTheOldSessionSurvives(): void
    {
        $hostile2 = new StubCache();
        $live2 = new Session($hostile2);
        $live2->set('user_id', 42);
        $hostile2->deleteRefusedAndKept = true;

        $this->assertThrows(
            fn() => $live2->regenerate(deleteOldSession: true),
            RuntimeException::class,
            'regenerate(deleteOldSession: true) must throw when the old session survives'
        );
    }

    // =========================================================================
    // Container-default constructor: `new Session()` with no argument
    // This is the path config/mini/Session/SessionInterface.php ships in production.
    // =========================================================================

    public function testNewSessionResolvesItsCacheLazilyFromTheContainer(): void
    {
        $default = new Session();
        $default->set('from_container', 'yes');
        $defaultKey = 'session.' . $default->getId();

        $this->assertTrue($this->containerCache->has($defaultKey), 'new Session() must resolve CacheInterface from the container');
        $this->assertSame('yes', $default->get('from_container'), 'Container-backed session should read back its own write');
        $this->assertSame(['from_container' => 'yes'], $default->all(), 'all() should expose container-backed data');
        $this->assertTrue($default->destroy(), 'Container-backed session should destroy cleanly');
        $this->assertFalse($this->containerCache->has($defaultKey), 'destroy() should remove the container-backed session');
    }
};

exit($test->run());
