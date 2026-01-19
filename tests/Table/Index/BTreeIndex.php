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

    /**
     * Helper to create a leaf page from entries for testing.
     */
    private function leafFromEntries(array $inputEntries): BTreeLeafPage
    {
        $leaf = BTreeLeafPage::fromPool();
        $n = count($inputEntries);
        for ($i = 0; $i < $n; $i++) {
            $leaf->entries[$i] = $inputEntries[$i];
        }
        $leaf->count = $n;
        $leaf->entriesBuilt = true;
        $leaf->rebuildMeta();
        return $leaf;
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

        // Size should decrease or stay same (sizeAfter <= sizeBefore)
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
    // Transaction combination tests
    // =========================================================================

    public function testInsertAndHasInTransaction(): void
    {
        $index = new BTreeIndex($this->indexPath());

        $index->begin();
        $this->assertFalse($index->has('new_key'));

        $index->insert('new_key', 1);
        $this->assertTrue($index->has('new_key'));

        $index->insert('another_key', 2);
        $this->assertTrue($index->has('another_key'));

        $index->commit();
        $index->close();
    }

    public function testInsertAndCountInTransaction(): void
    {
        $index = new BTreeIndex($this->indexPath());

        $index->begin();
        $this->assertSame(0, $index->count('key'));

        $index->insert('key', 1);
        $this->assertSame(1, $index->count('key'));

        $index->insert('key', 2);
        $this->assertSame(2, $index->count('key'));

        $index->insert('key', 3);
        $this->assertSame(3, $index->count('key'));

        $index->commit();
        $this->assertSame(3, $index->count('key'));

        $index->close();
    }

    public function testAppendToExistingKeyInTransaction(): void
    {
        $index = new BTreeIndex($this->indexPath());

        // Insert before transaction
        $index->insert('key', 1);
        $index->insert('key', 2);

        // Append in transaction
        $index->begin();
        $index->insert('key', 3);
        $index->insert('key', 4);

        // Should see all 4
        $results = iterator_to_array($index->eq('key'));
        $this->assertSame([1, 2, 3, 4], $results);
        $this->assertSame(4, $index->count('key'));

        $index->commit();

        // Still all 4 after commit
        $results = iterator_to_array($index->eq('key'));
        $this->assertSame([1, 2, 3, 4], $results);

        $index->close();
    }

    public function testNewKeyAndExistingKeyInTransaction(): void
    {
        $index = new BTreeIndex($this->indexPath());

        // Existing key before transaction
        $index->insert('existing', 1);

        $index->begin();
        // Append to existing
        $index->insert('existing', 2);
        // New key
        $index->insert('new', 10);

        // Both should be visible
        $this->assertSame([1, 2], iterator_to_array($index->eq('existing')));
        $this->assertSame([10], iterator_to_array($index->eq('new')));
        $this->assertTrue($index->has('existing'));
        $this->assertTrue($index->has('new'));

        $index->commit();
        $index->close();
    }

    public function testMultipleAppendsToSameKeyInTransaction(): void
    {
        $index = new BTreeIndex($this->indexPath());

        $index->begin();
        for ($i = 0; $i < 100; $i++) {
            $index->insert('same_key', $i);
        }

        // All 100 should be visible
        $results = iterator_to_array($index->eq('same_key'));
        $this->assertSame(range(0, 99), $results);
        $this->assertSame(100, $index->count('same_key'));

        $index->commit();

        // Still 100 after commit
        $this->assertSame(range(0, 99), iterator_to_array($index->eq('same_key')));

        $index->close();
    }

    public function testInsertCommitThenLookup(): void
    {
        $path = $this->indexPath();
        $index = new BTreeIndex($path);

        $index->begin();
        $index->insert('a', 1);
        $index->insert('b', 2);
        $index->insert('b', 3);
        $index->commit();

        // Lookup after commit
        $this->assertSame([1], iterator_to_array($index->eq('a')));
        $this->assertSame([2, 3], iterator_to_array($index->eq('b')));
        $this->assertTrue($index->has('a'));
        $this->assertTrue($index->has('b'));
        $this->assertSame(1, $index->count('a'));
        $this->assertSame(2, $index->count('b'));

        $index->close();
    }

    public function testInsertCommitReopenThenLookup(): void
    {
        $path = $this->indexPath();

        // Insert and commit
        $index = new BTreeIndex($path);
        $index->begin();
        $index->insert('a', 1);
        $index->insert('b', 2);
        $index->insert('b', 3);
        $index->commit();
        $index->close();

        // Reopen and lookup
        $index = new BTreeIndex($path);
        $this->assertSame([1], iterator_to_array($index->eq('a')));
        $this->assertSame([2, 3], iterator_to_array($index->eq('b')));
        $this->assertTrue($index->has('a'));
        $this->assertTrue($index->has('b'));
        $this->assertSame(1, $index->count('a'));
        $this->assertSame(2, $index->count('b'));

        $index->close();
    }

    public function testRangeInTransaction(): void
    {
        $index = new BTreeIndex($this->indexPath());

        // Some data before txn
        $index->insert('b', 2);

        $index->begin();
        $index->insert('a', 1);
        $index->insert('c', 3);

        // Range should include all three in order
        $results = iterator_to_array($index->range());
        $this->assertSame([1, 2, 3], $results);

        // Reverse range
        $results = iterator_to_array($index->range(reverse: true));
        $this->assertSame([3, 2, 1], $results);

        // Bounded range
        $results = iterator_to_array($index->range(start: 'a', end: 'b'));
        $this->assertSame([1, 2], $results);

        $index->commit();
        $index->close();
    }

    // =========================================================================
    // Variable length key tests
    // =========================================================================

    public function testVariableLengthKeysMixed(): void
    {
        $index = new BTreeIndex($this->indexPath());

        $keys = [
            'a',
            'ab',
            'abc',
            'abcd',
            str_repeat('x', 100),
            str_repeat('y', 500),
            'z',
        ];

        foreach ($keys as $i => $key) {
            $index->insert($key, $i);
        }

        // Verify each key
        foreach ($keys as $i => $key) {
            $this->assertSame([$i], iterator_to_array($index->eq($key)));
            $this->assertTrue($index->has($key));
        }

        $index->close();
    }

    public function testVariableLengthKeysInTransaction(): void
    {
        $index = new BTreeIndex($this->indexPath());

        $index->begin();

        $short = 'a';
        $medium = str_repeat('m', 100);
        $long = str_repeat('l', 1000);

        $index->insert($short, 1);
        $index->insert($medium, 2);
        $index->insert($long, 3);

        // All visible in transaction
        $this->assertSame([1], iterator_to_array($index->eq($short)));
        $this->assertSame([2], iterator_to_array($index->eq($medium)));
        $this->assertSame([3], iterator_to_array($index->eq($long)));

        $index->commit();

        // Still visible after commit
        $this->assertSame([1], iterator_to_array($index->eq($short)));
        $this->assertSame([2], iterator_to_array($index->eq($medium)));
        $this->assertSame([3], iterator_to_array($index->eq($long)));

        $index->close();
    }

    public function testLongKeysWithMultipleRowIds(): void
    {
        $index = new BTreeIndex($this->indexPath());

        $longKey = str_repeat('k', 500);

        $index->insert($longKey, 1);
        $index->insert($longKey, 2);
        $index->insert($longKey, 3);

        $this->assertSame([1, 2, 3], iterator_to_array($index->eq($longKey)));
        $this->assertSame(3, $index->count($longKey));

        $index->close();
    }

    public function testLongKeysInRange(): void
    {
        $index = new BTreeIndex($this->indexPath());

        $key1 = str_repeat('a', 200);
        $key2 = str_repeat('b', 200);
        $key3 = str_repeat('c', 200);

        $index->insert($key1, 1);
        $index->insert($key2, 2);
        $index->insert($key3, 3);

        // Full range
        $this->assertSame([1, 2, 3], iterator_to_array($index->range()));

        // Bounded range
        $this->assertSame([1, 2], iterator_to_array($index->range(end: $key2)));
        $this->assertSame([2, 3], iterator_to_array($index->range(start: $key2)));

        $index->close();
    }

    public function testPrefixKeysOrdering(): void
    {
        $index = new BTreeIndex($this->indexPath());

        // Insert keys that are prefixes of each other
        $index->insert('test', 1);
        $index->insert('testing', 2);
        $index->insert('test123', 3);
        $index->insert('tes', 4);
        $index->insert('te', 5);

        // Range should be in lexicographic order
        $results = iterator_to_array($index->range());
        $this->assertSame([5, 4, 1, 3, 2], $results); // te, tes, test, test123, testing

        $index->close();
    }

    // =========================================================================
    // Delete in transaction tests
    // =========================================================================

    public function testDeleteInTransactionThenLookup(): void
    {
        $index = new BTreeIndex($this->indexPath());

        $index->insert('a', 1);
        $index->insert('a', 2);
        $index->insert('b', 3);

        $index->begin();
        $index->delete('a', 1);

        // During transaction
        $this->assertSame([2], iterator_to_array($index->eq('a')));
        $this->assertTrue($index->has('a'));
        $this->assertSame(1, $index->count('a'));

        $index->commit();

        // After commit
        $this->assertSame([2], iterator_to_array($index->eq('a')));

        $index->close();
    }

    public function testDeleteAllRowIdsInTransaction(): void
    {
        $index = new BTreeIndex($this->indexPath());

        $index->insert('key', 1);
        $index->insert('key', 2);

        $index->begin();
        $index->delete('key', 1);
        $index->delete('key', 2);

        // Key should not exist
        $this->assertFalse($index->has('key'));
        $this->assertSame([], iterator_to_array($index->eq('key')));
        $this->assertSame(0, $index->count('key'));

        $index->commit();

        // Still gone after commit
        $this->assertFalse($index->has('key'));

        $index->close();
    }

    public function testInsertDeleteInsertInTransaction(): void
    {
        $index = new BTreeIndex($this->indexPath());

        $index->begin();
        $index->insert('key', 1);
        $index->delete('key', 1);
        $index->insert('key', 2);

        // Should only have rowId 2
        $this->assertSame([2], iterator_to_array($index->eq('key')));
        $this->assertSame(1, $index->count('key'));

        $index->commit();
        $this->assertSame([2], iterator_to_array($index->eq('key')));

        $index->close();
    }

    // =========================================================================
    // High cardinality in transaction tests
    // =========================================================================

    public function testHighCardinalityAppendInTransaction(): void
    {
        $index = new BTreeIndex($this->indexPath());

        // Existing high cardinality key
        $index->begin();
        for ($i = 0; $i < 500; $i++) {
            $index->insert('key', $i);
        }
        $index->commit();

        // Append more in new transaction
        $index->begin();
        for ($i = 500; $i < 1000; $i++) {
            $index->insert('key', $i);
        }

        // All 1000 should be visible
        $results = iterator_to_array($index->eq('key'));
        sort($results);
        $this->assertSame(range(0, 999), $results);

        $index->commit();

        // Still all 1000 after commit
        $results = iterator_to_array($index->eq('key'));
        sort($results);
        $this->assertSame(range(0, 999), $results);

        $index->close();
    }

    public function testHighCardinalityDeleteInTransaction(): void
    {
        $index = new BTreeIndex($this->indexPath());

        // Create high cardinality key
        $index->begin();
        for ($i = 0; $i < 100; $i++) {
            $index->insert('key', $i);
        }
        $index->commit();

        // Delete some in transaction
        $index->begin();
        for ($i = 0; $i < 100; $i += 2) {
            $index->delete('key', $i);
        }

        // Only odd numbers remain
        $results = iterator_to_array($index->eq('key'));
        sort($results);
        $this->assertSame(range(1, 99, 2), $results);
        $this->assertSame(50, $index->count('key'));

        $index->commit();

        // Same after commit
        $results = iterator_to_array($index->eq('key'));
        sort($results);
        $this->assertSame(range(1, 99, 2), $results);

        $index->close();
    }

    // =========================================================================
    // Edge case combination tests
    // =========================================================================

    public function testEmptyKeyInTransaction(): void
    {
        $index = new BTreeIndex($this->indexPath());

        $index->begin();
        $index->insert('', 1);
        $index->insert('a', 2);

        $this->assertTrue($index->has(''));
        $this->assertSame([1], iterator_to_array($index->eq('')));

        $index->commit();

        $this->assertTrue($index->has(''));
        $this->assertSame([1], iterator_to_array($index->eq('')));

        $index->close();
    }

    public function testBinaryKeysInTransaction(): void
    {
        $index = new BTreeIndex($this->indexPath());

        $key1 = "\x00\x00\x00";
        $key2 = "\x00\x00\x01";
        $key3 = "\xff\xff\xff";

        $index->begin();
        $index->insert($key1, 1);
        $index->insert($key2, 2);
        $index->insert($key3, 3);

        $this->assertSame([1], iterator_to_array($index->eq($key1)));
        $this->assertSame([2], iterator_to_array($index->eq($key2)));
        $this->assertSame([3], iterator_to_array($index->eq($key3)));

        // Range should be in binary order
        $this->assertSame([1, 2, 3], iterator_to_array($index->range()));

        $index->commit();
        $index->close();
    }

    public function testMixedOperationsAcrossTransactions(): void
    {
        $path = $this->indexPath();
        $index = new BTreeIndex($path);

        // First transaction: insert
        $index->begin();
        $index->insert('a', 1);
        $index->insert('b', 2);
        $index->insert('c', 3);
        $index->commit();

        // Second transaction: delete and insert
        $index->begin();
        $index->delete('b', 2);
        $index->insert('d', 4);
        $index->commit();

        // Third transaction: append to existing
        $index->begin();
        $index->insert('a', 10);
        $index->insert('c', 30);
        $index->commit();

        // Final state
        $this->assertSame([1, 10], iterator_to_array($index->eq('a')));
        $this->assertSame([], iterator_to_array($index->eq('b')));
        $this->assertSame([3, 30], iterator_to_array($index->eq('c')));
        $this->assertSame([4], iterator_to_array($index->eq('d')));

        $index->close();

        // Verify persistence
        $index = new BTreeIndex($path);
        $this->assertSame([1, 10], iterator_to_array($index->eq('a')));
        $this->assertSame([], iterator_to_array($index->eq('b')));
        $this->assertSame([3, 30], iterator_to_array($index->eq('c')));
        $this->assertSame([4], iterator_to_array($index->eq('d')));
        $index->close();
    }

    public function testRollbackRestoresPreviousState(): void
    {
        $index = new BTreeIndex($this->indexPath());

        // Initial state
        $index->insert('a', 1);
        $index->insert('b', 2);

        // Transaction that will be rolled back
        $index->begin();
        $index->insert('c', 3);
        $index->insert('a', 10); // Append to existing
        $index->delete('b', 2);

        // During transaction
        $this->assertSame([1, 10], iterator_to_array($index->eq('a')));
        $this->assertSame([], iterator_to_array($index->eq('b')));
        $this->assertSame([3], iterator_to_array($index->eq('c')));

        $index->rollback();

        // After rollback - back to original state
        $this->assertSame([1], iterator_to_array($index->eq('a')));
        $this->assertSame([2], iterator_to_array($index->eq('b')));
        $this->assertSame([], iterator_to_array($index->eq('c')));

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

        // Entries are list format: [null, entry1, entry2, ...]
        $entries = [
            ['a', 1, 2, 3],
            ['b', 10],
            ["\x00\x01\xff", 999],   // binary-ish key
            [str_repeat('k', 50), 7, 8],
        ];

        $leaf = $this->leafFromEntries($entries);
        $pageBody = $leaf->asString();
        $leaf->release();

        // Pad to full page like appendPage() does
        $page = pack('a' . $PAGE_SIZE, $pageBody);

        $parsed = BTreeLeafPage::fromRaw($page);

        // Basic invariants (0-based)
        $this->assertSame(count($entries), $parsed->count);

        // Validate keys via getKeyAt() (key-only parse path, 0-based)
        for ($i = 0; $i < $parsed->count; $i++) {
            $this->assertSame($entries[$i][0], $parsed->getKeyAt($i));
        }

        // Validate full entries (rowIds + key) via getEntry() (0-based)
        for ($i = 0; $i < $parsed->count; $i++) {
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

        // Build using fromArrays(): input is 0-based
        $children0 = [11, 22, 33, 44];
        $keys0 = ['b', 'c', 'd']; // n keys => n+1 children

        $node = BTreeInternalPage::fromArrays($children0, $keys0);
        $pageBody = $node->asString();

        $page = pack('a' . $PAGE_SIZE, $pageBody);

        // Parse back from raw bytes (0-based ArrayObject)
        $parsed = BTreeInternalPage::fromRaw($page);

        $this->assertSame(count($children0), $parsed->childCount);
        // Keys are 0-based after fromRaw
        $this->assertSame('b', $parsed->keys[0]);
        $this->assertSame('c', $parsed->keys[1]);
        $this->assertSame('d', $parsed->keys[2]);

        // Children are 0-based
        $this->assertSame($children0[0], $parsed->children[0]);
        $this->assertSame($children0[1], $parsed->children[1]);
        $this->assertSame($children0[2], $parsed->children[2]);
        $this->assertSame($children0[3], $parsed->children[3]);
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

        // Entries are 0-based
        $entries = [
            ['a', 1],
            ['b', 2],
        ];

        $leaf = $this->leafFromEntries($entries);
        $pageBody = $leaf->asString();
        $leaf->release();

        $page = pack('a' . $PAGE_SIZE, $pageBody);

        $parsed = BTreeLeafPage::fromRaw($page);

        $n = $parsed->count;

        // Header is: type(1) + count(2) + offsets((n+1)*2) + rowIdCounts(n*2)
        $expectedHeaderSize = 3 + ($n + 1) * 2 + $n * 2;

        // meta[0] is the first entry offset (0-based); it should point exactly to header end
        $this->assertSame($expectedHeaderSize, $parsed->meta[0]);

        // end marker meta[n] should be <= PAGE_SIZE and >= header (0-based)
        $this->assertGreaterThanOrEqual($expectedHeaderSize, $parsed->meta[$n]);
        $this->assertLessThanOrEqual($PAGE_SIZE, $parsed->meta[$n]);

        $parsed->release();
    }

    /**
     * Test compact with enough keys to create internal nodes.
     * This tests the rewritePages() iteration over list format children.
     */
    public function testCompactWithInternalNodes(): void
    {
        $path = $this->indexPath('compact_internal');
        $index = new BTreeIndex($path);

        // Insert 2000 keys - enough to create multiple internal nodes
        $index->begin();
        for ($i = 0; $i < 2000; $i++) {
            $index->insert(sprintf('%04d', $i), $i);
        }
        $index->commit();

        // Delete half the keys to create garbage
        for ($i = 0; $i < 2000; $i += 2) {
            $index->delete(sprintf('%04d', $i), $i);
        }

        // Compact
        $index->compact();

        // Verify all remaining data is still correct
        $results = iterator_to_array($index->range());
        $expected = range(1, 1999, 2); // odd numbers
        $this->assertSame($expected, $results);

        // Verify point lookups still work
        $this->assertSame([1], iterator_to_array($index->eq('0001')));
        $this->assertSame([999], iterator_to_array($index->eq('0999')));
        $this->assertSame([1999], iterator_to_array($index->eq('1999')));

        // Verify deleted keys are gone
        $this->assertSame([], iterator_to_array($index->eq('0000')));
        $this->assertSame([], iterator_to_array($index->eq('1000')));

        $index->close();
    }

    /**
     * Test creating an empty leaf page with 0-based format [].
     * This is the format used when creating a new empty index.
     */
    public function testEmptyLeafPageFromEntries(): void
    {
        $PAGE_SIZE = 4096;

        // Empty entries = empty 0-based array
        $emptyLeaf = $this->leafFromEntries([]);

        $this->assertSame(0, $emptyLeaf->count);

        // Serialize and parse back
        $pageBody = $emptyLeaf->asString();
        $emptyLeaf->release();

        $page = pack('a' . $PAGE_SIZE, $pageBody);
        $parsed = BTreeLeafPage::fromRaw($page);

        $this->assertSame(0, $parsed->count);

        // Empty page should have no entries (count is authoritative, not ArrayObject size)
        $parsed->buildEntries();
        $this->assertSame(0, $parsed->count);

        $parsed->release();
    }

    /**
     * Test that sorted and shuffled inserts produce identical range scans.
     */
    public function testSortedVsShuffledInserts(): void
    {
        $n = 5000;
        $keys = [];
        for ($i = 0; $i < $n; $i++) {
            $keys[] = sprintf('%06d', $i);
        }

        // Build index with sorted keys
        $sortedPath = $this->indexPath('sorted_insert');
        $sortedIndex = new BTreeIndex($sortedPath);
        $sortedIndex->begin();
        foreach ($keys as $i => $key) {
            $sortedIndex->insert($key, $i);
        }
        $sortedIndex->commit();

        // Build index with shuffled keys
        $shuffledKeys = $keys;
        shuffle($shuffledKeys);

        $shuffledPath = $this->indexPath('shuffled_insert');
        $shuffledIndex = new BTreeIndex($shuffledPath);
        $shuffledIndex->begin();
        foreach ($shuffledKeys as $key) {
            // Use original index as rowId to match sorted index
            $shuffledIndex->insert($key, (int)$key);
        }
        $shuffledIndex->commit();

        // Compare forward range scans
        $sortedResults = iterator_to_array($sortedIndex->range());
        $shuffledResults = iterator_to_array($shuffledIndex->range());

        $this->assertSame($sortedResults, $shuffledResults);

        $sortedIndex->close();
        $shuffledIndex->close();
    }

    /**
     * Test BTreeLeafPage::splitOversized() generator.
     */
    public function testLeafSplitOversized(): void
    {
        // Create a leaf with many entries that exceeds page size
        $entries = [];
        for ($i = 0; $i < 500; $i++) {
            $entries[] = [sprintf('key%04d', $i), $i];
        }

        $leaf = $this->leafFromEntries($entries);
        $originalSize = $leaf->calculateSize();
        $this->assertTrue($originalSize > 4096, "Leaf should be oversized: $originalSize");

        // Split it
        $pages = iterator_to_array($leaf->splitOversized(4096));

        // Should have multiple pages
        $this->assertTrue(count($pages) > 1, "Should split into multiple pages");

        // First page should be the original leaf (keeps smallest keys)
        $this->assertSame($leaf, $pages[0]);

        // All pages should fit within page size
        foreach ($pages as $idx => $page) {
            $size = $page->calculateSize();
            $this->assertTrue($size <= 4096, "Page $idx size $size exceeds 4096");
        }

        // Total entries should match original
        $totalEntries = 0;
        $allKeys = [];
        foreach ($pages as $page) {
            $totalEntries += $page->count;
            for ($i = 0; $i < $page->count; $i++) {
                $allKeys[] = $page->entries[$i][0];
            }
        }
        $this->assertSame(500, $totalEntries);

        // Keys should still be in sorted order across all pages
        $sortedKeys = $allKeys;
        sort($sortedKeys);
        $this->assertSame($sortedKeys, $allKeys);

        // Release pages (except original at index 0 which we'll release separately)
        for ($i = 1; $i < count($pages); $i++) {
            $pages[$i]->release();
        }
        $leaf->release();
    }

    /**
     * Test splitOversized with high-cardinality entry (many rowIds).
     */
    public function testLeafSplitOversizedHighCardinality(): void
    {
        // Create a leaf with one key but many rowIds
        $entry = ['bigkey'];
        for ($i = 0; $i < 1000; $i++) {
            $entry[] = $i;
        }

        $leaf = BTreeLeafPage::fromPool();
        $leaf->entries[0] = $entry;
        $leaf->count = 1;
        $leaf->entriesBuilt = true;
        $leaf->rebuildMeta();

        $originalSize = $leaf->calculateSize();
        $this->assertTrue($originalSize > 4096, "Leaf should be oversized: $originalSize");

        // Split it
        $pages = iterator_to_array($leaf->splitOversized(4096));

        // Should have multiple pages
        $this->assertTrue(count($pages) > 1, "Should split into multiple pages");

        // All pages should have the same key
        foreach ($pages as $page) {
            $this->assertSame('bigkey', $page->entries[0][0]);
            $this->assertTrue($page->calculateSize() <= 4096);
        }

        // Total rowIds should match
        $totalRowIds = 0;
        foreach ($pages as $page) {
            $totalRowIds += count($page->entries[0]) - 1;
        }
        $this->assertSame(1000, $totalRowIds);

        // Release
        foreach ($pages as $page) {
            $page->release();
        }
    }

    /**
     * Test splitOversized on a leaf that fits - should just yield self.
     */
    public function testLeafSplitOversizedFits(): void
    {
        $entries = [];
        for ($i = 0; $i < 10; $i++) {
            $entries[] = ["key$i", $i];
        }

        $leaf = $this->leafFromEntries($entries);
        $this->assertTrue($leaf->calculateSize() <= 4096);

        $pages = iterator_to_array($leaf->splitOversized(4096));

        $this->assertCount(1, $pages);
        $this->assertSame($leaf, $pages[0]);
        $this->assertSame(10, $leaf->count);

        $leaf->release();
    }

    /**
     * Test BTreeInternalPage::splitOversized() generator.
     */
    public function testInternalSplitOversized(): void
    {
        // Create an internal page with many children that exceeds page size
        $children = [];
        $keys = [];
        for ($i = 0; $i < 500; $i++) {
            $children[] = $i + 100; // Page numbers
            if ($i > 0) {
                $keys[] = sprintf('separator%04d', $i);
            }
        }

        $internal = BTreeInternalPage::fromArrays($children, $keys);
        $originalSize = $internal->calculateSize();
        $this->assertTrue($originalSize > 4096, "Internal should be oversized: $originalSize");

        // Split it
        $pages = iterator_to_array($internal->splitOversized(4096));

        // Should have multiple pages
        $this->assertTrue(count($pages) > 1, "Should split into multiple pages");

        // First page should be the original (keeps smallest keys)
        $this->assertSame($internal, $pages[0]);

        // All pages should fit within page size
        foreach ($pages as $idx => $page) {
            $size = $page->calculateSize();
            $this->assertTrue($size <= 4096, "Page $idx size $size exceeds 4096");
        }

        // Total children should match original
        $totalChildren = 0;
        foreach ($pages as $page) {
            $totalChildren += $page->childCount;
        }
        $this->assertSame(500, $totalChildren);
    }

    /**
     * Test BTreeInternalPage::importWriteBuffer().
     */
    public function testInternalImportWriteBuffer(): void
    {
        // Create an internal page with 3 children
        $internal = BTreeInternalPage::fromArrays([100, 200, 300], ['key_b', 'key_d']);

        // Add children via writeBuffer
        $internal->writeBuffer[] = ['key_a', 150]; // Should go between 100 and 200
        $internal->writeBuffer[] = ['key_c', 250]; // Should go between 200 and 300
        $internal->writeBuffer[] = ['key_e', 350]; // Should go after 300

        $internal->importWriteBuffer();

        // Should now have 6 children in sorted order
        $this->assertSame(6, $internal->childCount);
        $this->assertSame(100, $internal->children[0]);
        $this->assertSame(350, $internal->children[5]);
        $this->assertSame(['key_a', 'key_b', 'key_c', 'key_d', 'key_e'], $internal->keys);
        $this->assertSame([], $internal->writeBuffer);
    }

    /**
     * Test internal splitOversized when it fits.
     */
    public function testInternalSplitOversizedFits(): void
    {
        $internal = BTreeInternalPage::fromArrays([100, 200, 300], ['key_a', 'key_b']);
        $this->assertTrue($internal->calculateSize() <= 4096);

        $pages = iterator_to_array($internal->splitOversized(4096));

        $this->assertCount(1, $pages);
        $this->assertSame($internal, $pages[0]);
        $this->assertSame(3, $internal->childCount);
    }

    // =========================================================================
    // Production readiness tests
    // =========================================================================

    public function testMultipleConcurrentRangeIterators(): void
    {
        $index = new BTreeIndex($this->indexPath());

        $index->begin();
        for ($i = 0; $i < 100; $i++) {
            $index->insert(sprintf('%02d', $i), $i);
        }
        $index->commit();

        // Start two iterators
        $iter1 = $index->range();
        $iter2 = $index->range(reverse: true);

        // Interleave reads - current() first since generators start at first yield
        $results1 = [];
        $results2 = [];
        for ($i = 0; $i < 50; $i++) {
            $results1[] = $iter1->current();
            $results2[] = $iter2->current();
            $iter1->next();
            $iter2->next();
        }

        // Both should have valid results from beginning
        $this->assertSame(range(0, 49), $results1);       // Forward: 0-49
        $this->assertSame(range(99, 50, -1), $results2);  // Reverse: 99-50

        $index->close();
    }

    public function testInsertDuringRangeIteration(): void
    {
        $index = new BTreeIndex($this->indexPath());

        $index->insert('a', 1);
        $index->insert('c', 3);
        $index->insert('e', 5);

        $results = [];
        $index->begin();
        foreach ($index->range() as $rowId) {
            $results[] = $rowId;
            if ($rowId === 1) {
                // Insert during iteration
                $index->insert('b', 2);
                $index->insert('d', 4);
            }
        }

        // Should see original items; new inserts may or may not appear
        // depending on iterator position - but should NOT crash
        $this->assertTrue(in_array(1, $results, true));
        $this->assertTrue(in_array(3, $results, true));
        $this->assertTrue(in_array(5, $results, true));

        $index->commit();

        // After commit, all items present
        $this->assertSame([1, 2, 3, 4, 5], iterator_to_array($index->range()));

        $index->close();
    }

    public function testDeleteDuringRangeIteration(): void
    {
        $index = new BTreeIndex($this->indexPath());

        $index->insert('a', 1);
        $index->insert('b', 2);
        $index->insert('c', 3);
        $index->insert('d', 4);
        $index->insert('e', 5);

        $results = [];
        $index->begin();
        foreach ($index->range() as $rowId) {
            $results[] = $rowId;
            if ($rowId === 2) {
                // Delete items ahead in iteration
                $index->delete('d', 4);
            }
        }

        // Should complete without crash
        $this->assertTrue(in_array(1, $results, true));
        $this->assertTrue(in_array(2, $results, true));

        $index->commit();

        // After commit, deleted item gone
        $this->assertFalse($index->has('d'));

        $index->close();
    }

    public function testCloseWithoutCommitDiscardsChanges(): void
    {
        $path = $this->indexPath();

        // Create initial state
        $index = new BTreeIndex($path);
        $index->insert('a', 1);
        $index->close();

        // Open, modify, close WITHOUT commit
        $index = new BTreeIndex($path);
        $index->begin();
        $index->insert('b', 2);
        $index->insert('c', 3);
        // Simulate crash/abnormal close - destroy without commit
        $index->rollback();
        $index->close();

        // Reopen - should only have original data
        $index = new BTreeIndex($path);
        $this->assertSame([1], iterator_to_array($index->eq('a')));
        $this->assertFalse($index->has('b'));
        $this->assertFalse($index->has('c'));
        $index->close();
    }

    public function testUnicodeKeys(): void
    {
        $index = new BTreeIndex($this->indexPath());

        $keys = [
            'émoji' => 1,
            '日本語' => 2,
            '🎉🎊🎁' => 3,
            'Ñoño' => 4,
            'Привет' => 5,
            'مرحبا' => 6,
            '中文' => 7,
        ];

        foreach ($keys as $key => $id) {
            $index->insert($key, $id);
        }

        // Verify each key
        foreach ($keys as $key => $id) {
            $this->assertSame([$id], iterator_to_array($index->eq($key)));
            $this->assertTrue($index->has($key));
        }

        // Range should work
        $results = iterator_to_array($index->range());
        $this->assertCount(7, $results);

        $index->close();
    }

    public function testUnicodeKeysPersistence(): void
    {
        $path = $this->indexPath();

        $index = new BTreeIndex($path);
        $index->insert('日本語', 1);
        $index->insert('🎉', 2);
        $index->close();

        $index = new BTreeIndex($path);
        $this->assertSame([1], iterator_to_array($index->eq('日本語')));
        $this->assertSame([2], iterator_to_array($index->eq('🎉')));
        $index->close();
    }

    public function testRangeExactBoundMatch(): void
    {
        $index = new BTreeIndex($this->indexPath());

        $index->insert('a', 1);
        $index->insert('b', 2);
        $index->insert('c', 3);

        // Range where start = end = existing key
        $results = iterator_to_array($index->range(start: 'b', end: 'b'));
        $this->assertSame([2], $results);

        // Range where start = end = non-existing key
        $results = iterator_to_array($index->range(start: 'bb', end: 'bb'));
        $this->assertSame([], $results);

        $index->close();
    }

    public function testDeleteThenReinsertSameKeyInTransaction(): void
    {
        $index = new BTreeIndex($this->indexPath());

        $index->insert('key', 1);
        $index->insert('key', 2);

        $index->begin();
        // Delete all
        $index->delete('key', 1);
        $index->delete('key', 2);

        $this->assertFalse($index->has('key'));

        // Re-insert same key with new rowIds
        $index->insert('key', 100);
        $index->insert('key', 200);

        $this->assertTrue($index->has('key'));
        $this->assertSame([100, 200], iterator_to_array($index->eq('key')));

        $index->commit();

        // After commit
        $this->assertSame([100, 200], iterator_to_array($index->eq('key')));

        $index->close();
    }

    public function testFromGeneratorUnsortedInput(): void
    {
        // fromGenerator should handle unsorted input
        $index = BTreeIndex::fromGenerator($this->indexPath(), function() {
            yield ['zebra', 1];
            yield ['apple', 2];
            yield ['mango', 3];
            yield ['banana', 4];
        });

        // Should be sorted in output
        $results = [];
        foreach ($index->range() as $rowId) {
            $results[] = $rowId;
        }
        // Sorted by key: apple(2), banana(4), mango(3), zebra(1)
        $this->assertSame([2, 4, 3, 1], $results);

        $index->close();
    }

    public function testCompactOnEmptyIndex(): void
    {
        $path = $this->indexPath();
        $index = new BTreeIndex($path);

        // Compact empty index - should not crash
        $index->compact();

        $this->assertSame([], iterator_to_array($index->range()));

        $index->close();
    }

    public function testCompactAfterDeletingEverything(): void
    {
        $path = $this->indexPath();
        $index = new BTreeIndex($path);

        $index->begin();
        for ($i = 0; $i < 100; $i++) {
            $index->insert(sprintf('%02d', $i), $i);
        }
        $index->commit();

        // Delete everything
        for ($i = 0; $i < 100; $i++) {
            $index->delete(sprintf('%02d', $i), $i);
        }

        $sizeBefore = filesize($path);

        // Compact
        $index->compact();

        $sizeAfter = filesize($path);

        // Should shrink (sizeAfter <= sizeBefore)
        $this->assertLessThanOrEqual($sizeBefore, $sizeAfter);

        // Should be empty
        $this->assertSame([], iterator_to_array($index->range()));

        $index->close();
    }

    public function testVeryLargeKey(): void
    {
        $index = new BTreeIndex($this->indexPath());

        // Key that's large but fits in a page (< 4096 - header overhead)
        $largeKey = str_repeat('x', 2000);

        $index->insert($largeKey, 1);
        $index->insert('small', 2);

        $this->assertSame([1], iterator_to_array($index->eq($largeKey)));
        $this->assertSame([2], iterator_to_array($index->eq('small')));

        $index->close();
    }

    public function testManyLargeKeys(): void
    {
        $index = new BTreeIndex($this->indexPath());

        // Insert many large keys to force page splits
        $index->begin();
        for ($i = 0; $i < 50; $i++) {
            $key = sprintf('%03d', $i) . str_repeat('x', 500);
            $index->insert($key, $i);
        }
        $index->commit();

        // Verify all present
        for ($i = 0; $i < 50; $i++) {
            $key = sprintf('%03d', $i) . str_repeat('x', 500);
            $this->assertSame([$i], iterator_to_array($index->eq($key)));
        }

        // Range scan
        $results = iterator_to_array($index->range());
        $this->assertSame(range(0, 49), $results);

        $index->close();
    }

    public function testStressRandomOperations(): void
    {
        $index = new BTreeIndex($this->indexPath());

        $state = []; // key => [rowIds]
        $operations = 1000;

        // Seed for reproducibility
        srand(12345);

        // Use transaction to batch operations
        $index->begin();
        for ($i = 0; $i < $operations; $i++) {
            $key = sprintf('%03d', rand(0, 99));
            $rowId = $i;

            if (rand(0, 2) === 0 && isset($state[$key]) && count($state[$key]) > 0) {
                // Delete random rowId from this key (use rand() for determinism)
                $idx = rand(0, count($state[$key]) - 1);
                $delRowId = $state[$key][$idx];
                $index->delete($key, $delRowId);
                unset($state[$key][$idx]);
                $state[$key] = array_values($state[$key]);
                if (empty($state[$key])) {
                    unset($state[$key]);
                }
            } else {
                // Insert
                $index->insert($key, $rowId);
                $state[$key][] = $rowId;
            }
        }
        $index->commit();

        // Verify final state
        foreach ($state as $key => $expectedRowIds) {
            $actual = iterator_to_array($index->eq($key));
            sort($actual);
            sort($expectedRowIds);
            $this->assertSame($expectedRowIds, $actual, "Mismatch for key $key");
        }

        // Verify non-existent keys
        for ($i = 100; $i < 110; $i++) {
            $this->assertSame([], iterator_to_array($index->eq(sprintf('%03d', $i))));
        }

        $index->close();
    }

    public function testStressRandomOperationsWithTransactions(): void
    {
        $index = new BTreeIndex($this->indexPath());

        $state = [];

        // Do 5 transactions of 50 operations each with deterministic pattern
        for ($txn = 0; $txn < 5; $txn++) {
            $index->begin();

            for ($i = 0; $i < 50; $i++) {
                // Deterministic key selection
                $key = sprintf('%02d', ($txn * 7 + $i * 3) % 30);
                $rowId = $txn * 50 + $i;

                // Delete every 4th operation if there's something to delete
                if ($i % 4 === 0 && isset($state[$key]) && count($state[$key]) > 0) {
                    // Delete first rowId for this key
                    $delRowId = $state[$key][0];
                    $index->delete($key, $delRowId);
                    array_shift($state[$key]);
                    if (empty($state[$key])) {
                        unset($state[$key]);
                    }
                } else {
                    $index->insert($key, $rowId);
                    $state[$key][] = $rowId;
                }
            }

            $index->commit();
        }

        // Verify final state
        foreach ($state as $key => $expectedRowIds) {
            $actual = iterator_to_array($index->eq($key));
            sort($actual);
            sort($expectedRowIds);
            $this->assertSame($expectedRowIds, $actual, "Mismatch for key $key");
        }

        $index->close();
    }

    public function testPersistenceAfterManyOperations(): void
    {
        $path = $this->indexPath();

        // Create index with many operations
        $index = new BTreeIndex($path);
        $index->begin();
        for ($i = 0; $i < 500; $i++) {
            $index->insert(sprintf('%03d', $i), $i);
        }
        $index->commit();

        // Delete some
        for ($i = 0; $i < 500; $i += 3) {
            $index->delete(sprintf('%03d', $i), $i);
        }

        // Add more
        $index->begin();
        for ($i = 500; $i < 600; $i++) {
            $index->insert(sprintf('%03d', $i), $i);
        }
        $index->commit();

        $index->close();

        // Reopen and verify
        $index = new BTreeIndex($path);

        // Check deleted items are gone
        $this->assertSame([], iterator_to_array($index->eq('000')));
        $this->assertSame([], iterator_to_array($index->eq('003')));

        // Check non-deleted items present
        $this->assertSame([1], iterator_to_array($index->eq('001')));
        $this->assertSame([2], iterator_to_array($index->eq('002')));

        // Check new items present
        $this->assertSame([500], iterator_to_array($index->eq('500')));
        $this->assertSame([599], iterator_to_array($index->eq('599')));

        $index->close();
    }

    public function testReopenManyTimes(): void
    {
        $path = $this->indexPath();

        // Open/close many times, adding data each time
        for ($round = 0; $round < 10; $round++) {
            $index = new BTreeIndex($path);
            $index->insert(sprintf('round%02d', $round), $round);
            $index->close();
        }

        // Final verification
        $index = new BTreeIndex($path);
        for ($round = 0; $round < 10; $round++) {
            $this->assertSame([$round], iterator_to_array($index->eq(sprintf('round%02d', $round))));
        }
        $this->assertSame(range(0, 9), iterator_to_array($index->range()));
        $index->close();
    }

    public function testRangeWithManyPages(): void
    {
        $index = new BTreeIndex($this->indexPath());

        // Insert enough to create many pages
        $index->begin();
        for ($i = 0; $i < 5000; $i++) {
            $index->insert(sprintf('%05d', $i), $i);
        }
        $index->commit();

        // Full range
        $results = iterator_to_array($index->range());
        $this->assertSame(range(0, 4999), $results);

        // Bounded range spanning multiple pages
        $results = iterator_to_array($index->range(start: '01000', end: '03000'));
        $this->assertSame(range(1000, 3000), $results);

        // Reverse range spanning multiple pages
        $results = iterator_to_array($index->range(start: '02000', end: '02100', reverse: true));
        $this->assertSame(range(2100, 2000, -1), $results);

        $index->close();
    }

    public function testEqWithManyPages(): void
    {
        $index = new BTreeIndex($this->indexPath());

        // Insert enough to create many pages
        $index->begin();
        for ($i = 0; $i < 5000; $i++) {
            $index->insert(sprintf('%05d', $i), $i);
        }
        $index->commit();

        // Point lookups at various positions
        $this->assertSame([0], iterator_to_array($index->eq('00000')));
        $this->assertSame([1000], iterator_to_array($index->eq('01000')));
        $this->assertSame([2500], iterator_to_array($index->eq('02500')));
        $this->assertSame([4999], iterator_to_array($index->eq('04999')));

        // Non-existent
        $this->assertSame([], iterator_to_array($index->eq('05000')));
        $this->assertSame([], iterator_to_array($index->eq('99999')));

        $index->close();
    }

    public function testCountWithManyPages(): void
    {
        $index = new BTreeIndex($this->indexPath());

        // Create high-cardinality key spanning pages
        $index->begin();
        for ($i = 0; $i < 3000; $i++) {
            $index->insert('same', $i);
        }
        // And some unique keys
        for ($i = 0; $i < 100; $i++) {
            $index->insert(sprintf('unique%03d', $i), 10000 + $i);
        }
        $index->commit();

        $this->assertSame(3000, $index->count('same'));
        $this->assertSame(1, $index->count('unique000'));
        $this->assertSame(1, $index->count('unique099'));
        $this->assertSame(0, $index->count('nonexistent'));

        $index->close();
    }

    public function testHasWithManyPages(): void
    {
        $index = new BTreeIndex($this->indexPath());

        $index->begin();
        for ($i = 0; $i < 5000; $i++) {
            $index->insert(sprintf('%05d', $i), $i);
        }
        $index->commit();

        // Existing keys
        $this->assertTrue($index->has('00000'));
        $this->assertTrue($index->has('02500'));
        $this->assertTrue($index->has('04999'));

        // Non-existing
        $this->assertFalse($index->has('05000'));
        $this->assertFalse($index->has('zzzzz'));

        $index->close();
    }

    public function testNullBytesInKeys(): void
    {
        $index = new BTreeIndex($this->indexPath());

        $key1 = "foo\x00bar";
        $key2 = "foo\x00baz";
        $key3 = "foo\x00";
        $key4 = "\x00foo";

        $index->insert($key1, 1);
        $index->insert($key2, 2);
        $index->insert($key3, 3);
        $index->insert($key4, 4);

        $this->assertSame([1], iterator_to_array($index->eq($key1)));
        $this->assertSame([2], iterator_to_array($index->eq($key2)));
        $this->assertSame([3], iterator_to_array($index->eq($key3)));
        $this->assertSame([4], iterator_to_array($index->eq($key4)));

        // Verify they're distinct
        $this->assertSame(4, count(iterator_to_array($index->range())));

        $index->close();
    }

    public function testKeyOrderingWithNullBytes(): void
    {
        $index = new BTreeIndex($this->indexPath());

        // \x00 should sort before any other character
        $index->insert("b", 1);
        $index->insert("\x00", 2);
        $index->insert("a", 3);
        $index->insert("\x00a", 4);
        $index->insert("a\x00", 5);

        // Expected order: \x00, \x00a, a, a\x00, b
        $results = iterator_to_array($index->range());
        $this->assertSame([2, 4, 3, 5, 1], $results);

        $index->close();
    }

    public function testCompactPreservesData(): void
    {
        $path = $this->indexPath();
        $index = new BTreeIndex($path);

        // Create a complex index
        $index->begin();
        for ($i = 0; $i < 1000; $i++) {
            $index->insert(sprintf('%04d', $i), $i);
            // Some keys with multiple rowIds
            if ($i % 10 === 0) {
                $index->insert(sprintf('%04d', $i), $i + 10000);
            }
        }
        $index->commit();

        // Delete some
        for ($i = 0; $i < 1000; $i += 7) {
            $index->delete(sprintf('%04d', $i), $i);
        }

        // Snapshot expected state before compact
        $expectedKeys = [];
        for ($i = 0; $i < 1000; $i++) {
            $key = sprintf('%04d', $i);
            $ids = iterator_to_array($index->eq($key));
            if (!empty($ids)) {
                $expectedKeys[$key] = $ids;
            }
        }

        // Compact
        $index->compact();

        // Verify all data preserved
        foreach ($expectedKeys as $key => $expectedIds) {
            $actual = iterator_to_array($index->eq($key));
            sort($actual);
            sort($expectedIds);
            $this->assertSame($expectedIds, $actual, "Mismatch after compact for key $key");
        }

        $index->close();
    }

    public function testFromGeneratorLargeDataset(): void
    {
        $index = BTreeIndex::fromGenerator($this->indexPath(), function() {
            for ($i = 0; $i < 10000; $i++) {
                yield [sprintf('%05d', $i), $i];
            }
        });

        // Spot check
        $this->assertSame([0], iterator_to_array($index->eq('00000')));
        $this->assertSame([5000], iterator_to_array($index->eq('05000')));
        $this->assertSame([9999], iterator_to_array($index->eq('09999')));

        // Range subset
        $results = iterator_to_array($index->range(start: '05000', end: '05010'));
        $this->assertSame(range(5000, 5010), $results);

        $index->close();
    }

    public function testFromArrayLargeDataset(): void
    {
        $data = [];
        for ($i = 0; $i < 5000; $i++) {
            $data[] = [sprintf('%04d', $i), $i];
        }

        $index = BTreeIndex::fromArray($this->indexPath(), $data);

        $this->assertSame([0], iterator_to_array($index->eq('0000')));
        $this->assertSame([4999], iterator_to_array($index->eq('4999')));
        $this->assertSame(range(0, 4999), iterator_to_array($index->range()));

        $index->close();
    }

    // =========================================================================
    // Regression tests for fixed bugs
    // =========================================================================

    /**
     * Regression test: splitOversized must keep smallest keys in $this.
     *
     * Before the fix, splitOversized yielded $this with the LARGEST keys,
     * but $this's position in the parent tree wasn't updated. This caused
     * lookups to fail for keys that were now in the wrong subtree.
     *
     * This test runs 10 transactions of 100 operations each, touching 50 keys
     * with mixed insert/delete. Before the fix, keys 00, 45-48 lost all data.
     */
    public function testLargeTransactionDataLoss(): void
    {
        $index = new BTreeIndex($this->indexPath());
        $state = [];

        for ($txn = 0; $txn < 10; $txn++) {
            $index->begin();

            for ($i = 0; $i < 100; $i++) {
                $key = sprintf('%02d', ($txn * 7 + $i * 3) % 50);
                $rowId = $txn * 100 + $i;

                if ($i % 4 === 0 && isset($state[$key]) && count($state[$key]) > 0) {
                    $delRowId = $state[$key][0];
                    $index->delete($key, $delRowId);
                    array_shift($state[$key]);
                    if (empty($state[$key])) {
                        unset($state[$key]);
                    }
                } else {
                    $index->insert($key, $rowId);
                    $state[$key][] = $rowId;
                }
            }

            $index->commit();
        }

        // Keys that were affected by the bug (now should work correctly)
        $affectedKeys = ['00', '45', '46', '47', '48'];
        foreach ($affectedKeys as $key) {
            $actual = iterator_to_array($index->eq($key));
            $expected = $state[$key] ?? [];
            sort($actual);
            sort($expected);
            $this->assertSame($expected, $actual, "Key $key data loss");
        }

        $index->close();
    }
};

$test->run();
