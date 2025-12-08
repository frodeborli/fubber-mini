<?php
/**
 * Test FilesystemCache PSR-16 implementation
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/_lib/AbstractPsr16Test.php';

use mini\Cache\FilesystemCache;
use Psr\SimpleCache\CacheInterface;

$test = new class extends AbstractPsr16Test {
    private string $testDir;

    protected function createCache(): CacheInterface
    {
        $this->testDir = sys_get_temp_dir() . '/mini-cache-test-' . uniqid();
        return new FilesystemCache($this->testDir);
    }

    // FilesystemCache-specific tests

    public function testCleanupRemovesExpiredEntries(): void
    {
        $this->cache->set('permanent', 'value');
        $this->cache->set('expired1', 'value', -1);
        $this->cache->set('expired2', 'value', -1);

        /** @var FilesystemCache $cache */
        $cache = $this->cache;
        $removed = $cache->cleanup();

        $this->assertSame(2, $removed);
        $this->assertTrue($this->cache->has('permanent'));
    }
};

exit($test->run());
