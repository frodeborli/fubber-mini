<?php
/**
 * Test BTreeIndex implementation
 */

require __DIR__ . '/../../../ensure-autoloader.php';

use mini\Test;
use mini\Table\Index\BTreeIndex;
use mini\Table\Index\BTreeLeafPage;
use mini\Table\Index\BTreeInternalPage;

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

    public function testHighCardinalityEntries(): void
    {
        $path = $this->indexPath('high-cardinality');

        // Create index with 2 keys, each with 1000 row IDs
        // This should split entries across multiple leaf entries
        $index = BTreeIndex::fromGenerator($path, function() {
            for ($i = 0; $i < 1000; $i++) {
                yield ['active', $i];
            }
            for ($i = 0; $i < 1000; $i++) {
                yield ['inactive', 1000 + $i];
            }
        });
        $index->close();

        // Reopen and verify all row IDs are retrievable
        $index = new BTreeIndex($path);

        // Check all 'active' row IDs
        $activeIds = iterator_to_array($index->eq('active'));
        sort($activeIds);
        $this->assertSame(range(0, 999), $activeIds);

        // Check all 'inactive' row IDs
        $inactiveIds = iterator_to_array($index->eq('inactive'));
        sort($inactiveIds);
        $this->assertSame(range(1000, 1999), $inactiveIds);

        // Count should work
        $this->assertSame(1000, $index->count('active'));
        $this->assertSame(1000, $index->count('inactive'));

        $index->close();
    }

    public function testVeryHighCardinalityEntries(): void
    {
        $path = $this->indexPath('very-high-cardinality');

        // Create index with 1 key with 5000 row IDs
        // This should span multiple pages
        $index = BTreeIndex::fromGenerator($path, function() {
            for ($i = 0; $i < 5000; $i++) {
                yield ['mega', $i];
            }
        });
        $index->close();

        // Reopen and verify
        $index = new BTreeIndex($path);

        // Check count
        $this->assertSame(5000, $index->count('mega'));

        // Check all row IDs are present
        $ids = iterator_to_array($index->eq('mega'));
        sort($ids);
        $this->assertSame(range(0, 4999), $ids);

        $index->close();
    }

    public function testHighCardinalityIncrementalCommit(): void
    {
        // This tests the incremental commit path (not bulk load)
        // which had a bug causing duplicate/missing entries at ~3000+ rowIds
        $path = $this->indexPath('high-card-incremental');

        $index = new BTreeIndex($path);
        $index->begin();
        for ($i = 0; $i < 3500; $i++) {
            $index->insert('single_key', $i);
        }
        $index->commit();

        $result = iterator_to_array($index->eq('single_key'));
        $unique = array_unique($result);

        // Must have exactly 3500 unique values
        $this->assertSame(3500, count($result), "Total count mismatch");
        $this->assertSame(3500, count($unique), "Unique count mismatch - duplicates detected");

        // Verify all expected values are present
        sort($result);
        $this->assertSame(range(0, 3499), $result);

        $index->close();
    }

    public function testHighCardinalityIncrementalCommitVariousSizes(): void
    {
        // Test multiple sizes to catch edge cases around page splits
        foreach ([500, 1000, 2000, 3000, 3010, 4000, 5000] as $n) {
            $path = $this->indexPath("high-card-$n");

            $index = new BTreeIndex($path);
            $index->begin();
            for ($i = 0; $i < $n; $i++) {
                $index->insert('key', $i);
            }
            $index->commit();

            $result = iterator_to_array($index->eq('key'));
            $this->assertSame($n, count($result), "Size $n: total count mismatch");
            $this->assertSame($n, count(array_unique($result)), "Size $n: duplicates detected");

            $index->close();
        }
    }

    public function testHighCardinalitySingleWriteMode(): void
    {
        // Test single-write mode (no explicit transaction) with high cardinality
        $path = $this->indexPath('high-card-single-write');

        $index = new BTreeIndex($path);
        for ($i = 0; $i < 1000; $i++) {
            $index->insert('key', $i);
        }

        $result = iterator_to_array($index->eq('key'));
        $this->assertSame(1000, count($result));
        $this->assertSame(1000, count(array_unique($result)));

        $index->close();
    }

    // =========================================================================
    // Order verification
    // =========================================================================

    public function testRangeYieldsInSortedOrder(): void
    {
        $index = new BTreeIndex($this->indexPath());

        // Insert in random order
        $keys = ['delta', 'alpha', 'charlie', 'bravo', 'echo'];
        foreach ($keys as $i => $key) {
            $index->insert($key, $i);
        }

        // Should yield in sorted key order
        $results = [];
        foreach ($index->range() as $id) {
            $results[] = $keys[$id];
        }
        $this->assertSame(['alpha', 'bravo', 'charlie', 'delta', 'echo'], $results);

        $index->close();
    }

    public function testRangeReverseYieldsInReverseSortedOrder(): void
    {
        $index = new BTreeIndex($this->indexPath());

        $keys = ['delta', 'alpha', 'charlie', 'bravo', 'echo'];
        foreach ($keys as $i => $key) {
            $index->insert($key, $i);
        }

        $results = [];
        foreach ($index->range(reverse: true) as $id) {
            $results[] = $keys[$id];
        }
        $this->assertSame(['echo', 'delta', 'charlie', 'bravo', 'alpha'], $results);

        $index->close();
    }

    public function testMultipleRowIdsYieldedInInsertOrder(): void
    {
        $index = new BTreeIndex($this->indexPath());

        // Insert multiple rowIds for same key
        $index->insert('key', 100);
        $index->insert('key', 50);
        $index->insert('key', 200);

        // RowIds should come out in insertion order
        $results = iterator_to_array($index->eq('key'));
        $this->assertSame([100, 50, 200], $results);

        $index->close();
    }

    // =========================================================================
    // Duplicate and edge case handling
    // =========================================================================

    public function testDuplicateRowIdInsert(): void
    {
        $index = new BTreeIndex($this->indexPath());

        $index->insert('a', 1);
        $index->insert('a', 1); // Same key+rowId again

        // Should have duplicate (B-tree doesn't dedupe)
        $results = iterator_to_array($index->eq('a'));
        $this->assertSame([1, 1], $results);
        $this->assertSame(2, $index->count('a'));

        $index->close();
    }

    public function testEmptyKey(): void
    {
        $index = new BTreeIndex($this->indexPath());

        $index->insert('', 1);
        $index->insert('a', 2);

        $this->assertSame([1], iterator_to_array($index->eq('')));
        $this->assertTrue($index->has(''));

        // Empty string sorts before 'a'
        $results = iterator_to_array($index->range());
        $this->assertSame([1, 2], $results);

        $index->close();
    }

    public function testLargeRowIds(): void
    {
        $index = new BTreeIndex($this->indexPath());

        $large1 = PHP_INT_MAX;
        $large2 = PHP_INT_MAX - 1;
        $large3 = 2 ** 53; // Max safe integer in JS, good test value

        $index->insert('a', $large1);
        $index->insert('b', $large2);
        $index->insert('c', $large3);

        $this->assertSame([$large1], iterator_to_array($index->eq('a')));
        $this->assertSame([$large2], iterator_to_array($index->eq('b')));
        $this->assertSame([$large3], iterator_to_array($index->eq('c')));

        $index->close();
    }

    public function testRangeBoundsBetweenKeys(): void
    {
        $index = new BTreeIndex($this->indexPath());

        $index->insert('aa', 1);
        $index->insert('cc', 2);
        $index->insert('ee', 3);

        // Range with bounds between existing keys
        $results = iterator_to_array($index->range(start: 'bb', end: 'dd'));
        $this->assertSame([2], $results); // Only 'cc' is in range

        // Range with start between keys
        $results = iterator_to_array($index->range(start: 'bb'));
        $this->assertSame([2, 3], $results);

        // Range with end between keys
        $results = iterator_to_array($index->range(end: 'dd'));
        $this->assertSame([1, 2], $results);

        $index->close();
    }

    // =========================================================================
    // Transaction visibility
    // =========================================================================

    public function testBufferedInsertsVisibleDuringTransaction(): void
    {
        $index = new BTreeIndex($this->indexPath());

        $index->insert('a', 1);

        $index->begin();
        $index->insert('b', 2);
        $index->insert('c', 3);

        // New inserts should be visible via eq()
        $this->assertSame([2], iterator_to_array($index->eq('b')));
        $this->assertSame([3], iterator_to_array($index->eq('c')));

        // And via range()
        $results = iterator_to_array($index->range());
        $this->assertSame([1, 2, 3], $results);

        $index->commit();
        $index->close();
    }

    public function testMixedInsertDeleteInTransaction(): void
    {
        $index = new BTreeIndex($this->indexPath());

        $index->insert('a', 1);
        $index->insert('a', 2);

        $index->begin();
        $index->insert('a', 3);  // Add new rowId
        $index->delete('a', 1); // Delete existing rowId

        // Should see 2 and 3, not 1
        $results = iterator_to_array($index->eq('a'));
        sort($results);
        $this->assertSame([2, 3], $results);

        $index->commit();

        // After commit, same result
        $results = iterator_to_array($index->eq('a'));
        sort($results);
        $this->assertSame([2, 3], $results);

        $index->close();
    }

    public function testInsertThenDeleteSameKeyInTransaction(): void
    {
        $index = new BTreeIndex($this->indexPath());

        $index->begin();
        $index->insert('a', 1);
        $index->delete('a', 1);

        // Should not exist
        $this->assertSame([], iterator_to_array($index->eq('a')));
        $this->assertFalse($index->has('a'));

        $index->commit();

        // After commit, still doesn't exist
        $this->assertSame([], iterator_to_array($index->eq('a')));

        $index->close();
    }

    // =========================================================================
    // Out-of-order insert verification
    // =========================================================================

    public function testRandomOrderInsertsYieldSorted(): void
    {
        $index = new BTreeIndex($this->indexPath());

        // Insert 100 keys in random order
        $keys = range(0, 99);
        shuffle($keys);

        foreach ($keys as $k) {
            $index->insert(sprintf('%02d', $k), $k);
        }

        // Range should yield in sorted order
        $results = iterator_to_array($index->range());
        $this->assertSame(range(0, 99), $results);

        $index->close();
    }

    public function testBulkLoadRandomOrderYieldsSorted(): void
    {
        // Keys inserted in random order via bulk load
        $keys = range(0, 99);
        shuffle($keys);

        $index = BTreeIndex::fromGenerator($this->indexPath(), function() use ($keys) {
            foreach ($keys as $k) {
                yield [sprintf('%02d', $k), $k];
            }
        });

        // Should be sorted
        $results = iterator_to_array($index->range());
        $this->assertSame(range(0, 99), $results);

        $index->close();
    }

    // =========================================================================
    // Binary format round-trip tests
    // =========================================================================

    public function testLeafPageBinaryRoundTrip(): void
    {
        // NOTE: we intentionally test the leaf format directly to catch header packing bugs.
        // We must pad to a full page because fromRaw() expects it to be in-page layout.
        $PAGE_SIZE = 4096;

        // Entries are 1-based
        $entries = [
            1 => ['a', 1, 2, 3],
            2 => ['b', 10],
            3 => ["\x00\x01\xff", 999],   // binary-ish key
            4 => [str_repeat('k', 50), 7, 8],
        ];

        $leaf = BTreeLeafPage::fromEntries($entries);
        $pageBody = $leaf->asString();
        $leaf->release();

        // Pad to full page like appendPage() does
        $page = pack('a' . $PAGE_SIZE, $pageBody);

        $parsed = BTreeLeafPage::fromRaw($page);

        // Basic invariants
        $this->assertSame(count($entries), $parsed->count);

        // Validate keys via getKeyAt() (key-only parse path, 1-based)
        for ($i = 1; $i <= $parsed->count; $i++) {
            $this->assertSame($entries[$i][0], $parsed->getKeyAt($i));
        }

        // Validate full entries (rowIds + key) via getEntry() (1-based)
        for ($i = 1; $i <= $parsed->count; $i++) {
            $entry = $parsed->getEntry($i);

            $this->assertSame($entries[$i][0], $entry[0]);

            $expectedRowIds = array_slice($entries[$i], 1);
            $actualRowIds = [];
            for ($j = 1; $j <= count($entry) - 1; $j++) {
                $actualRowIds[] = $entry[$j];
            }
            $this->assertSame($expectedRowIds, $actualRowIds);
        }
    }

    public function testInternalPageBinaryRoundTripAndIndexingSemantics(): void
    {
        $PAGE_SIZE = 4096;

        // Build using fromArrays(): input is 0-based, converted to 1-based internally
        $children0 = [11, 22, 33, 44];
        $keys0 = ['b', 'c', 'd']; // n keys => n+1 children

        $node = BTreeInternalPage::fromArrays($children0, $keys0);
        $pageBody = $node->asString();

        $page = pack('a' . $PAGE_SIZE, $pageBody);

        // Parse back from raw bytes: both children and keys are 1-based
        $parsed = BTreeInternalPage::fromRaw($page);

        $this->assertSame(count($children0), $parsed->childCount);
        // Keys are now 1-based after fromRaw
        $this->assertSame([1 => 'b', 2 => 'c', 3 => 'd'], $parsed->keys);

        // Children from unpack are 1-based
        $this->assertSame($children0[0], $parsed->children[1]);
        $this->assertSame($children0[1], $parsed->children[2]);
        $this->assertSame($children0[2], $parsed->children[3]);
        $this->assertSame($children0[3], $parsed->children[4]);

        // Index 0 should not exist in the unpacked arrays (1-based)
        $this->assertFalse(isset($parsed->children[0]));
        $this->assertFalse(isset($parsed->keys[0]));
    }

    public function testPackPointerSizeAssumptionIsEightBytes(): void
    {
        // Your page layout math assumes 8 bytes per rowId/child.
        // This is only safe if pack('P', ...) is 8 bytes on this platform.
        $this->assertSame(8, strlen(pack('P', 1)));
    }

    public function testLeafHeaderOffsetsMatchExpectedHeaderSize(): void
    {
        $PAGE_SIZE = 4096;

        // Entries are 1-based
        $entries = [
            1 => ['a', 1],
            2 => ['b', 2],
        ];

        $leaf = BTreeLeafPage::fromEntries($entries);
        $pageBody = $leaf->asString();
        $leaf->release();

        $page = pack('a' . $PAGE_SIZE, $pageBody);

        $parsed = BTreeLeafPage::fromRaw($page);

        $n = $parsed->count;

        // Header is: type(1) + count(2) + offsets((n+1)*2) + rowIdCounts(n*2)
        $expectedHeaderSize = 3 + ($n + 1) * 2 + $n * 2;

        // meta[1] is the first entry offset; it should point exactly to header end
        $this->assertSame($expectedHeaderSize, $parsed->meta[1]);

        // end marker meta[n+1] should be <= PAGE_SIZE and >= header
        $this->assertGreaterThanOrEqual($expectedHeaderSize, $parsed->meta[$n + 1]);
        $this->assertLessThanOrEqual($PAGE_SIZE, $parsed->meta[$n + 1]);

        $parsed->release();
    }
};

$test->run();
