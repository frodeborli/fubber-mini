<?php
/**
 * Abstract PSR-16 SimpleCache compliance test
 *
 * Extend this class and implement createCache() to test any CacheInterface implementation.
 *
 * Usage:
 *   $test = new class extends AbstractPsr16Test {
 *       protected function createCache(): CacheInterface {
 *           return new MyCache();
 *       }
 *   };
 *   exit($test->run());
 */

// Autoloader must be loaded by the test file before including this
use mini\Test;
use Psr\SimpleCache\CacheInterface;

abstract class AbstractPsr16Test extends Test
{
    protected CacheInterface $cache;

    /**
     * Create the cache instance to test
     */
    abstract protected function createCache(): CacheInterface;

    protected function setUp(): void
    {
        $this->cache = $this->createCache();
        $this->cache->clear();
    }

    // ========================================
    // Basic get/set operations
    // ========================================

    public function testGetReturnsDefaultWhenKeyNotFound(): void
    {
        $this->assertNull($this->cache->get('nonexistent'));
        $this->assertSame('custom-default', $this->cache->get('nonexistent', 'custom-default'));
    }

    public function testSetAndGetString(): void
    {
        $this->assertTrue($this->cache->set('string-key', 'string-value'));
        $this->assertSame('string-value', $this->cache->get('string-key'));
    }

    public function testSetAndGetInteger(): void
    {
        $this->cache->set('int-key', 42);
        $this->assertSame(42, $this->cache->get('int-key'));
    }

    public function testSetAndGetFloat(): void
    {
        $this->cache->set('float-key', 3.14159);
        $this->assertSame(3.14159, $this->cache->get('float-key'));
    }

    public function testSetAndGetBooleanTrue(): void
    {
        $this->cache->set('bool-true', true);
        $this->assertSame(true, $this->cache->get('bool-true'));
    }

    public function testSetAndGetBooleanFalse(): void
    {
        $this->cache->set('bool-false', false);
        $this->assertSame(false, $this->cache->get('bool-false'));
    }

    public function testSetAndGetNull(): void
    {
        $this->cache->set('null-key', null);
        // Must distinguish between "not found" and "stored null"
        $this->assertTrue($this->cache->has('null-key'));
        $this->assertNull($this->cache->get('null-key'));
        // When value is null, default should NOT be returned
        $this->assertNull($this->cache->get('null-key', 'should-not-return'));
    }

    public function testSetAndGetArray(): void
    {
        $data = ['foo' => 'bar', 'nested' => ['a' => 1, 'b' => 2]];
        $this->cache->set('array-key', $data);
        $this->assertSame($data, $this->cache->get('array-key'));
    }

    public function testSetAndGetObject(): void
    {
        $obj = new \stdClass();
        $obj->name = 'test';
        $obj->value = 123;
        $this->cache->set('object-key', $obj);
        $retrieved = $this->cache->get('object-key');
        $this->assertEquals($obj, $retrieved);
    }

    public function testSetOverwritesExistingValue(): void
    {
        $this->cache->set('overwrite-key', 'original');
        $this->cache->set('overwrite-key', 'updated');
        $this->assertSame('updated', $this->cache->get('overwrite-key'));
    }

    // ========================================
    // TTL (Time-To-Live) behavior
    // ========================================

    public function testSetWithIntegerTtl(): void
    {
        $this->cache->set('ttl-int', 'value', 3600);
        $this->assertSame('value', $this->cache->get('ttl-int'));
    }

    public function testSetWithDateIntervalTtl(): void
    {
        $ttl = new \DateInterval('PT1H'); // 1 hour
        $this->cache->set('ttl-interval', 'value', $ttl);
        $this->assertSame('value', $this->cache->get('ttl-interval'));
    }

    public function testExpiredItemReturnsDefault(): void
    {
        // TTL of 0 should expire immediately
        $this->cache->set('expired-zero', 'value', 0);
        $this->assertNull($this->cache->get('expired-zero'));
        $this->assertFalse($this->cache->has('expired-zero'));
    }

    public function testExpiredItemWithNegativeTtl(): void
    {
        $this->cache->set('expired-negative', 'value', -1);
        $this->assertNull($this->cache->get('expired-negative'));
    }

    // ========================================
    // has() method
    // ========================================

    public function testHasReturnsTrueForExistingKey(): void
    {
        $this->cache->set('has-exists', 'value');
        $this->assertTrue($this->cache->has('has-exists'));
    }

    public function testHasReturnsFalseForNonexistentKey(): void
    {
        $this->assertFalse($this->cache->has('has-not-exists'));
    }

    public function testHasReturnsTrueForStoredNull(): void
    {
        $this->cache->set('has-null', null);
        $this->assertTrue($this->cache->has('has-null'));
    }

    public function testHasReturnsFalseForExpiredKey(): void
    {
        $this->cache->set('has-expired', 'value', -1);
        $this->assertFalse($this->cache->has('has-expired'));
    }

    // ========================================
    // delete() method
    // ========================================

    public function testDeleteRemovesKey(): void
    {
        $this->cache->set('delete-me', 'value');
        $this->assertTrue($this->cache->delete('delete-me'));
        $this->assertFalse($this->cache->has('delete-me'));
    }

