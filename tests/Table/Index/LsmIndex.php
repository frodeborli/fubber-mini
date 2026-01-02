<?php
/**
 * Test LsmIndex implementation
 */

require __DIR__ . '/../../../ensure-autoloader.php';

use mini\Test;
use mini\Table\Index\LsmIndex;

$test = new class extends Test {

    // =========================================================================
    // Basic operations
    // =========================================================================

    public function testInsertAndEq(): void
    {
        $index = new LsmIndex();

        $index->insert('a', 1);
        $index->insert('a', 2);
        $index->insert('b', 3);

        $this->assertSame([1, 2], iterator_to_array($index->eq('a')));
        $this->assertSame([3], iterator_to_array($index->eq('b')));
    }

    public function testEqNoMatch(): void
    {
        $index = new LsmIndex();
        $index->insert('a', 1);

        $this->assertSame([], iterator_to_array($index->eq('nonexistent')));
    }

    public function testHas(): void
    {
        $index = new LsmIndex();

        $this->assertFalse($index->has('a'));

        $index->insert('a', 1);
        $this->assertTrue($index->has('a'));
    }

    public function testCount(): void
    {
        $index = new LsmIndex();

        $this->assertSame(0, count($index));

        $index->insert('a', 1);
        $index->insert('a', 2);
        $index->insert('b', 3);

        $this->assertSame(2, count($index)); // 2 unique keys
    }

    // =========================================================================
    // Delete
    // =========================================================================

    public function testDelete(): void
    {
        $index = new LsmIndex();

        $index->insert('a', 1);
        $index->insert('a', 2);
        $index->insert('a', 3);

        $index->delete('a', 2);

        $this->assertSame([1, 3], iterator_to_array($index->eq('a')));
    }

    public function testDeleteLastRowIdRemovesKey(): void
    {
        $index = new LsmIndex();

        $index->insert('a', 1);
        $index->delete('a', 1);

        $this->assertFalse($index->has('a'));
        $this->assertSame([], iterator_to_array($index->eq('a')));
    }

    public function testDeleteNonexistent(): void
    {
        $index = new LsmIndex();

        $index->insert('a', 1);
        $index->delete('a', 99); // rowId doesn't exist
        $index->delete('b', 1);  // key doesn't exist

        $this->assertSame([1], iterator_to_array($index->eq('a')));
    }

    // =========================================================================
    // Range queries
    // =========================================================================

    public function testRangeFullScan(): void
    {
        $index = new LsmIndex();

        $index->insert('c', 3);
        $index->insert('a', 1);
        $index->insert('b', 2);

        $results = iterator_to_array($index->range());
        $this->assertSame([1, 2, 3], $results);
    }

    public function testRangeReverse(): void
    {
        $index = new LsmIndex();

        $index->insert('a', 1);
        $index->insert('b', 2);
        $index->insert('c', 3);

        $results = iterator_to_array($index->range(reverse: true));
        $this->assertSame([3, 2, 1], $results);
    }

    public function testRangeWithBounds(): void
    {
        $index = new LsmIndex();

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
        $index = new LsmIndex();

        $index->insert('a', 1);
        $index->insert('b', 2);
        $index->insert('c', 3);

        $results = iterator_to_array($index->range(start: 'b'));
        $this->assertSame([2, 3], $results);
    }

    public function testRangeWithEndOnly(): void
    {
        $index = new LsmIndex();

        $index->insert('a', 1);
        $index->insert('b', 2);
        $index->insert('c', 3);

        $results = iterator_to_array($index->range(end: 'b'));
        $this->assertSame([1, 2], $results);
    }

    public function testRangeReverseWithBounds(): void
    {
        $index = new LsmIndex();

        $index->insert('a', 1);
        $index->insert('b', 2);
        $index->insert('c', 3);
        $index->insert('d', 4);

        $results = iterator_to_array($index->range(start: 'b', end: 'c', reverse: true));
        $this->assertSame([3, 2], $results);
    }

    public function testMultipleRowIdsPerKey(): void
    {
        $index = new LsmIndex();

        $index->insert('a', 1);
        $index->insert('a', 2);
        $index->insert('b', 3);
        $index->insert('b', 4);

        $results = iterator_to_array($index->range());
        $this->assertSame([1, 2, 3, 4], $results);
    }

    public function testMultipleRowIdsPerKeyReverse(): void
    {
        $index = new LsmIndex();

        $index->insert('a', 1);
        $index->insert('a', 2);
        $index->insert('b', 3);
        $index->insert('b', 4);

        $results = iterator_to_array($index->range(reverse: true));
        $this->assertSame([4, 3, 2, 1], $results);
    }

    // =========================================================================
    // Inner layer / LSM structure tests
    // =========================================================================

    public function testInnerLayerCreation(): void
    {
        // Use small threshold to force inner layer
        $index = new LsmIndex(minimumSizeBeforeDelegation: 3);

        // First 3 keys go to outer layer
        $index->insert('a', 1);
        $index->insert('b', 2);
        $index->insert('c', 3);

        // 4th key should trigger inner layer
        $index->insert('d', 4);

        $this->assertSame(4, count($index));
        $results = iterator_to_array($index->range());
        $this->assertSame([1, 2, 3, 4], $results);
    }

    public function testMergeDuringRange(): void
    {
        // Small threshold to force layers
        $index = new LsmIndex(minimumSizeBeforeDelegation: 3);

        // Fill outer layer
        $index->insert('b', 2);
        $index->insert('d', 4);
        $index->insert('f', 6);

        // These go to inner layer
        $index->insert('a', 1);
        $index->insert('c', 3);
        $index->insert('e', 5);

        // Range should merge correctly
        $results = iterator_to_array($index->range());
        $this->assertSame([1, 2, 3, 4, 5, 6], $results);
    }

    public function testMergeDuringRangeReverse(): void
    {
        $index = new LsmIndex(minimumSizeBeforeDelegation: 3);

        $index->insert('b', 2);
        $index->insert('d', 4);
        $index->insert('f', 6);

        $index->insert('a', 1);
        $index->insert('c', 3);
        $index->insert('e', 5);

        $results = iterator_to_array($index->range(reverse: true));
        $this->assertSame([6, 5, 4, 3, 2, 1], $results);
    }

    public function testRangeQueryWhileInserting(): void
    {
        $index = new LsmIndex(minimumSizeBeforeDelegation: 5);

        // Insert some keys
        for ($i = 0; $i < 5; $i++) {
            $index->insert(sprintf('%02d', $i * 2), $i * 2); // 00, 02, 04, 06, 08
        }

        // Query mid-insert to freeze outer layer state
        $midResults = iterator_to_array($index->range(start: '02', end: '06'));
        $this->assertSame([2, 4, 6], $midResults);

        // Insert more (goes to inner layer)
        for ($i = 0; $i < 5; $i++) {
            $index->insert(sprintf('%02d', $i * 2 + 1), $i * 2 + 1); // 01, 03, 05, 07, 09
        }

        // Full range should merge both layers
        $results = iterator_to_array($index->range());
        $this->assertSame([0, 1, 2, 3, 4, 5, 6, 7, 8, 9], $results);
    }

    public function testDeleteFromOuterLayer(): void
    {
        $index = new LsmIndex(minimumSizeBeforeDelegation: 3);

        // Outer layer
        $index->insert('a', 1);
        $index->insert('b', 2);
        $index->insert('c', 3);

        // Inner layer
        $index->insert('d', 4);
        $index->insert('e', 5);

        // Delete from outer
        $index->delete('b', 2);

        $results = iterator_to_array($index->range());
        $this->assertSame([1, 3, 4, 5], $results);
    }

    public function testDeleteFromInnerLayer(): void
    {
        $index = new LsmIndex(minimumSizeBeforeDelegation: 3);

        // Outer layer
        $index->insert('a', 1);
        $index->insert('b', 2);
        $index->insert('c', 3);

        // Inner layer
        $index->insert('d', 4);
        $index->insert('e', 5);

        // Delete from inner
        $index->delete('d', 4);

        $results = iterator_to_array($index->range());
        $this->assertSame([1, 2, 3, 5], $results);
    }

    public function testHasWithInnerLayer(): void
    {
        $index = new LsmIndex(minimumSizeBeforeDelegation: 2);

        $index->insert('a', 1);
        $index->insert('b', 2);
        $index->insert('c', 3); // Goes to inner

        $this->assertTrue($index->has('a')); // outer
        $this->assertTrue($index->has('c')); // inner
        $this->assertFalse($index->has('z'));
    }

    public function testEqWithInnerLayer(): void
    {
        $index = new LsmIndex(minimumSizeBeforeDelegation: 2);

        $index->insert('a', 1);
        $index->insert('b', 2);
        $index->insert('c', 3); // Goes to inner

        $this->assertSame([1], iterator_to_array($index->eq('a')));
        $this->assertSame([3], iterator_to_array($index->eq('c')));
    }

    public function testAppendToExistingKeyStaysInOuter(): void
    {
        $index = new LsmIndex(minimumSizeBeforeDelegation: 2);

        $index->insert('a', 1);
        $index->insert('b', 2);
        $index->insert('c', 3); // Goes to inner

        // Append to existing key in outer - should stay in outer
        $index->insert('a', 10);

        $this->assertSame([1, 10], iterator_to_array($index->eq('a')));

        // Full range should work
        $results = iterator_to_array($index->range());
        $this->assertSame([1, 10, 2, 3], $results);
    }

    // =========================================================================
    // Deeply nested layers
    // =========================================================================

    public function testMultipleLayers(): void
    {
        // Very small threshold to force multiple nesting levels
        $index = new LsmIndex(minimumSizeBeforeDelegation: 2);

        // This should create multiple layers: outer gets 2, then inner gets 2, then inner-inner, etc.
        for ($i = 0; $i < 10; $i++) {
            $index->insert(sprintf('%02d', $i), $i);
        }

        $this->assertSame(10, count($index));

        $results = iterator_to_array($index->range());
        $this->assertSame([0, 1, 2, 3, 4, 5, 6, 7, 8, 9], $results);

        $resultsRev = iterator_to_array($index->range(reverse: true));
        $this->assertSame([9, 8, 7, 6, 5, 4, 3, 2, 1, 0], $resultsRev);
    }

    public function testMultipleLayersWithBoundedRange(): void
    {
        $index = new LsmIndex(minimumSizeBeforeDelegation: 2);

        for ($i = 0; $i < 10; $i++) {
            $index->insert(sprintf('%02d', $i), $i);
        }

        $results = iterator_to_array($index->range(start: '03', end: '07'));
        $this->assertSame([3, 4, 5, 6, 7], $results);
    }

    public function testDeleteAcrossLayers(): void
    {
        $index = new LsmIndex(minimumSizeBeforeDelegation: 2);

        for ($i = 0; $i < 8; $i++) {
            $index->insert(sprintf('%02d', $i), $i);
        }

        // Delete from various layers
        $index->delete('00', 0);
        $index->delete('03', 3);
        $index->delete('07', 7);

        $results = iterator_to_array($index->range());
        $this->assertSame([1, 2, 4, 5, 6], $results);
    }

    // =========================================================================
    // Edge cases
    // =========================================================================

    public function testEmptyIndex(): void
    {
        $index = new LsmIndex();

        $this->assertSame(0, count($index));
        $this->assertSame([], iterator_to_array($index->range()));
        $this->assertSame([], iterator_to_array($index->eq('any')));
        $this->assertFalse($index->has('any'));
    }

    public function testRangeOnEmptyIndex(): void
    {
        $index = new LsmIndex();

        $this->assertSame([], iterator_to_array($index->range(start: 'a', end: 'z')));
        $this->assertSame([], iterator_to_array($index->range(reverse: true)));
    }

    public function testSingleElement(): void
    {
        $index = new LsmIndex();

        $index->insert('only', 42);

        $this->assertSame([42], iterator_to_array($index->range()));
        $this->assertSame([42], iterator_to_array($index->range(reverse: true)));
        $this->assertSame([42], iterator_to_array($index->eq('only')));
    }

    public function testRangeOutsideData(): void
    {
        $index = new LsmIndex();

        $index->insert('m', 1);

        // Range entirely before data
        $this->assertSame([], iterator_to_array($index->range(start: 'a', end: 'l')));

        // Range entirely after data
        $this->assertSame([], iterator_to_array($index->range(start: 'n', end: 'z')));
    }
};

$test->run();
