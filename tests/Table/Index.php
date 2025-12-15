<?php
/**
 * Test Index implementation with binary keys
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use mini\Test;
use mini\Table\Index;
use mini\Table\Index\TreapIndex;

$test = new class extends Test {

    // =========================================================================
    // Pack/Unpack helpers
    // =========================================================================

    public function testPackUnpackInt(): void
    {
        $values = [0, 1, -1, 42, -42, PHP_INT_MAX, PHP_INT_MIN, 1000000];
        foreach ($values as $v) {
            $packed = Index::packInt($v);
            $this->assertSame(8, strlen($packed), "packInt should produce 8 bytes");
            $this->assertSame($v, Index::unpackInt($packed), "unpackInt(packInt($v)) should equal $v");
        }
    }

    public function testPackUnpackFloat(): void
    {
        $values = [0.0, 1.0, -1.0, 3.14159, -273.15, 1e10, -1e10, PHP_FLOAT_MIN, PHP_FLOAT_MAX];
        foreach ($values as $v) {
            $packed = Index::packFloat($v);
            $this->assertSame(8, strlen($packed), "packFloat should produce 8 bytes");
            $this->assertSame($v, Index::unpackFloat($packed), "unpackFloat(packFloat($v)) should equal $v");
        }
    }

    public function testPackIntSortOrder(): void
    {
        $values = [-100, -10, -1, 0, 1, 10, 100];
        $packed = array_map(fn($v) => Index::packInt($v), $values);

        // Verify binary sort order matches numeric order
        $sorted = $packed;
        sort($sorted, SORT_STRING);
        $this->assertSame($packed, $sorted, "Packed ints should sort correctly");
    }

    public function testPackFloatSortOrder(): void
    {
        $values = [-100.5, -10.0, -1.5, 0.0, 1.5, 10.0, 100.5];
        $packed = array_map(fn($v) => Index::packFloat($v), $values);

        $sorted = $packed;
        sort($sorted, SORT_STRING);
        $this->assertSame($packed, $sorted, "Packed floats should sort correctly");
    }

    public function testPackStringSortOrder(): void
    {
        $values = ['apple', 'banana', 'cherry'];
        $packed = array_map(fn($v) => Index::packString($v), $values);

        $sorted = $packed;
        sort($sorted, SORT_STRING);
        $this->assertSame($packed, $sorted, "Packed strings should sort correctly");
    }

    public function testPackStringMaxLength(): void
    {
        $this->assertSame('abc', Index::packString('abcdef', 3));
        $this->assertSame('ab', Index::packString('ab', 3));
        $this->assertSame('test', Index::packString('test', null));
    }

    // =========================================================================
    // Basic operations
    // =========================================================================

    public function testInsertAndEq(): void
    {
        $index = new TreapIndex();

        $key5 = Index::packInt(5);
        $key10 = Index::packInt(10);

        $index->insert($key5, 1);
        $index->insert($key5, 2);
        $index->insert($key10, 3);

        $results = iterator_to_array($index->eq($key5));
        $this->assertSame([1, 2], $results);
    }

    public function testDelete(): void
    {
        $index = new TreapIndex();

        $key = Index::packInt(5);
        $index->insert($key, 1);
        $index->insert($key, 2);

        $index->delete($key, 1);

        $results = iterator_to_array($index->eq($key));
        $this->assertSame([2], $results);
    }

    public function testHas(): void
    {
        $index = new TreapIndex();
        $key = Index::packInt(5);

        $this->assertFalse($index->has($key));

        $index->insert($key, 1);
        $this->assertTrue($index->has($key));
    }

    public function testCount(): void
    {
        $index = new TreapIndex();
        $key = Index::packInt(5);

        $index->insert($key, 1);
        $index->insert($key, 2);

        $this->assertSame(2, $index->count($key));
        $this->assertSame(0, $index->count(Index::packInt(99)));
    }

    // =========================================================================
    // Range queries (triggers SQLite build)
    // =========================================================================

    public function testRangeFullScan(): void
    {
        $index = new TreapIndex();

        $index->insert(Index::packInt(30), 1);
        $index->insert(Index::packInt(10), 2);
        $index->insert(Index::packInt(20), 3);

        $results = iterator_to_array($index->range());

        // Should be sorted by key: 10->2, 20->3, 30->1
        $this->assertSame([2, 3, 1], $results);
    }

    public function testRangeReverse(): void
    {
        $index = new TreapIndex();

        $index->insert(Index::packInt(30), 1);
        $index->insert(Index::packInt(10), 2);
        $index->insert(Index::packInt(20), 3);

        $results = iterator_to_array($index->range(reverse: true));

        $this->assertSame([1, 3, 2], $results);
    }

    public function testRangeWithBounds(): void
    {
        $index = new TreapIndex();

        $index->insert(Index::packInt(10), 1);
        $index->insert(Index::packInt(20), 2);
        $index->insert(Index::packInt(30), 3);
        $index->insert(Index::packInt(40), 4);

        // 15 <= key <= 35
        $results = iterator_to_array($index->range(
            Index::packInt(15),
            Index::packInt(35)
        ));

        $this->assertSame([2, 3], $results);
    }

    public function testCountRange(): void
    {
        $index = new TreapIndex();

        $index->insert(Index::packInt(10), 1);
        $index->insert(Index::packInt(20), 2);
        $index->insert(Index::packInt(30), 3);
        $index->insert(Index::packInt(40), 4);

        $this->assertSame(4, $index->countRange());
        $this->assertSame(2, $index->countRange(Index::packInt(25)));
        $this->assertSame(2, $index->countRange(null, Index::packInt(25)));
        $this->assertSame(2, $index->countRange(Index::packInt(15), Index::packInt(35)));
    }

    // =========================================================================
    // Numeric sorting
    // =========================================================================

    public function testNegativeNumbersSortCorrectly(): void
    {
        $index = new TreapIndex();

        $index->insert(Index::packInt(-10), 1);
        $index->insert(Index::packInt(0), 2);
        $index->insert(Index::packInt(10), 3);
        $index->insert(Index::packInt(-5), 4);

        $results = iterator_to_array($index->range());

        // -10->1, -5->4, 0->2, 10->3
        $this->assertSame([1, 4, 2, 3], $results);
    }

    public function testFloatsSortCorrectly(): void
    {
        $index = new TreapIndex();

        $index->insert(Index::packFloat(1.5), 1);
        $index->insert(Index::packFloat(1.1), 2);
        $index->insert(Index::packFloat(2.0), 3);
        $index->insert(Index::packFloat(-0.5), 4);

        $results = iterator_to_array($index->range());

        // -0.5->4, 1.1->2, 1.5->1, 2.0->3
        $this->assertSame([4, 2, 1, 3], $results);
    }

    // =========================================================================
    // String keys
    // =========================================================================

    public function testStringKeysSortLexicographically(): void
    {
        $index = new TreapIndex();

        $index->insert(Index::packString('banana'), 1);
        $index->insert(Index::packString('apple'), 2);
        $index->insert(Index::packString('cherry'), 3);

        $results = iterator_to_array($index->range());

        // apple->2, banana->1, cherry->3
        $this->assertSame([2, 1, 3], $results);
    }

    // =========================================================================
    // Integer rowIds
    // =========================================================================

    public function testIntegerRowIds(): void
    {
        $index = new TreapIndex();
        $key = Index::packString('key');

        $index->insert($key, 1);
        $index->insert($key, 2);

        $results = iterator_to_array($index->eq($key));
        $this->assertSame([1, 2], $results);
    }

    public function testRowIdsPreservedThroughRange(): void
    {
        $index = new TreapIndex();
        $key = Index::packInt(1);

        $index->insert($key, 42);
        $index->insert($key, 100);
        $index->insert($key, 999);

        // Trigger range to test SQLite round-trip
        $results = iterator_to_array($index->range());

        $this->assertSame([42, 100, 999], $results);
    }

    // =========================================================================
    // Utility methods
    // =========================================================================

    public function testClear(): void
    {
        $index = new TreapIndex();

        $index->insert(Index::packInt(1), 1);
        $index->insert(Index::packInt(2), 2);

        $index->clear();

        $this->assertSame(0, $index->rowCount());
    }

    public function testKeyCount(): void
    {
        $index = new TreapIndex();

        $index->insert(Index::packInt(1), 1);
        $index->insert(Index::packInt(1), 2);
        $index->insert(Index::packInt(2), 3);

        $this->assertSame(2, $index->keyCount());
    }

    public function testRowCount(): void
    {
        $index = new TreapIndex();

        $index->insert(Index::packInt(1), 1);
        $index->insert(Index::packInt(1), 2);
        $index->insert(Index::packInt(2), 3);

        $this->assertSame(3, $index->rowCount());
    }

    // =========================================================================
    // Insert/delete after range (SQLite sync)
    // =========================================================================

    public function testInsertAfterRangeUpdatesIndex(): void
    {
        $index = new TreapIndex();

        $index->insert(Index::packInt(10), 1);
        $index->insert(Index::packInt(30), 3);

        // Trigger SQLite build
        iterator_to_array($index->range());

        // Insert new row
        $index->insert(Index::packInt(20), 2);

        // Should appear in range results
        $results = iterator_to_array($index->range());
        $this->assertSame([1, 2, 3], $results);
    }

    public function testDeleteAfterRangeUpdatesIndex(): void
    {
        $index = new TreapIndex();

        $index->insert(Index::packInt(10), 1);
        $index->insert(Index::packInt(20), 2);
        $index->insert(Index::packInt(30), 3);

        // Trigger SQLite build
        iterator_to_array($index->range());

        // Delete middle row
        $index->delete(Index::packInt(20), 2);

        $results = iterator_to_array($index->range());
        $this->assertSame([1, 3], $results);
    }
};

$test->run();
