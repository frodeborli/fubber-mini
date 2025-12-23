<?php
/**
 * Test column projection with various query shapes
 *
 * Ensures filters work regardless of which columns are visible,
 * and that columns() can only narrow, not expand.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use mini\Test;
use mini\Table\GeneratorTable;
use mini\Table\Predicate;
use mini\Table\Utility\Set;
use mini\Table\ColumnDef;
use mini\Table\Types\ColumnType;
use mini\Table\Types\IndexType;

$test = new class extends Test {

    /**
     * Create a test table with various column types
     */
    protected function createTable(): GeneratorTable
    {
        return new GeneratorTable(
            fn() => yield from [
                1  => (object)['id' => 1,  'name' => 'Alice',   'age' => 20, 'dept' => 'Engineering', 'active' => true],
                2  => (object)['id' => 2,  'name' => 'Bob',     'age' => 25, 'dept' => 'Sales',       'active' => true],
                3  => (object)['id' => 3,  'name' => 'Carol',   'age' => 30, 'dept' => 'Engineering', 'active' => false],
                4  => (object)['id' => 4,  'name' => 'Dave',    'age' => 35, 'dept' => 'Sales',       'active' => true],
                5  => (object)['id' => 5,  'name' => 'Eve',     'age' => 40, 'dept' => 'Marketing',   'active' => false],
                6  => (object)['id' => 6,  'name' => 'Frank',   'age' => 45, 'dept' => 'Engineering', 'active' => true],
                7  => (object)['id' => 7,  'name' => 'Grace',   'age' => 50, 'dept' => 'Sales',       'active' => false],
                8  => (object)['id' => 8,  'name' => 'Henry',   'age' => 55, 'dept' => 'Marketing',   'active' => true],
                9  => (object)['id' => 9,  'name' => 'Ivy',     'age' => 60, 'dept' => 'Engineering', 'active' => false],
                10 => (object)['id' => 10, 'name' => 'Jack',    'age' => 65, 'dept' => 'Sales',       'active' => true],
            ],
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('name', ColumnType::Text),
            new ColumnDef('age', ColumnType::Int),
            new ColumnDef('dept', ColumnType::Text),
            new ColumnDef('active', ColumnType::Int),
        );
    }

    /**
     * Generate various query shapes for comprehensive testing
     *
     * @return Generator<string, callable(GeneratorTable): \mini\Table\TableInterface>
     */
    protected function queryShapes(): \Generator
    {
        // Basic projections
        yield 'all columns' => fn($t) => $t;
        yield 'single column' => fn($t) => $t->columns('id');
        yield 'two columns' => fn($t) => $t->columns('id', 'name');
        yield 'three columns' => fn($t) => $t->columns('id', 'name', 'age');

        // Filters on visible columns
        yield 'eq on visible' => fn($t) => $t->columns('id', 'dept')->eq('dept', 'Sales');
        yield 'lt on visible' => fn($t) => $t->columns('id', 'age')->lt('age', 40);
        yield 'gt on visible' => fn($t) => $t->columns('id', 'age')->gt('age', 40);

        // Filters on non-visible columns (the tricky cases)
        yield 'eq on hidden column' => fn($t) => $t->columns('id', 'name')->eq('dept', 'Engineering');
        yield 'lt on hidden column' => fn($t) => $t->columns('id', 'name')->lt('age', 30);
        yield 'gt on hidden column' => fn($t) => $t->columns('id', 'name')->gt('age', 50);

        // Filter then project
        yield 'filter then project' => fn($t) => $t->eq('dept', 'Sales')->columns('id');
        yield 'multiple filters then project' => fn($t) => $t->eq('dept', 'Sales')->gte('age', 30)->columns('id', 'name');

        // Project then filter (filter on hidden)
        yield 'project then filter hidden' => fn($t) => $t->columns('id', 'name')->eq('dept', 'Engineering');

        // Limit/offset combinations
        yield 'limit then project' => fn($t) => $t->limit(5)->columns('id');
        yield 'project then limit' => fn($t) => $t->columns('id')->limit(5);
        yield 'filter limit project' => fn($t) => $t->eq('dept', 'Sales')->limit(2)->columns('id');

        // Order combinations
        yield 'order then project' => fn($t) => $t->order('age DESC')->columns('id', 'age');
        yield 'project then order' => fn($t) => $t->columns('id', 'age')->order('age DESC');
        yield 'order on hidden then project' => fn($t) => $t->order('dept ASC')->columns('id', 'name');

        // Complex chains
        yield 'filter order limit project' => fn($t) => $t->eq('active', true)->order('age DESC')->limit(3)->columns('id', 'name');
        yield 'project filter order limit' => fn($t) => $t->columns('id', 'name', 'age', 'active')->eq('active', true)->order('age DESC')->limit(3);

        // OR predicates
        yield 'or with visible columns' => function($t) {
            $p = Predicate::from($t);
            return $t->columns('id', 'dept')->or($p->eq('dept', 'Sales'), $p->eq('dept', 'Marketing'));
        };
        yield 'or then project' => function($t) {
            $p = Predicate::from($t);
            return $t->or($p->eq('dept', 'Sales'), $p->eq('dept', 'Marketing'))->columns('id');
        };

        // Except combinations
        yield 'except then project' => fn($t) => $t->except(new Set('id', [2, 4, 6]))->columns('id', 'name');
        yield 'project then except' => fn($t) => $t->columns('id', 'name')->except(new Set('id', [2, 4, 6]));

        // Union combinations
        yield 'union then project' => fn($t) => $t->eq('dept', 'Sales')->union($t->eq('dept', 'Marketing'))->columns('id');
    }

    // =========================================================================
    // Column narrowing tests
    // =========================================================================

    public function testColumnsCanOnlyNarrow(): void
    {
        $table = $this->createTable();
        $narrowed = $table->columns('id', 'name');

        $this->assertThrows(
            fn() => $narrowed->columns('id', 'name', 'age'),
            \InvalidArgumentException::class
        );
    }

    public function testColumnsCanNarrowFurther(): void
    {
        $table = $this->createTable();
        $result = $table->columns('id', 'name', 'age')->columns('id', 'name');

        $cols = array_keys($result->getColumns());
        $this->assertSame(['id', 'name'], $cols);
    }

    public function testColumnsCanSelectSameColumns(): void
    {
        $table = $this->createTable();
        $result = $table->columns('id', 'name')->columns('id', 'name');

        $cols = array_keys($result->getColumns());
        $this->assertSame(['id', 'name'], $cols);
    }

    public function testColumnsCanReorder(): void
    {
        $table = $this->createTable();
        $result = $table->columns('name', 'id', 'age')->columns('age', 'id');

        $cols = array_keys($result->getColumns());
        $this->assertSame(['age', 'id'], $cols);
    }

    // =========================================================================
    // Filter on hidden column tests
    // =========================================================================

    public function testFilterOnHiddenColumnStillWorks(): void
    {
        $table = $this->createTable();

        // Project to just id/name, but filter on dept (hidden)
        $result = $table->columns('id', 'name')->eq('dept', 'Engineering');

        $ids = [];
        foreach ($result as $row) {
            $ids[] = $row->id;
            // Verify dept is not in output
            $this->assertFalse(property_exists($row, 'dept'));
        }

        // Engineering: Alice(1), Carol(3), Frank(6), Ivy(9)
        $this->assertSame([1, 3, 6, 9], $ids);
    }

    public function testMultipleFiltersOnHiddenColumns(): void
    {
        $table = $this->createTable();

        // Project to just id, filter on dept and age (both hidden after projection)
        $result = $table->columns('id')->eq('dept', 'Engineering')->gte('age', 40);

        $ids = [];
        foreach ($result as $row) {
            $ids[] = $row->id;
        }

        // Engineering with age >= 40: Frank(45), Ivy(60)
        $this->assertSame([6, 9], $ids);
    }

    public function testFilterBeforeAndAfterProjection(): void
    {
        $table = $this->createTable();

        // Filter on dept, project, then filter on age (now hidden)
        $result = $table->eq('dept', 'Sales')->columns('id', 'name')->gte('age', 50);

        $ids = [];
        foreach ($result as $row) {
            $ids[] = $row->id;
        }

        // Sales with age >= 50: Grace(50), Jack(65)
        $this->assertSame([7, 10], $ids);
    }

    // =========================================================================
    // Query shape verification
    // =========================================================================

    public function testAllQueryShapesProduceValidOutput(): void
    {
        $table = $this->createTable();
        $failures = [];

        foreach ($this->queryShapes() as $name => $builder) {
            try {
                $result = $builder($table);

                // Verify we can iterate
                $rows = iterator_to_array($result);

                // Verify row structure matches declared columns
                $declaredCols = array_keys($result->getColumns());
                foreach ($rows as $row) {
                    $rowCols = array_keys((array) $row);
                    sort($rowCols);
                    $sortedDeclared = $declaredCols;
                    sort($sortedDeclared);

                    if ($rowCols !== $sortedDeclared) {
                        $failures[] = "$name: row columns [" . implode(',', $rowCols) . "] != declared [" . implode(',', $sortedDeclared) . "]";
                    }
                }

                // Verify count matches iteration
                $iterCount = count($rows);
                $countResult = $result->count();
                if ($iterCount !== $countResult) {
                    $failures[] = "$name: iteration count ($iterCount) != count() ($countResult)";
                }

            } catch (\Throwable $e) {
                $failures[] = "$name: " . $e->getMessage();
            }
        }

        if (!empty($failures)) {
            $this->fail("Query shape failures:\n" . implode("\n", $failures));
        }
    }

    public function testHasWorksWithProjectedColumns(): void
    {
        $table = $this->createTable();

        // Project to just id
        $projected = $table->columns('id');

        // has() should work on projected columns
        $this->assertTrue($projected->has((object)['id' => 1]));
        $this->assertTrue($projected->has((object)['id' => 5]));
        $this->assertFalse($projected->has((object)['id' => 99]));
    }

    public function testHasWithFilterAndProjection(): void
    {
        $table = $this->createTable();

        // Filter then project
        $result = $table->eq('dept', 'Engineering')->columns('id');

        // Should find Engineering ids
        $this->assertTrue($result->has((object)['id' => 1]));  // Alice - Engineering
        $this->assertTrue($result->has((object)['id' => 6]));  // Frank - Engineering

        // Should NOT find non-Engineering ids (they're filtered out)
        $this->assertFalse($result->has((object)['id' => 2])); // Bob - Sales
        $this->assertFalse($result->has((object)['id' => 5])); // Eve - Marketing
    }

    public function testExistsWithProjectedColumns(): void
    {
        $table = $this->createTable();

        $this->assertTrue($table->columns('id')->exists());
        $this->assertTrue($table->columns('id')->eq('dept', 'Engineering')->exists());
        $this->assertFalse($table->columns('id')->eq('dept', 'NonExistent')->exists());
    }

    public function testCountWithProjectedColumns(): void
    {
        $table = $this->createTable();

        $this->assertSame(10, $table->columns('id')->count());
        $this->assertSame(4, $table->columns('id')->eq('dept', 'Engineering')->count());
        $this->assertSame(4, $table->columns('id', 'name')->eq('dept', 'Sales')->count());
    }

    // =========================================================================
    // Order with hidden columns
    // =========================================================================

    public function testOrderOnHiddenColumn(): void
    {
        $table = $this->createTable();

        // Order by age (will be hidden), project to id only
        $result = $table->order('age DESC')->columns('id');

        $ids = [];
        foreach ($result as $row) {
            $ids[] = $row->id;
        }

        // Should be ordered by age descending: Jack(65), Ivy(60), Henry(55), ...
        $this->assertSame([10, 9, 8, 7, 6, 5, 4, 3, 2, 1], $ids);
    }

    public function testOrderOnHiddenColumnWithFilter(): void
    {
        $table = $this->createTable();

        // Filter by dept, order by age, project to id
        $result = $table->eq('dept', 'Engineering')->order('age DESC')->columns('id');

        $ids = [];
        foreach ($result as $row) {
            $ids[] = $row->id;
        }

        // Engineering ordered by age desc: Ivy(60), Frank(45), Carol(30), Alice(20)
        $this->assertSame([9, 6, 3, 1], $ids);
    }
};

exit($test->run());
