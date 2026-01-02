<?php
/**
 * Test NamespacedCache PSR-16 implementation
 *
 * NamespacedCache is a decorator - tests focus on namespace isolation behavior.
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Test;
use mini\Cache\FilesystemCache;
use mini\Cache\NamespacedCache;

$test = new class extends Test {
    private FilesystemCache $rootCache;
    private NamespacedCache $cache;
    private string $testDir;

    protected function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/mini-cache-ns-test-' . uniqid();
        $this->rootCache = new FilesystemCache($this->testDir);
        $this->rootCache->clear();
        $this->cache = new NamespacedCache($this->rootCache, 'myns');
    }

    // === Basic operations with namespace ===

    public function testSetAndGetWithNamespace(): void
    {
        $this->cache->set('key', 'value');
        $this->assertSame('value', $this->cache->get('key'));
    }

    public function testNamespaceIsolation(): void
    {
        $ns1 = new NamespacedCache($this->rootCache, 'ns1');
        $ns2 = new NamespacedCache($this->rootCache, 'ns2');

        $ns1->set('key', 'value1');
        $ns2->set('key', 'value2');

        $this->assertSame('value1', $ns1->get('key'));
        $this->assertSame('value2', $ns2->get('key'));
    }

    public function testNamespacedKeyStoredWithPrefix(): void
    {
        $this->cache->set('mykey', 'myvalue');

        // The root cache should have the prefixed key
        $this->assertSame('myvalue', $this->rootCache->get('myns:mykey'));
        // But not the unprefixed key
        $this->assertNull($this->rootCache->get('mykey'));
    }

    public function testHasWithNamespace(): void
    {
        $this->cache->set('exists', 'value');
        $this->assertTrue($this->cache->has('exists'));
        $this->assertFalse($this->cache->has('not-exists'));
    }

    public function testDeleteWithNamespace(): void
    {
        $this->cache->set('to-delete', 'value');
        $this->assertTrue($this->cache->delete('to-delete'));
        $this->assertFalse($this->cache->has('to-delete'));
    }

    // === Multiple operations ===

    public function testGetMultipleWithNamespace(): void
    {
        $this->cache->set('multi1', 'v1');
        $this->cache->set('multi2', 'v2');

        $result = $this->cache->getMultiple(['multi1', 'multi2', 'multi3']);

        $this->assertSame('v1', $result['multi1']);
        $this->assertSame('v2', $result['multi2']);
        $this->assertNull($result['multi3']);
    }

    public function testSetMultipleWithNamespace(): void
    {
        $this->cache->setMultiple(['batch1' => 'a', 'batch2' => 'b']);

        $this->assertSame('a', $this->cache->get('batch1'));
        $this->assertSame('b', $this->cache->get('batch2'));

        // Verify stored with namespace prefix in root
        $this->assertSame('a', $this->rootCache->get('myns:batch1'));
    }

    public function testDeleteMultipleWithNamespace(): void
    {
        $this->cache->set('del1', 'v1');
        $this->cache->set('del2', 'v2');
        $this->cache->set('keep', 'v3');

        $this->cache->deleteMultiple(['del1', 'del2']);

        $this->assertFalse($this->cache->has('del1'));
        $this->assertFalse($this->cache->has('del2'));
        $this->assertTrue($this->cache->has('keep'));
    }

    // === Clear behavior ===

    public function testClearThrowsLogicException(): void
    {
        // NamespacedCache cannot implement clear() without scanning all keys
        $this->assertThrows(
            fn() => $this->cache->clear(),
            \LogicException::class
        );
    }

    // === Accessors ===

    public function testGetNamespace(): void
    {
        $this->assertSame('myns', $this->cache->getNamespace());
    }

    public function testGetUnderlyingCache(): void
    {
        $this->assertSame($this->rootCache, $this->cache->getUnderlyingCache());
    }

    // === Custom separator ===

    public function testCustomSeparator(): void
    {
        $cache = new NamespacedCache($this->rootCache, 'custom', '.');
        $cache->set('key', 'value');

        $this->assertSame('value', $this->rootCache->get('custom.key'));
    }

    // === TTL passthrough ===

    public function testTtlPassthrough(): void
    {
        $this->cache->set('ttl-key', 'value', 3600);
        $this->assertSame('value', $this->cache->get('ttl-key'));
    }

    public function testExpiredItemWithNamespace(): void
    {
        $this->cache->set('expired', 'value', -1);
        $this->assertNull($this->cache->get('expired'));
    }
};

exit($test->run());
