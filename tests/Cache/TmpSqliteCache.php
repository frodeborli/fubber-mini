<?php
/**
 * Test TmpSqliteCache PSR-16 implementation
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/_lib/AbstractPsr16Test.php';

use mini\Cache\TmpSqliteCache;
use Psr\SimpleCache\CacheInterface;

$test = new class extends AbstractPsr16Test {
    private string $dbPath;

    protected function createCache(): CacheInterface
    {
        $this->dbPath = sys_get_temp_dir() . '/mini-cache-test-' . uniqid() . '.sqlite3';
        return new TmpSqliteCache($this->dbPath);
    }

    // TmpSqliteCache-specific tests

    public function testCleanupRemovesExpiredEntries(): void
    {
        $this->cache->set('permanent', 'value');
        $this->cache->set('expired1', 'value', -1);
        $this->cache->set('expired2', 'value', -1);

        /** @var TmpSqliteCache $cache */
        $cache = $this->cache;
        $removed = $cache->cleanup();

        $this->assertSame(2, $removed);
        $this->assertTrue($this->cache->has('permanent'));
    }
};

exit($test->run());
