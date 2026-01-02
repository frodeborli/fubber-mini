<?php
/**
 * Test DatabaseCache PSR-16 implementation
 */

require __DIR__ . '/../../ensure-autoloader.php';
require_once __DIR__ . '/_lib/AbstractPsr16Test.php';

use mini\Mini;
use mini\Cache\DatabaseCache;
use Psr\SimpleCache\CacheInterface;

$test = new class extends AbstractPsr16Test {

    protected function createCache(): CacheInterface
    {
        // DatabaseCache requires Mini to be bootstrapped for DatabaseInterface
        \mini\bootstrap();
        return new DatabaseCache();
    }

    // DatabaseCache-specific tests

    public function testCleanupRemovesExpiredEntries(): void
    {
        $this->cache->set('permanent', 'value');
        $this->cache->set('expired1', 'value', -1);
        $this->cache->set('expired2', 'value', -1);

        /** @var DatabaseCache $cache */
        $cache = $this->cache;
        $removed = $cache->cleanup();

        $this->assertSame(2, $removed);
        $this->assertTrue($this->cache->has('permanent'));
    }

    public function testGetStatsReturnsCorrectCounts(): void
    {
        $this->cache->clear();
        $this->cache->set('active1', 'value');
        $this->cache->set('active2', 'value');
        $this->cache->set('expired', 'value', -1);

        /** @var DatabaseCache $cache */
        $cache = $this->cache;
        $stats = $cache->getStats();

        $this->assertSame(3, $stats['total_entries']);
        $this->assertSame(1, $stats['expired_entries']);
        $this->assertSame(2, $stats['active_entries']);
    }
};

exit($test->run());
