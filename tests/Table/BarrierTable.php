<?php
/**
 * Test BarrierTable implementation
 *
 * BarrierTable freezes a paged result set so subsequent operations
 * filter the frozen rows instead of modifying the underlying query.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use mini\Test;
use mini\Table\GeneratorTable;
use mini\Table\Wrappers\BarrierTable;
use mini\Table\Utility\Set;
use mini\Table\ColumnDef;
use mini\Table\Types\ColumnType;
use mini\Table\Types\IndexType;

$test = new class extends Test {

    /**
     * Create a test table with 10 rows for pagination testing
     */
    protected function createTable(): GeneratorTable
    {
        return new GeneratorTable(
            fn() => yield from [
                1  => (object)['id' => 1,  'name' => 'Alice',   'age' => 20, 'dept' => 'Engineering'],
                2  => (object)['id' => 2,  'name' => 'Bob',     'age' => 25, 'dept' => 'Sales'],
                3  => (object)['id' => 3,  'name' => 'Carol',   'age' => 30, 'dept' => 'Engineering'],
                4  => (object)['id' => 4,  'name' => 'Dave',    'age' => 35, 'dept' => 'Sales'],
                5  => (object)['id' => 5,  'name' => 'Eve',     'age' => 40, 'dept' => 'Marketing'],
                6  => (object)['id' => 6,  'name' => 'Frank',   'age' => 45, 'dept' => 'Engineering'],
                7  => (object)['id' => 7,  'name' => 'Grace',   'age' => 50, 'dept' => 'Sales'],
                8  => (object)['id' => 8,  'name' => 'Henry',   'age' => 55, 'dept' => 'Marketing'],
                9  => (object)['id' => 9,  'name' => 'Ivy',     'age' => 60, 'dept' => 'Engineering'],
                10 => (object)['id' => 10, 'name' => 'Jack',    'age' => 65, 'dept' => 'Sales'],
            ],
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('name', ColumnType::Text),
            new ColumnDef('age', ColumnType::Int),
            new ColumnDef('dept', ColumnType::Text),
        );
    }

    // =========================================================================
    // from() factory method behavior
    // =========================================================================

    public function testFromReturnsAsIsWhenNoLimitOrOffset(): void
    {
        $table = $this->createTable();
        $result = BarrierTable::from($table);

        // Should return the same table, not wrapped
        $this->assertSame($table, $result);
    }

    public function testFromWrapsWhenHasLimit(): void
    {
        $table = $this->createTable()->limit(5);
        $result = BarrierTable::from($table);

        // Should be a different object (wrapped)
        $this->assertTrue($result !== $table);
    }

    public function testFromWrapsWhenHasOffset(): void
    {
        $table = $this->createTable()->offset(3);
        $result = BarrierTable::from($table);

        // Should be a different object (wrapped)
        $this->assertTrue($result !== $table);
    }

    public function testFromWrapsWhenHasBothLimitAndOffset(): void
    {
        $table = $this->createTable()->limit(5)->offset(2);
        $result = BarrierTable::from($table);

        $this->assertTrue($result !== $table);
    }

    // =========================================================================
    // getLimit/getOffset after freezing
    // =========================================================================

    public function testFrozenTableReportsNullLimit(): void
    {
        $table = $this->createTable()->limit(5);
        $frozen = BarrierTable::from($table);

        $this->assertNull($frozen->getLimit());
    }

    public function testFrozenTableReportsZeroOffset(): void
    {
        $table = $this->createTable()->offset(3);
        $frozen = BarrierTable::from($table);

        $this->assertSame(0, $frozen->getOffset());
    }

    // =========================================================================
    // Core behavior: filters operate on frozen rows
    // =========================================================================

    public function testFilterOnFrozenTableFiltersPagedRows(): void
    {
        // Get first 5 rows (ids 1-5), then filter to Engineering
        $paged = $this->createTable()->limit(5);
        $frozen = BarrierTable::from($paged);
        $filtered = $frozen->eq('dept', 'Engineering');

        $ids = [];
        foreach ($filtered as $row) {
            $ids[] = $row->id;
        }

        // Only Engineering from first 5: Alice(1) and Carol(3)
        $this->assertSame([1, 3], $ids);
    }

    public function testFilterWithoutFreezingPushesDown(): void
    {
        // Without freezing: filter pushes down, then limit applies
        // This returns the first 5 Engineering employees (ids: 1, 3, 6, 9)
        $result = $this->createTable()->eq('dept', 'Engineering')->limit(5);

        $ids = [];
        foreach ($result as $row) {
            $ids[] = $row->id;
        }

        // All 4 Engineering employees (limit 5 doesn't cut anything)
        $this->assertSame([1, 3, 6, 9], $ids);
    }

    public function testFreezePreventsPushdownOnSubsequentFilters(): void
    {
        // Limit 5, freeze, then filter - should filter the 5 rows
        $paged = $this->createTable()->limit(5);
        $frozen = BarrierTable::from($paged);
        $filtered = $frozen->eq('dept', 'Sales');

        $ids = [];
        foreach ($filtered as $row) {
            $ids[] = $row->id;
        }

        // Only Sales from first 5: Bob(2) and Dave(4)
        $this->assertSame([2, 4], $ids);
    }

    // =========================================================================
    // Filter methods work correctly after freezing
    // =========================================================================

    public function testEqOnFrozenTable(): void
    {
        $frozen = BarrierTable::from($this->createTable()->limit(6));
        $result = $frozen->eq('name', 'Carol');

        $ids = [];
        foreach ($result as $row) {
            $ids[] = $row->id;
        }

        $this->assertSame([3], $ids);
    }

    public function testLtOnFrozenTable(): void
    {
        $frozen = BarrierTable::from($this->createTable()->limit(6));
        $result = $frozen->lt('age', 30);

        $ids = [];
        foreach ($result as $row) {
            $ids[] = $row->id;
        }

        // From first 6 (ages 20,25,30,35,40,45): age < 30 => Alice(20), Bob(25)
        $this->assertSame([1, 2], $ids);
    }

    public function testGtOnFrozenTable(): void
    {
        $frozen = BarrierTable::from($this->createTable()->limit(6));
        $result = $frozen->gt('age', 35);

        $ids = [];
        foreach ($result as $row) {
            $ids[] = $row->id;
        }

        // From first 6 (ages 20,25,30,35,40,45): age > 35 => Eve(40), Frank(45)
        $this->assertSame([5, 6], $ids);
    }

    public function testLteOnFrozenTable(): void
    {
        $frozen = BarrierTable::from($this->createTable()->limit(6));
        $result = $frozen->lte('age', 30);

        $ids = [];
        foreach ($result as $row) {
            $ids[] = $row->id;
        }

        // age <= 30 => Alice(20), Bob(25), Carol(30)
        $this->assertSame([1, 2, 3], $ids);
    }

    public function testGteOnFrozenTable(): void
    {
        $frozen = BarrierTable::from($this->createTable()->limit(6));
        $result = $frozen->gte('age', 40);

        $ids = [];
        foreach ($result as $row) {
            $ids[] = $row->id;
        }

        // age >= 40 => Eve(40), Frank(45)
        $this->assertSame([5, 6], $ids);
    }

    public function testInOnFrozenTable(): void
    {
        $frozen = BarrierTable::from($this->createTable()->limit(6));
        $result = $frozen->in('id', new Set('id', [2, 4, 8]));

        $ids = [];
        foreach ($result as $row) {
            $ids[] = $row->id;
        }

        // From first 6, only 2 and 4 are in the set (8 is outside limit)
        $this->assertSame([2, 4], $ids);
    }

    public function testLikeOnFrozenTable(): void
    {
        $frozen = BarrierTable::from($this->createTable()->limit(6));
        $result = $frozen->like('name', '%a%');

        $names = [];
        foreach ($result as $row) {
            $names[] = $row->name;
        }

        // From first 6: names containing 'a' (case insensitive)
        // Alice, Carol, Dave, Frank all have 'a'
        $this->assertSame(['Alice', 'Carol', 'Dave', 'Frank'], $names);
    }

    // =========================================================================
    // Order/limit/offset after freezing
    // =========================================================================

    public function testOrderOnFrozenTable(): void
    {
        $frozen = BarrierTable::from($this->createTable()->limit(5));
        $result = $frozen->order('age DESC');

        $ages = [];
        foreach ($result as $row) {
            $ages[] = $row->age;
        }

        // First 5 rows (ages 20,25,30,35,40) sorted DESC
        $this->assertSame([40, 35, 30, 25, 20], $ages);
    }

    public function testLimitOnFrozenTable(): void
    {
        $frozen = BarrierTable::from($this->createTable()->limit(5));
        $result = $frozen->limit(2);

        $ids = [];
        foreach ($result as $row) {
            $ids[] = $row->id;
        }

        $this->assertSame([1, 2], $ids);
    }

    public function testOffsetOnFrozenTable(): void
    {
        $frozen = BarrierTable::from($this->createTable()->limit(5));
        $result = $frozen->offset(2);

        $ids = [];
        foreach ($result as $row) {
            $ids[] = $row->id;
        }

        // Skip first 2 of the frozen 5 rows
        $this->assertSame([3, 4, 5], $ids);
    }

    public function testLimitAndOffsetOnFrozenTable(): void
    {
        $frozen = BarrierTable::from($this->createTable()->limit(8));
        $result = $frozen->offset(2)->limit(3);

        $ids = [];
        foreach ($result as $row) {
            $ids[] = $row->id;
        }

        // Skip 2, take 3 from frozen rows 1-8
        $this->assertSame([3, 4, 5], $ids);
    }

    // =========================================================================
    // Edge cases: outer limit vs inner (frozen) limit
    // =========================================================================

    /**
     * SELECT * FROM (SELECT * FROM src LIMIT 5) LIMIT 10
     * Outer limit exceeds frozen rows - should return only what's available
     */
    public function testOuterLimitExceedsFrozenRows(): void
    {
        $frozen = BarrierTable::from($this->createTable()->limit(5));
        $result = $frozen->limit(10);

        $ids = [];
        foreach ($result as $row) {
            $ids[] = $row->id;
        }

        // Can only return 5 rows even though limit is 10
        $this->assertSame([1, 2, 3, 4, 5], $ids);
        $this->assertSame(5, $result->count());
    }

    /**
     * SELECT * FROM (SELECT * FROM src LIMIT 10) LIMIT 5
     * Outer limit is smaller than frozen rows - should respect outer limit
     */
    public function testOuterLimitSmallerThanFrozenRows(): void
    {
        $frozen = BarrierTable::from($this->createTable()->limit(10));
        $result = $frozen->limit(5);

        $ids = [];
        foreach ($result as $row) {
            $ids[] = $row->id;
        }

        $this->assertSame([1, 2, 3, 4, 5], $ids);
        $this->assertSame(5, $result->count());
    }

    /**
     * SELECT * FROM (SELECT * FROM src LIMIT 5) OFFSET 10
     * Offset exceeds frozen rows - should return empty
     */
    public function testOffsetExceedsFrozenRows(): void
    {
        $frozen = BarrierTable::from($this->createTable()->limit(5));
        $result = $frozen->offset(10);

        $this->assertSame(0, $result->count());

        $ids = [];
        foreach ($result as $row) {
            $ids[] = $row->id;
        }
        $this->assertSame([], $ids);
    }

    /**
     * SELECT * FROM (SELECT * FROM src LIMIT 5 OFFSET 3) LIMIT 10
     * Frozen source already has offset, outer limit exceeds remaining
     */
    public function testOuterLimitExceedsFrozenRowsWithSourceOffset(): void
    {
        // Source has 10 rows, offset 3 limit 5 gives rows 4-8
        $frozen = BarrierTable::from($this->createTable()->offset(3)->limit(5));
        $result = $frozen->limit(10);

        $ids = [];
        foreach ($result as $row) {
            $ids[] = $row->id;
        }

        // Rows 4-8 (5 rows), outer limit 10 doesn't add more
        $this->assertSame([4, 5, 6, 7, 8], $ids);
        $this->assertSame(5, $result->count());
    }

    /**
     * SELECT * FROM (SELECT * FROM src LIMIT 5 OFFSET 3) OFFSET 2 LIMIT 10
     * Both source and outer have offset, outer limit exceeds remaining
     */
    public function testNestedOffsetAndLimit(): void
    {
        // Source offset 3, limit 5 gives rows 4-8
        // Outer offset 2, limit 10 gives rows 6-8 (3 rows)
        $frozen = BarrierTable::from($this->createTable()->offset(3)->limit(5));
        $result = $frozen->offset(2)->limit(10);

        $ids = [];
        foreach ($result as $row) {
            $ids[] = $row->id;
        }

        $this->assertSame([6, 7, 8], $ids);
        $this->assertSame(3, $result->count());
    }

    /**
     * SELECT * FROM (SELECT * FROM src WHERE age < 30 LIMIT 3) WHERE age < 50 LIMIT 10
     * Outer filter is less restrictive than inner, outer limit exceeds frozen rows.
     * Should NOT introduce new rows - only the 3 frozen rows are candidates.
     */
    public function testLessRestrictiveFilterDoesNotIntroduceRows(): void
    {
        // Inner: age < 30 gives Alice(20), Bob(25), Carol(30 excluded), etc. Limit 3 = ids 1,2,5
        // Wait, let me check the test data...
        // Ages: 1=20, 2=25, 3=30, 4=35, 5=40, 6=45, 7=50, 8=55, 9=60, 10=65
        // age < 30: Alice(20), Bob(25) = only 2 rows!

        // Let me use age < 35 to get 3 rows: Alice(20), Bob(25), Carol(30)
        $source = $this->createTable()->lt('age', 35)->limit(3);
        $frozen = BarrierTable::from($source);

        // Outer filter age < 50 is less restrictive - all 3 frozen rows match
        // Outer limit 10 exceeds frozen rows
        $result = $frozen->lt('age', 50)->limit(10);

        $ids = [];
        foreach ($result as $row) {
            $ids[] = $row->id;
        }

        // Should still be only 3 rows (the frozen ones), not 10
        $this->assertSame([1, 2, 3], $ids);
        $this->assertSame(3, $result->count());
    }

    /**
     * SELECT * FROM (SELECT * FROM src WHERE dept='Sales' LIMIT 3) WHERE age < 100 LIMIT 10
     * Outer filter matches all, outer limit exceeds frozen - should return frozen rows only
     */
    public function testUniversalFilterDoesNotIntroduceRows(): void
    {
        // Sales: Bob(2), Dave(4), Grace(7), Jack(10) - limit 3 = ids 2,4,7
        $source = $this->createTable()->eq('dept', 'Sales')->limit(3);
        $frozen = BarrierTable::from($source);

        // age < 100 matches everyone, limit 10 exceeds frozen rows
        $result = $frozen->lt('age', 100)->limit(10);

        $ids = [];
        foreach ($result as $row) {
            $ids[] = $row->id;
        }

        // Only the 3 frozen Sales rows, not 10
        $this->assertSame([2, 4, 7], $ids);
        $this->assertSame(3, $result->count());
    }

    /**
     * Contrast: without freezing, filter+limit returns different rows than with freezing
     *
     * This demonstrates the core purpose of BarrierTable:
     * - Without freezing: limit(5).eq('dept', 'Sales') → first 5 Sales employees
     * - With freezing: freeze(limit(5)).eq('dept', 'Sales') → Sales from first 5 rows
     */
    public function testContrastFrozenVsUnfrozen(): void
    {
        $base = $this->createTable();

        // Without freezing: eq() pushes down, we get first 5 Sales employees
        // Sales: Bob(2), Dave(4), Grace(7), Jack(10)
        $unfrozen = $base->limit(5)->eq('dept', 'Sales');
        $unfrozenIds = [];
        foreach ($unfrozen as $row) {
            $unfrozenIds[] = $row->id;
        }
        // Filter pushed down: gets all 4 Sales employees (limit 5 not reached)
        $this->assertSame([2, 4, 7, 10], $unfrozenIds);

        // With freezing: only Sales from the frozen first 5 rows (ids 1-5)
        $frozen = BarrierTable::from($base->limit(5))->eq('dept', 'Sales');
        $frozenIds = [];
        foreach ($frozen as $row) {
            $frozenIds[] = $row->id;
        }
        // Only Bob(2) and Dave(4) are Sales within first 5 rows
        $this->assertSame([2, 4], $frozenIds);
    }

    // =========================================================================
    // Verify filters/order never push up (would change result set membership)
    // =========================================================================

    /**
     * If order pushed up, different rows would enter the limit window.
     * Frozen table must sort the frozen rows, not re-query with new order.
     */
    public function testOrderDoesNotChangeResultSetMembership(): void
    {
        // First 5 by default order (ids 1-5)
        $frozen = BarrierTable::from($this->createTable()->limit(5));

        // Order by age DESC - should sort the same 5 rows, not get 5 oldest
        $ordered = $frozen->order('age DESC');

        $ids = [];
        foreach ($ordered as $row) {
            $ids[] = $row->id;
        }

        // Same rows (1-5), just reordered by age: Eve(40), Dave(35), Carol(30), Bob(25), Alice(20)
        $this->assertSame([5, 4, 3, 2, 1], $ids);

        // If order had pushed up, we'd get ids 10,9,8,7,6 (oldest 5)
    }

    /**
     * If filter pushed up, removed rows would be replaced by others.
     * Frozen table must filter the frozen rows, not re-query.
     */
    public function testFilterDoesNotChangeResultSetMembership(): void
    {
        // First 5 rows: Alice(Eng), Bob(Sales), Carol(Eng), Dave(Sales), Eve(Mkt)
        $frozen = BarrierTable::from($this->createTable()->limit(5));

        // Filter to Engineering - should only get Eng from these 5
        $filtered = $frozen->eq('dept', 'Engineering');

        $ids = [];
        foreach ($filtered as $row) {
            $ids[] = $row->id;
        }

        // Only Alice(1) and Carol(3) - Engineering from first 5
        $this->assertSame([1, 3], $ids);

        // If filter had pushed up, we'd get all 4 Engineering: 1, 3, 6, 9
    }

    /**
     * Multiple filters should all wrap, never push up
     */
    public function testChainedFiltersDoNotChangeResultSetMembership(): void
    {
        // First 8 rows
        $frozen = BarrierTable::from($this->createTable()->limit(8));

        // Chain filters that would match more rows if pushed up
        $filtered = $frozen->gte('age', 25)->lte('age', 45);

        $ids = [];
        foreach ($filtered as $row) {
            $ids[] = $row->id;
        }

        // From first 8 (ages 20-55): 25 <= age <= 45
        // Bob(25), Carol(30), Dave(35), Eve(40), Frank(45)
        $this->assertSame([2, 3, 4, 5, 6], $ids);
    }

    /**
     * Order then filter - both must not push up
     */
    public function testOrderThenFilterDoNotChangeResultSetMembership(): void
    {
        // First 5 rows (ids 1-5)
        $frozen = BarrierTable::from($this->createTable()->limit(5));

        // Order then filter
        $result = $frozen->order('age DESC')->eq('dept', 'Sales');

        $ids = [];
        foreach ($result as $row) {
            $ids[] = $row->id;
        }

        // Sales from first 5, ordered by age DESC: Dave(35), Bob(25)
        $this->assertSame([4, 2], $ids);
    }

    // =========================================================================
    // Chained operations after freezing
    // =========================================================================

    public function testChainedFiltersOnFrozenTable(): void
    {
        $frozen = BarrierTable::from($this->createTable()->limit(8));
        $result = $frozen->gte('age', 30)->lte('age', 50);

        $ids = [];
        foreach ($result as $row) {
            $ids[] = $row->id;
        }

        // From first 8 (ages 20-55): 30 <= age <= 50
        // Carol(30), Dave(35), Eve(40), Frank(45), Grace(50)
        $this->assertSame([3, 4, 5, 6, 7], $ids);
    }

    public function testFilterThenOrderOnFrozenTable(): void
    {
        $frozen = BarrierTable::from($this->createTable()->limit(6));
        $result = $frozen->eq('dept', 'Engineering')->order('age DESC');

        $ages = [];
        foreach ($result as $row) {
            $ages[] = $row->age;
        }

        // Engineering from first 6: Alice(20), Carol(30), Frank(45), sorted DESC
        $this->assertSame([45, 30, 20], $ages);
    }

    // =========================================================================
    // With offset-based pagination
    // =========================================================================

    public function testFreezeWithOffset(): void
    {
        // Skip first 3, take next 4 (ids 4-7), then filter
        $paged = $this->createTable()->offset(3)->limit(4);
        $frozen = BarrierTable::from($paged);
        $filtered = $frozen->eq('dept', 'Sales');

        $ids = [];
        foreach ($filtered as $row) {
            $ids[] = $row->id;
        }

        // Rows 4-7: Dave(Sales), Eve(Marketing), Frank(Engineering), Grace(Sales)
        // Only Sales: Dave(4), Grace(7)
        $this->assertSame([4, 7], $ids);
    }

    public function testFreezeOffsetOnlyThenFilter(): void
    {
        // Skip first 5 (ids 6-10 remain), then filter
        $paged = $this->createTable()->offset(5);
        $frozen = BarrierTable::from($paged);
        $filtered = $frozen->eq('dept', 'Engineering');

        $ids = [];
        foreach ($filtered as $row) {
            $ids[] = $row->id;
        }

        // Rows 6-10: Frank(Eng), Grace(Sales), Henry(Mkt), Ivy(Eng), Jack(Sales)
        // Only Engineering: Frank(6), Ivy(9)
        $this->assertSame([6, 9], $ids);
    }

    // =========================================================================
    // Count behavior
    // =========================================================================

    public function testCountOnFrozenTable(): void
    {
        $frozen = BarrierTable::from($this->createTable()->limit(5));

        $this->assertSame(5, $frozen->count());
    }

    public function testCountAfterFilterOnFrozenTable(): void
    {
        $frozen = BarrierTable::from($this->createTable()->limit(5));
        $filtered = $frozen->eq('dept', 'Engineering');

        // Only Alice and Carol from first 5
        $this->assertSame(2, $filtered->count());
    }

    public function testCountRespectsLimitOnFrozenTable(): void
    {
        $frozen = BarrierTable::from($this->createTable()->limit(5));
        $limited = $frozen->limit(2);

        $this->assertSame(2, $limited->count());
    }

    public function testCountRespectsOffsetOnFrozenTable(): void
    {
        $frozen = BarrierTable::from($this->createTable()->limit(5));
        $offset = $frozen->offset(2);

        $this->assertSame(3, $offset->count());
    }
};

exit($test->run());
