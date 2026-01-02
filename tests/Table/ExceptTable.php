<?php
/**
 * Test ExceptTable implementation
 *
 * ExceptTable yields rows from source that don't exist in the excluded set.
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Test;
use mini\Table\GeneratorTable;
use mini\Table\Utility\Set;
use mini\Table\ColumnDef;
use mini\Table\Types\ColumnType;
use mini\Table\Types\IndexType;

$test = new class extends Test {

    /**
     * Create a test table with 10 rows
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
    // Basic except behavior
    // =========================================================================

    public function testExceptRemovesMatchingRows(): void
    {
        $table = $this->createTable();
        $excluded = new Set('id', [2, 4, 6]);

        $result = $table->except($excluded);

        $ids = [];
        foreach ($result as $row) {
            $ids[] = $row->id;
        }

        $this->assertSame([1, 3, 5, 7, 8, 9, 10], $ids);
    }

    public function testExceptWithEmptySetReturnsAll(): void
    {
        $table = $this->createTable();
        $excluded = new Set('id', []);

        $result = $table->except($excluded);

        $this->assertSame(10, $result->count());
    }

    public function testExceptWithAllExcludedReturnsEmpty(): void
    {
        $table = $this->createTable();
        $excluded = new Set('id', [1, 2, 3, 4, 5, 6, 7, 8, 9, 10]);

        $result = $table->except($excluded);

        $this->assertSame(0, $result->count());
    }

    public function testExceptWithTableAsExcludedSet(): void
    {
        $table = $this->createTable();
        // Exclude Sales dept
        $excluded = $table->eq('dept', 'Sales')->columns('id');

        $result = $table->except($excluded);

        $ids = [];
        foreach ($result as $row) {
            $ids[] = $row->id;
        }

        // Non-Sales: Alice(1), Carol(3), Eve(5), Frank(6), Henry(8), Ivy(9)
        $this->assertSame([1, 3, 5, 6, 8, 9], $ids);
    }

    // =========================================================================
    // Except with limit/offset on source
    // =========================================================================

    public function testExceptWithLimitedSource(): void
    {
        // First 5 rows, then exclude id 2 and 4
        $table = $this->createTable()->limit(5);
        $excluded = new Set('id', [2, 4]);

        $result = $table->except($excluded);

        $ids = [];
        foreach ($result as $row) {
            $ids[] = $row->id;
        }

        // From first 5 (1-5), minus 2 and 4 = 1, 3, 5
        $this->assertSame([1, 3, 5], $ids);
    }

    public function testExceptWithOffsetSource(): void
    {
        // Skip first 3, then exclude id 6
        $table = $this->createTable()->offset(3);
        $excluded = new Set('id', [6]);

        $result = $table->except($excluded);

        $ids = [];
        foreach ($result as $row) {
            $ids[] = $row->id;
        }

        // Rows 4-10, minus 6 = 4, 5, 7, 8, 9, 10
        $this->assertSame([4, 5, 7, 8, 9, 10], $ids);
    }

    // =========================================================================
    // Filter pushdown behavior - this is where issues may occur
    // =========================================================================

    /**
     * Test if filter after except with limited source maintains result set membership.
     *
     * Scenario: First 5 rows (1-5), except id 3, then filter to Engineering.
     * Expected: Only Engineering from {1, 2, 4, 5} = {1} (Alice)
     * If filter pushes down incorrectly, we might get different rows.
     */
    public function testFilterAfterExceptWithLimitedSource(): void
    {
        $table = $this->createTable()->limit(5);
        $excluded = new Set('id', [3]);

        // First 5 (1-5), minus 3 = {1, 2, 4, 5}
        $excepted = $table->except($excluded);

        // Now filter to Engineering - should only get Engineering from {1, 2, 4, 5}
        $filtered = $excepted->eq('dept', 'Engineering');

        $ids = [];
        foreach ($filtered as $row) {
            $ids[] = $row->id;
        }

        // Engineering in {1, 2, 4, 5}: only Alice(1)
        // If filter pushed down incorrectly, might get Frank(6), Ivy(9) etc.
        $this->assertSame([1], $ids);
    }

    /**
     * Contrast: what happens without limit - filter can push down safely
     */
    public function testFilterAfterExceptWithoutLimit(): void
    {
        $table = $this->createTable();
        $excluded = new Set('id', [3, 6]);

        $excepted = $table->except($excluded);
        $filtered = $excepted->eq('dept', 'Engineering');

        $ids = [];
        foreach ($filtered as $row) {
            $ids[] = $row->id;
        }

        // All Engineering (1, 3, 6, 9) minus excluded (3, 6) = {1, 9}
        $this->assertSame([1, 9], $ids);
    }

    /**
     * Test that limit on except result works correctly
     */
    public function testLimitOnExceptResult(): void
    {
        $table = $this->createTable();
        $excluded = new Set('id', [2, 4]);

        $result = $table->except($excluded)->limit(3);

        $ids = [];
        foreach ($result as $row) {
            $ids[] = $row->id;
        }

        // First 3 of {1, 3, 5, 6, 7, 8, 9, 10}
        $this->assertSame([1, 3, 5], $ids);
    }

    /**
     * Test offset on except result
     */
    public function testOffsetOnExceptResult(): void
    {
        $table = $this->createTable();
        $excluded = new Set('id', [2, 4]);

        $result = $table->except($excluded)->offset(2);

        $ids = [];
        foreach ($result as $row) {
            $ids[] = $row->id;
        }

        // Skip first 2 of {1, 3, 5, 6, 7, 8, 9, 10} = {5, 6, 7, 8, 9, 10}
        $this->assertSame([5, 6, 7, 8, 9, 10], $ids);
    }

    // =========================================================================
    // Edge case: nested limit/offset with filter
    // =========================================================================

    /**
     * SELECT * FROM (SELECT * FROM src LIMIT 5) EXCEPT excluded WHERE dept='Sales'
     *
     * If source has pagination and filter pushes down, result set membership changes.
     */
    public function testFilterDoesNotChangeResultSetMembership(): void
    {
        // First 5 rows: Alice(Eng), Bob(Sales), Carol(Eng), Dave(Sales), Eve(Mkt)
        $source = $this->createTable()->limit(5);

        // Exclude Carol(3)
        $excepted = $source->except(new Set('id', [3]));
        // Result: {1, 2, 4, 5}

        // Filter to Sales - should only get Sales from {1, 2, 4, 5}
        $filtered = $excepted->eq('dept', 'Sales');

        $ids = [];
        foreach ($filtered as $row) {
            $ids[] = $row->id;
        }

        // Sales in {1, 2, 4, 5}: Bob(2) and Dave(4)
        // If filter pushed down: would get first 5 Sales = {2, 4, 7, 10} limited to 5
        $this->assertSame([2, 4], $ids);
    }

    /**
     * Multiple filters after except with limited source
     */
    public function testChainedFiltersAfterExceptWithLimitedSource(): void
    {
        $source = $this->createTable()->limit(8);
        $excepted = $source->except(new Set('id', [1, 2]));
        // Result: {3, 4, 5, 6, 7, 8}

        $filtered = $excepted->gte('age', 35)->lte('age', 50);

        $ids = [];
        foreach ($filtered as $row) {
            $ids[] = $row->id;
        }

        // From {3, 4, 5, 6, 7, 8} with 35 <= age <= 50:
        // Carol(30-no), Dave(35-yes), Eve(40-yes), Frank(45-yes), Grace(50-yes), Henry(55-no)
        $this->assertSame([4, 5, 6, 7], $ids);
    }
};

exit($test->run());