    public function testDeleteReturnsTrueForNonexistentKey(): void
    {
        // PSR-16: MUST return true if key doesn't exist
        $this->assertTrue($this->cache->delete('never-existed'));
    }

    // ========================================
    // clear() method
    // ========================================

    public function testClearRemovesAllKeys(): void
    {
        $this->cache->set('clear-key1', 'value1');
        $this->cache->set('clear-key2', 'value2');
        $this->cache->set('clear-key3', 'value3');

        $this->assertTrue($this->cache->clear());

        $this->assertFalse($this->cache->has('clear-key1'));
        $this->assertFalse($this->cache->has('clear-key2'));
        $this->assertFalse($this->cache->has('clear-key3'));
    }

    // ========================================
    // getMultiple() method
    // ========================================

    public function testGetMultipleReturnsAllRequestedKeys(): void
    {
        $this->cache->set('getmulti-1', 'value1');
        $this->cache->set('getmulti-2', 'value2');

        $result = $this->cache->getMultiple(['getmulti-1', 'getmulti-2', 'getmulti-missing']);

        $this->assertSame('value1', $result['getmulti-1']);
        $this->assertSame('value2', $result['getmulti-2']);
        $this->assertNull($result['getmulti-missing']);
    }

    public function testGetMultipleWithCustomDefault(): void
    {
        $this->cache->set('getmulti-exists', 'value');

        $result = $this->cache->getMultiple(['getmulti-exists', 'getmulti-not'], 'MISSING');

        $this->assertSame('value', $result['getmulti-exists']);
        $this->assertSame('MISSING', $result['getmulti-not']);
    }

    // ========================================
    // setMultiple() method
    // ========================================

    public function testSetMultipleSetsAllValues(): void
    {
        $values = [
            'setmulti-1' => 'value1',
            'setmulti-2' => 'value2',
            'setmulti-3' => 'value3',
        ];

        $this->assertTrue($this->cache->setMultiple($values));

        $this->assertSame('value1', $this->cache->get('setmulti-1'));
        $this->assertSame('value2', $this->cache->get('setmulti-2'));
        $this->assertSame('value3', $this->cache->get('setmulti-3'));
    }

    public function testSetMultipleWithTtl(): void
    {
        $values = ['setmulti-ttl1' => 'a', 'setmulti-ttl2' => 'b'];
        $this->cache->setMultiple($values, 3600);

        $this->assertTrue($this->cache->has('setmulti-ttl1'));
        $this->assertTrue($this->cache->has('setmulti-ttl2'));
    }

    // ========================================
    // deleteMultiple() method
    // ========================================

    public function testDeleteMultipleRemovesAllKeys(): void
    {
        $this->cache->set('delmulti-1', 'v1');
        $this->cache->set('delmulti-2', 'v2');
        $this->cache->set('delmulti-keep', 'v3');

        $this->assertTrue($this->cache->deleteMultiple(['delmulti-1', 'delmulti-2']));

        $this->assertFalse($this->cache->has('delmulti-1'));
        $this->assertFalse($this->cache->has('delmulti-2'));
        $this->assertTrue($this->cache->has('delmulti-keep'));
    }

    // ========================================
    // Key validation (PSR-16 compliance)
    // ========================================

    public function testEmptyKeyThrowsException(): void
    {
        $this->assertThrows(
            fn() => $this->cache->get(''),
            \InvalidArgumentException::class
        );
    }

    public function testInvalidKeyWithCurlyBraces(): void
    {
        $this->assertThrows(fn() => $this->cache->get('key{'), \InvalidArgumentException::class);
        $this->assertThrows(fn() => $this->cache->get('key}'), \InvalidArgumentException::class);
    }

    public function testInvalidKeyWithParentheses(): void
    {
        $this->assertThrows(fn() => $this->cache->get('key('), \InvalidArgumentException::class);
        $this->assertThrows(fn() => $this->cache->get('key)'), \InvalidArgumentException::class);
    }

    public function testInvalidKeyWithSlash(): void
    {
        $this->assertThrows(fn() => $this->cache->get('key/value'), \InvalidArgumentException::class);
    }

    public function testInvalidKeyWithBackslash(): void
    {
        $this->assertThrows(fn() => $this->cache->get('key\\value'), \InvalidArgumentException::class);
    }

    public function testInvalidKeyWithAtSign(): void
    {
        $this->assertThrows(fn() => $this->cache->get('key@value'), \InvalidArgumentException::class);
    }

    // Valid keys that should NOT throw
    public function testValidKeyWithColon(): void
    {
        // PSR-16 allows colons
        $this->cache->set('namespace:key', 'value');
        $this->assertSame('value', $this->cache->get('namespace:key'));
    }

    public function testValidKeyWithDot(): void
    {
        $this->cache->set('key.with.dots', 'value');
        $this->assertSame('value', $this->cache->get('key.with.dots'));
    }

    public function testValidKeyWithDash(): void
    {
        $this->cache->set('key-with-dashes', 'value');
        $this->assertSame('value', $this->cache->get('key-with-dashes'));
    }

    public function testValidKeyWithUnderscore(): void
    {
        $this->cache->set('key_with_underscores', 'value');
        $this->assertSame('value', $this->cache->get('key_with_underscores'));
    }
}
