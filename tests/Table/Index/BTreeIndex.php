<?php
/**
 * Test BTreeIndex implementation
 */

require __DIR__ . '/../../../ensure-autoloader.php';

use mini\Test;
use mini\Table\Index\BTreeIndex;

$test = new class extends Test {

    private string $testDir;
    private static int $testNum = 0;

    public function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/btree-test-' . getmypid();
        if (!is_dir($this->testDir)) {
            mkdir($this->testDir, 0755, true);
        }
    }

    public function tearDown(): void
    {
        // Clean up test files including lock files
        foreach (glob($this->testDir . '/*') as $file) {
            @unlink($file);
        }
        @rmdir($this->testDir);
    }

    private function indexPath(string $name = 'test'): string
    {
        // Use incrementing number to ensure unique path per test
        self::$testNum++;
        return $this->testDir . '/' . $name . '-' . self::$testNum . '.btree';
    }

    // =========================================================================
    // Basic operations
    // =========================================================================

    public function testInsertAndEq(): void
    {
        $index = new BTreeIndex($this->indexPath());

        $index->insert('a', 1);
        $index->insert('a', 2);
        $index->insert('b', 3);

        $this->assertSame([1, 2], iterator_to_array($index->eq('a')));
        $this->assertSame([3], iterator_to_array($index->eq('b')));

        $index->close();
    }

    public function testEqNoMatch(): void
    {
        $index = new BTreeIndex($this->indexPath());
        $index->insert('a', 1);

        $this->assertSame([], iterator_to_array($index->eq('nonexistent')));

        $index->close();
    }

    public function testHas(): void
    {
        $index = new BTreeIndex($this->indexPath());

        $this->assertFalse($index->has('a'));

        $index->insert('a', 1);
        $this->assertTrue($index->has('a'));

        $index->close();
    }

    public function testCount(): void
    {
        $index = new BTreeIndex($this->indexPath());

        $this->assertSame(0, $index->count('a'));

        $index->insert('a', 1);
        $index->insert('a', 2);
        $index->insert('b', 3);

        $this->assertSame(2, $index->count('a'));
        $this->assertSame(1, $index->count('b'));

        $index->close();
    }

    // =========================================================================
    // Delete
    // =========================================================================

    public function testDelete(): void
    {
        $index = new BTreeIndex($this->indexPath());

        $index->insert('a', 1);
        $index->insert('a', 2);
        $index->insert('a', 3);

        $index->delete('a', 2);

        $this->assertSame([1, 3], iterator_to_array($index->eq('a')));

        $index->close();
    }

    public function testDeleteLastRowIdRemovesKey(): void
    {
        $index = new BTreeIndex($this->indexPath());

        $index->insert('a', 1);
        $index->delete('a', 1);

        $this->assertFalse($index->has('a'));
        $this->assertSame([], iterator_to_array($index->eq('a')));

        $index->close();
    }

    public function testDeleteNonexistent(): void
    {
        $index = new BTreeIndex($this->indexPath());

        $index->insert('a', 1);
        $index->delete('a', 99); // rowId doesn't exist
        $index->delete('b', 1);  // key doesn't exist

        $this->assertSame([1], iterator_to_array($index->eq('a')));

        $index->close();
    }

    // =========================================================================
    // Range queries
    // =========================================================================

    public function testRangeFullScan(): void
    {
        $index = new BTreeIndex($this->indexPath());

        $index->insert('c', 3);
        $index->insert('a', 1);
        $index->insert('b', 2);

        $results = iterator_to_array($index->range());
        $this->assertSame([1, 2, 3], $results);

        $index->close();
    }

    public function testRangeReverse(): void
    {
        $index = new BTreeIndex($this->indexPath());

        $index->insert('a', 1);
        $index->insert('b', 2);
        $index->insert('c', 3);

        $results = iterator_to_array($index->range(reverse: true));
        $this->assertSame([3, 2, 1], $results);

        $index->close();
    }

    public function testRangeWithBounds(): void
    {
        $index = new BTreeIndex($this->indexPath());

        $index->insert('a', 1);
        $index->insert('b', 2);
        $index->insert('c', 3);
        $index->insert('d', 4);

        // b <= key <= c
        $results = iterator_to_array($index->range(start: 'b', end: 'c'));
        $this->assertSame([2, 3], $results);

        $index->close();
    }

    public function testRangeWithStartOnly(): void
    {
        $index = new BTreeIndex($this->indexPath());

        $index->insert('a', 1);
        $index->insert('b', 2);
        $index->insert('c', 3);

        $results = iterator_to_array($index->range(start: 'b'));
        $this->assertSame([2, 3], $results);

        $index->close();
    }

    public function testRangeWithEndOnly(): void
    {
        $index = new BTreeIndex($this->indexPath());

        $index->insert('a', 1);
        $index->insert('b', 2);
        $index->insert('c', 3);

        $results = iterator_to_array($index->range(end: 'b'));
        $this->assertSame([1, 2], $results);

        $index->close();
    }

    public function testRangeReverseWithBounds(): void
    {
        $index = new BTreeIndex($this->indexPath());

        $index->insert('a', 1);
        $index->insert('b', 2);
        $index->insert('c', 3);
        $index->insert('d', 4);

        $results = iterator_to_array($index->range(start: 'b', end: 'c', reverse: true));
        $this->assertSame([3, 2], $results);

        $index->close();
    }

    public function testMultipleRowIdsPerKey(): void
    {
        $index = new BTreeIndex($this->indexPath());

        $index->insert('a', 1);
        $index->insert('a', 2);
        $index->insert('b', 3);
        $index->insert('b', 4);

        $results = iterator_to_array($index->range());
        $this->assertSame([1, 2, 3, 4], $results);

        $index->close();
    }

    public function testMultipleRowIdsPerKeyReverse(): void
    {
        $index = new BTreeIndex($this->indexPath());

        $index->insert('a', 1);
        $index->insert('a', 2);
        $index->insert('b', 3);
        $index->insert('b', 4);

        $results = iterator_to_array($index->range(reverse: true));
        $this->assertSame([4, 3, 2, 1], $results);

        $index->close();
    }

    // =========================================================================
    // Persistence
    // =========================================================================

    public function testPersistence(): void
    {
        $path = $this->indexPath('persist');

        // Create and populate index
        $index = new BTreeIndex($path);
        $index->insert('a', 1);
        $index->insert('b', 2);
        $index->insert('c', 3);
        $index->close();

        // Reopen and verify
        $index = new BTreeIndex($path);
        $this->assertSame([1], iterator_to_array($index->eq('a')));
        $this->assertSame([2], iterator_to_array($index->eq('b')));
        $this->assertSame([3], iterator_to_array($index->eq('c')));
        $this->assertSame([1, 2, 3], iterator_to_array($index->range()));
        $index->close();
    }

    public function testPersistenceAfterDelete(): void
    {
        $path = $this->indexPath('persist-delete');

        // Create and populate
        $index = new BTreeIndex($path);
        $index->insert('a', 1);
        $index->insert('b', 2);
        $index->insert('c', 3);
        $index->delete('b', 2);
        $index->close();

        // Reopen and verify
        $index = new BTreeIndex($path);
        $this->assertSame([1], iterator_to_array($index->eq('a')));
        $this->assertSame([], iterator_to_array($index->eq('b')));
        $this->assertSame([3], iterator_to_array($index->eq('c')));
        $index->close();
    }

    // =========================================================================
    // Transaction / Bulk loading
    // =========================================================================

    public function testBulkInsertWithTransaction(): void
    {
        $index = new BTreeIndex($this->indexPath());

        $index->begin();
        for ($i = 0; $i < 100; $i++) {
            $index->insert(sprintf('%03d', $i), $i);
        }
        $index->commit();

        $results = iterator_to_array($index->range());
        $expected = range(0, 99);
        $this->assertSame($expected, $results);

        $index->close();
    }

    public function testFromGenerator(): void
    {
        $index = BTreeIndex::fromGenerator($this->indexPath(), function() {
            for ($i = 0; $i < 50; $i++) {
                yield [sprintf('%02d', $i), $i];
            }
        });

        $results = iterator_to_array($index->range());
        $expected = range(0, 49);
        $this->assertSame($expected, $results);

        $index->close();
    }

    public function testFromArray(): void
    {
        $data = [];
        for ($i = 0; $i < 30; $i++) {
            $data[] = [sprintf('%02d', $i), $i];
        }

        $index = BTreeIndex::fromArray($this->indexPath(), $data);

        $results = iterator_to_array($index->range());
        $expected = range(0, 29);
        $this->assertSame($expected, $results);

        $index->close();
    }

    public function testRollback(): void
    {
        $index = new BTreeIndex($this->indexPath());

        $index->insert('a', 1);

        $index->begin();
        $index->insert('b', 2);
        $index->insert('c', 3);
        $index->rollback();

        // Only 'a' should exist
        $this->assertTrue($index->has('a'));
        $this->assertFalse($index->has('b'));
        $this->assertFalse($index->has('c'));

        $index->close();
    }

    public function testBufferedDeletesAppliedToRange(): void
    {
        $index = new BTreeIndex($this->indexPath());

        // Insert data to disk
        $index->insert('a', 1);
        $index->insert('b', 2);
        $index->insert('c', 3);

        // Start transaction and delete
        $index->begin();
        $index->delete('b', 2);

        // Range should not include deleted item, even though it's on disk
        $results = iterator_to_array($index->range());
        $this->assertSame([1, 3], $results);

        // eq() should also not include deleted item
        $this->assertSame([], iterator_to_array($index->eq('b')));

        $index->commit();

        // After commit, still no 'b'
        $this->assertSame([1, 3], iterator_to_array($index->range()));

        $index->close();
    }

    public function testTransactionAutoCommitOnClose(): void
    {
        $path = $this->indexPath('auto-commit');

        $index = new BTreeIndex($path);
        $index->begin();
        $index->insert('a', 1);
        $index->insert('b', 2);
        // No explicit commit - destructor should auto-commit
        unset($index);

        // Reopen and verify
        $index = new BTreeIndex($path);
        $this->assertSame([1], iterator_to_array($index->eq('a')));
        $this->assertSame([2], iterator_to_array($index->eq('b')));
        $index->close();
    }

    // =========================================================================
    // Larger data sets
    // =========================================================================

    public function testManyKeys(): void
    {
        $index = new BTreeIndex($this->indexPath());

        $index->begin();
        for ($i = 0; $i < 1000; $i++) {
            $index->insert(sprintf('%04d', $i), $i);
        }
        $index->commit();

        $results = iterator_to_array($index->range());
        $expected = range(0, 999);
        $this->assertSame($expected, $results);

        $index->close();
    }

    public function testManyKeysReverse(): void
    {
        $index = new BTreeIndex($this->indexPath());

        $index->begin();
        for ($i = 0; $i < 100; $i++) {
            $index->insert(sprintf('%03d', $i), $i);
        }
        $index->commit();

        $results = iterator_to_array($index->range(reverse: true));
        $expected = range(99, 0, -1);
        $this->assertSame($expected, $results);

        $index->close();
    }

    public function testManyKeysWithBoundedRange(): void
    {
        $index = new BTreeIndex($this->indexPath());

        $index->begin();
        for ($i = 0; $i < 100; $i++) {
            $index->insert(sprintf('%03d', $i), $i);
        }
        $index->commit();

        $results = iterator_to_array($index->range(start: '025', end: '075'));
        $expected = range(25, 75);
        $this->assertSame($expected, $results);

        $index->close();
    }

    // =========================================================================
    // Compact
    // =========================================================================

    public function testCompact(): void
    {
        $path = $this->indexPath('compact');
        $index = new BTreeIndex($path);

        // Insert many, delete some
        $index->begin();
        for ($i = 0; $i < 100; $i++) {
            $index->insert(sprintf('%03d', $i), $i);
        }
        $index->commit();

        // Delete every other key
        for ($i = 0; $i < 100; $i += 2) {
            $index->delete(sprintf('%03d', $i), $i);
        }

        $sizeBefore = filesize($path);

        // Compact
        $index->compact();

        $sizeAfter = filesize($path);

        // Size should decrease (though not guaranteed to be dramatically smaller)
        $this->assertLessThanOrEqual($sizeBefore, $sizeAfter);

        // Verify data is still correct
        $results = iterator_to_array($index->range());
        $expected = range(1, 99, 2);
        $this->assertSame($expected, $results);

        $index->close();
    }

    // =========================================================================
    // Edge cases
    // =========================================================================

    public function testEmptyIndex(): void
    {
        $index = new BTreeIndex($this->indexPath());

        $this->assertSame([], iterator_to_array($index->range()));
        $this->assertSame([], iterator_to_array($index->eq('any')));
        $this->assertFalse($index->has('any'));
        $this->assertSame(0, $index->count('any'));

        $index->close();
    }

    public function testRangeOnEmptyIndex(): void
    {
        $index = new BTreeIndex($this->indexPath());

        $this->assertSame([], iterator_to_array($index->range(start: 'a', end: 'z')));
        $this->assertSame([], iterator_to_array($index->range(reverse: true)));

        $index->close();
    }

    public function testSingleElement(): void
    {
        $index = new BTreeIndex($this->indexPath());

        $index->insert('only', 42);

        $this->assertSame([42], iterator_to_array($index->range()));
        $this->assertSame([42], iterator_to_array($index->range(reverse: true)));
        $this->assertSame([42], iterator_to_array($index->eq('only')));

        $index->close();
    }

    public function testRangeOutsideData(): void
    {
        $index = new BTreeIndex($this->indexPath());

        $index->insert('m', 1);

        // Range entirely before data
        $this->assertSame([], iterator_to_array($index->range(start: 'a', end: 'l')));

        // Range entirely after data
        $this->assertSame([], iterator_to_array($index->range(start: 'n', end: 'z')));

        $index->close();
    }

    public function testLongKeys(): void
    {
        $index = new BTreeIndex($this->indexPath());

        $longKey1 = str_repeat('a', 1000);
        $longKey2 = str_repeat('b', 1000);

        $index->insert($longKey1, 1);
        $index->insert($longKey2, 2);

        $this->assertSame([1], iterator_to_array($index->eq($longKey1)));
        $this->assertSame([2], iterator_to_array($index->eq($longKey2)));
        $this->assertSame([1, 2], iterator_to_array($index->range()));

        $index->close();
    }

    public function testBinaryKeys(): void
    {
        $index = new BTreeIndex($this->indexPath());

        $key1 = "\x00\x01\x02";
        $key2 = "\x00\x01\x03";
        $key3 = "\x00\x02\x01";

        $index->insert($key1, 1);
        $index->insert($key2, 2);
        $index->insert($key3, 3);

        $this->assertSame([1], iterator_to_array($index->eq($key1)));
        $this->assertSame([1, 2, 3], iterator_to_array($index->range()));

        $index->close();
    }

    public function testIncrementalInserts(): void
    {
        // Test that incremental inserts (without transaction) work
        $index = new BTreeIndex($this->indexPath());

        for ($i = 0; $i < 20; $i++) {
            $index->insert(sprintf('%02d', $i), $i);
        }

        $results = iterator_to_array($index->range());
        $expected = range(0, 19);
        $this->assertSame($expected, $results);

        $index->close();
    }

    public function testPersistenceWithManyKeys(): void
    {
        $path = $this->indexPath('persist-many');

        // Create with bulk load
        $index = BTreeIndex::fromGenerator($path, function() {
            for ($i = 0; $i < 500; $i++) {
                yield [sprintf('%03d', $i), $i];
            }
        });
        $index->close();

        // Reopen and verify
        $index = new BTreeIndex($path);

        // Check a few specific keys
        $this->assertSame([0], iterator_to_array($index->eq('000')));
        $this->assertSame([250], iterator_to_array($index->eq('250')));
        $this->assertSame([499], iterator_to_array($index->eq('499')));

        // Check range
        $results = iterator_to_array($index->range(start: '100', end: '105'));
        $this->assertSame([100, 101, 102, 103, 104, 105], $results);

        $index->close();
    }
};

$test->run();
