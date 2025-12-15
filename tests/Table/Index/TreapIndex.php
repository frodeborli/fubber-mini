<?php
/**
 * Test TreapIndex implementation
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

use mini\Test;
use mini\Table\Index\TreapIndex;

$test = new class extends Test {

    // =========================================================================
    // Basic operations (hash mode)
    // =========================================================================

    public function testInsertAndEq(): void
    {
        $index = new TreapIndex();

        $index->insert('a', 1);
        $index->insert('a', 2);
        $index->insert('b', 3);

        $this->assertSame([1, 2], iterator_to_array($index->eq('a')));
        $this->assertSame([3], iterator_to_array($index->eq('b')));
    }

    public function testEqNoMatch(): void
    {
        $index = new TreapIndex();
        $index->insert('a', 1);

        $this->assertSame([], iterator_to_array($index->eq('nonexistent')));
    }

    public function testHas(): void
    {
        $index = new TreapIndex();

        $this->assertFalse($index->has('a'));

        $index->insert('a', 1);
        $this->assertTrue($index->has('a'));
    }

    public function testCount(): void
    {
        $index = new TreapIndex();

        $this->assertSame(0, $index->keyCount());

        $index->insert('a', 1);
        $index->insert('a', 2);
        $index->insert('b', 3);

        $this->assertSame(2, $index->keyCount()); // 2 unique keys
    }

    // =========================================================================
    // Delete (hash mode)
    // =========================================================================

    public function testDelete(): void
    {
        $index = new TreapIndex();

        $index->insert('a', 1);
        $index->insert('a', 2);
        $index->insert('a', 3);

        $index->delete('a', 2);

        $this->assertSame([1, 3], iterator_to_array($index->eq('a')));
    }

    public function testDeleteLastRowIdRemovesKey(): void
    {
        $index = new TreapIndex();

        $index->insert('a', 1);
        $index->delete('a', 1);

        $this->assertFalse($index->has('a'));
        $this->assertSame([], iterator_to_array($index->eq('a')));
    }

    public function testDeleteNonexistent(): void
    {
        $index = new TreapIndex();

        $index->insert('a', 1);
        $index->delete('a', 99); // rowId doesn't exist
        $index->delete('b', 1);  // key doesn't exist

        $this->assertSame([1], iterator_to_array($index->eq('a')));
    }

    // =========================================================================
    // Range queries (triggers treap migration)
    // =========================================================================

    public function testRangeFullScan(): void
    {
        $index = new TreapIndex();

        $index->insert('c', 3);
        $index->insert('a', 1);
        $index->insert('b', 2);

        $results = iterator_to_array($index->range());
        $this->assertSame([1, 2, 3], $results);
    }

    public function testRangeReverse(): void
    {
        $index = new TreapIndex();

        $index->insert('a', 1);
        $index->insert('b', 2);
        $index->insert('c', 3);

        $results = iterator_to_array($index->range(reverse: true));
        $this->assertSame([3, 2, 1], $results);
    }

    public function testRangeWithBounds(): void
    {
        $index = new TreapIndex();

        $index->insert('a', 1);
        $index->insert('b', 2);
        $index->insert('c', 3);
        $index->insert('d', 4);

        // b <= key <= c
        $results = iterator_to_array($index->range(start: 'b', end: 'c'));
        $this->assertSame([2, 3], $results);
    }

    public function testRangeWithStartOnly(): void
    {
        $index = new TreapIndex();

        $index->insert('a', 1);
        $index->insert('b', 2);
        $index->insert('c', 3);

        $results = iterator_to_array($index->range(start: 'b'));
        $this->assertSame([2, 3], $results);
    }

    public function testRangeWithEndOnly(): void
    {
        $index = new TreapIndex();

        $index->insert('a', 1);
        $index->insert('b', 2);
        $index->insert('c', 3);

        $results = iterator_to_array($index->range(end: 'b'));
        $this->assertSame([1, 2], $results);
    }

    public function testRangeReverseWithBounds(): void
    {
        $index = new TreapIndex();

        $index->insert('a', 1);
        $index->insert('b', 2);
        $index->insert('c', 3);
        $index->insert('d', 4);

        $results = iterator_to_array($index->range(start: 'b', end: 'c', reverse: true));
        $this->assertSame([3, 2], $results);
    }

    public function testMultipleRowIdsPerKey(): void
    {
        $index = new TreapIndex();

        $index->insert('a', 1);
        $index->insert('a', 2);
        $index->insert('b', 3);
        $index->insert('b', 4);

        $results = iterator_to_array($index->range());
        $this->assertSame([1, 2, 3, 4], $results);
    }

    public function testMultipleRowIdsPerKeyReverse(): void
    {
        $index = new TreapIndex();

        $index->insert('a', 1);
        $index->insert('a', 2);
        $index->insert('b', 3);
        $index->insert('b', 4);

        $results = iterator_to_array($index->range(reverse: true));
        $this->assertSame([4, 3, 2, 1], $results);
    }

    // =========================================================================
    // Operations after migration to treap
    // =========================================================================

    public function testInsertAfterRange(): void
    {
        $index = new TreapIndex();

        $index->insert('a', 1);
        $index->insert('c', 3);

        // Trigger migration
        iterator_to_array($index->range());

        // Insert in treap mode
        $index->insert('b', 2);

        $results = iterator_to_array($index->range());
        $this->assertSame([1, 2, 3], $results);
    }

    public function testDeleteAfterRange(): void
    {
        $index = new TreapIndex();

        $index->insert('a', 1);
        $index->insert('b', 2);
        $index->insert('c', 3);

        // Trigger migration
        iterator_to_array($index->range());

        // Delete in treap mode
        $index->delete('b', 2);

        $results = iterator_to_array($index->range());
        $this->assertSame([1, 3], $results);
    }

    public function testHasAfterRange(): void
    {
        $index = new TreapIndex();

        $index->insert('a', 1);
        $index->insert('b', 2);

        // Trigger migration
        iterator_to_array($index->range());

        $this->assertTrue($index->has('a'));
        $this->assertTrue($index->has('b'));
        $this->assertFalse($index->has('c'));
    }

    public function testEqAfterRange(): void
    {
        $index = new TreapIndex();

        $index->insert('a', 1);
        $index->insert('a', 2);
        $index->insert('b', 3);

        // Trigger migration
        iterator_to_array($index->range());

        $this->assertSame([1, 2], iterator_to_array($index->eq('a')));
        $this->assertSame([3], iterator_to_array($index->eq('b')));
        $this->assertSame([], iterator_to_array($index->eq('c')));
    }

    public function testAppendToExistingKeyAfterRange(): void
    {
        $index = new TreapIndex();

        $index->insert('a', 1);
        $index->insert('b', 2);

        // Trigger migration
        iterator_to_array($index->range());

        // Append in treap mode
        $index->insert('a', 10);

        $this->assertSame([1, 10], iterator_to_array($index->eq('a')));

        $results = iterator_to_array($index->range());
        $this->assertSame([1, 10, 2], $results);
    }

    // =========================================================================
    // Larger data sets
    // =========================================================================

    public function testManyKeys(): void
    {
        $index = new TreapIndex();

        for ($i = 0; $i < 100; $i++) {
            $index->insert(sprintf('%03d', $i), $i);
        }

        $this->assertSame(100, $index->keyCount());

        $results = iterator_to_array($index->range());
        $expected = range(0, 99);
        $this->assertSame($expected, $results);
    }

    public function testManyKeysReverse(): void
    {
        $index = new TreapIndex();

        for ($i = 0; $i < 50; $i++) {
            $index->insert(sprintf('%02d', $i), $i);
        }

        $results = iterator_to_array($index->range(reverse: true));
        $expected = range(49, 0, -1);
        $this->assertSame($expected, $results);
    }

    public function testManyKeysWithBoundedRange(): void
    {
        $index = new TreapIndex();

        for ($i = 0; $i < 100; $i++) {
            $index->insert(sprintf('%03d', $i), $i);
        }

        $results = iterator_to_array($index->range(start: '025', end: '075'));
        $expected = range(25, 75);
        $this->assertSame($expected, $results);
    }

    public function testDeleteMultiple(): void
    {
        $index = new TreapIndex();

        for ($i = 0; $i < 20; $i++) {
            $index->insert(sprintf('%02d', $i), $i);
        }

        // Trigger migration
        iterator_to_array($index->range());

        // Delete several
        $index->delete('05', 5);
        $index->delete('10', 10);
        $index->delete('15', 15);

        $results = iterator_to_array($index->range());
        $expected = [0, 1, 2, 3, 4, 6, 7, 8, 9, 11, 12, 13, 14, 16, 17, 18, 19];
        $this->assertSame($expected, $results);
    }

    // =========================================================================
    // Edge cases
    // =========================================================================

    public function testEmptyIndex(): void
    {
        $index = new TreapIndex();

        $this->assertSame(0, $index->keyCount());
        $this->assertSame([], iterator_to_array($index->range()));
        $this->assertSame([], iterator_to_array($index->eq('any')));
        $this->assertFalse($index->has('any'));
    }

    public function testRangeOnEmptyIndex(): void
    {
        $index = new TreapIndex();

        $this->assertSame([], iterator_to_array($index->range(start: 'a', end: 'z')));
        $this->assertSame([], iterator_to_array($index->range(reverse: true)));
    }

    public function testSingleElement(): void
    {
        $index = new TreapIndex();

        $index->insert('only', 42);

        $this->assertSame([42], iterator_to_array($index->range()));
        $this->assertSame([42], iterator_to_array($index->range(reverse: true)));
        $this->assertSame([42], iterator_to_array($index->eq('only')));
    }

    public function testRangeOutsideData(): void
    {
        $index = new TreapIndex();

        $index->insert('m', 1);

        // Range entirely before data
        $this->assertSame([], iterator_to_array($index->range(start: 'a', end: 'l')));

        // Range entirely after data
        $this->assertSame([], iterator_to_array($index->range(start: 'n', end: 'z')));
    }

    public function testCountAfterMigration(): void
    {
        $index = new TreapIndex();

        $index->insert('a', 1);
        $index->insert('b', 2);
        $index->insert('c', 3);

        $this->assertSame(3, $index->keyCount());

        // Trigger migration
        iterator_to_array($index->range());

        $this->assertSame(3, $index->keyCount());

        // Insert and delete in treap mode
        $index->insert('d', 4);
        $this->assertSame(4, $index->keyCount());

        $index->delete('a', 1);
        $this->assertSame(3, $index->keyCount());
    }
};

$test->run();
