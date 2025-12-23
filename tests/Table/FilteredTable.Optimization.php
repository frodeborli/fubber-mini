<?php
/**
 * Test FilteredTable same-column filter optimization
 *
 * When chaining filters on the same column, FilteredTable optimizes:
 * - Incompatible filters → EmptyTable
 * - Narrowing filter → delegate to source
 * - Redundant filter → return $this
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use mini\Test;
use mini\Table\GeneratorTable;
use mini\Table\Wrappers\FilteredTable;
use mini\Table\Utility\EmptyTable;
use mini\Table\ColumnDef;
use mini\Table\Types\ColumnType;
use mini\Table\Types\IndexType;

$test = new class extends Test {

    protected function createTable(): GeneratorTable
    {
        return new GeneratorTable(
            fn() => yield from [
                1 => (object)['id' => 1, 'age' => 10],
                2 => (object)['id' => 2, 'age' => 20],
                3 => (object)['id' => 3, 'age' => 30],
                4 => (object)['id' => 4, 'age' => 40],
                5 => (object)['id' => 5, 'age' => 50],
            ],
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('age', ColumnType::Int),
        );
    }

    // =========================================================================
    // eq -> eq
    // =========================================================================

    public function testEqEqSameValueReturnsSelf(): void
    {
        $filtered = $this->createTable()->eq('age', 20);
        $result = $filtered->eq('age', 20);

        $this->assertSame($filtered, $result);
    }

    public function testEqEqDifferentValueReturnsEmpty(): void
    {
        $filtered = $this->createTable()->eq('age', 20);
        $result = $filtered->eq('age', 30);

        $this->assertTrue($result instanceof EmptyTable);
        $this->assertSame(0, $result->count());
    }

    // =========================================================================
    // eq -> comparison operators
    // =========================================================================

    public function testEqLtCompatibleReturnsSelf(): void
    {
        $filtered = $this->createTable()->eq('age', 20);
        $result = $filtered->lt('age', 30); // 20 < 30, compatible

        $this->assertSame($filtered, $result);
    }

    public function testEqLtIncompatibleReturnsEmpty(): void
    {
        $filtered = $this->createTable()->eq('age', 20);
        $result = $filtered->lt('age', 20); // 20 is not < 20

        $this->assertTrue($result instanceof EmptyTable);
    }

    public function testEqGtCompatibleReturnsSelf(): void
    {
        $filtered = $this->createTable()->eq('age', 20);
        $result = $filtered->gt('age', 10); // 20 > 10, compatible

        $this->assertSame($filtered, $result);
    }

    public function testEqGtIncompatibleReturnsEmpty(): void
    {
        $filtered = $this->createTable()->eq('age', 20);
        $result = $filtered->gt('age', 20); // 20 is not > 20

        $this->assertTrue($result instanceof EmptyTable);
    }

    // =========================================================================
    // lt -> lt (both upper bounds)
    // =========================================================================

    public function testLtLtNarrowerDelegatesToSource(): void
    {
        $table = $this->createTable();
        $filtered = $table->lt('age', 40);
        $result = $filtered->lt('age', 30); // Narrows: < 30 is stricter than < 40

        // Should delegate to source, not wrap
        $this->assertFalse($result === $filtered);

        $ids = [];
        foreach ($result as $row) {
            $ids[] = $row->id;
        }
        $this->assertSame([1, 2], $ids); // age 10, 20 (< 30)
    }

    public function testLtLtRedundantReturnsSelf(): void
    {
        $filtered = $this->createTable()->lt('age', 30);
        $result = $filtered->lt('age', 40); // Redundant: < 40 doesn't narrow < 30

        $this->assertSame($filtered, $result);
    }

    // =========================================================================
    // gt -> gt (both lower bounds)
    // =========================================================================

    public function testGtGtNarrowerDelegatesToSource(): void
    {
        $table = $this->createTable();
        $filtered = $table->gt('age', 20);
        $result = $filtered->gt('age', 30); // Narrows: > 30 is stricter than > 20

        $this->assertFalse($result === $filtered);

        $ids = [];
        foreach ($result as $row) {
            $ids[] = $row->id;
        }
        $this->assertSame([4, 5], $ids); // age 40, 50 (> 30)
    }

    public function testGtGtRedundantReturnsSelf(): void
    {
        $filtered = $this->createTable()->gt('age', 30);
        $result = $filtered->gt('age', 20); // Redundant: > 20 doesn't narrow > 30

        $this->assertSame($filtered, $result);
    }

    // =========================================================================
    // lt/lte -> eq (upper bound then equality)
    // =========================================================================

    public function testLtEqCompatibleDelegatesToSource(): void
    {
        $table = $this->createTable();
        $filtered = $table->lt('age', 40);
        $result = $filtered->eq('age', 20); // 20 < 40, narrows to single value

        $ids = [];
        foreach ($result as $row) {
            $ids[] = $row->id;
        }
        $this->assertSame([2], $ids);
    }

    public function testLtEqIncompatibleReturnsEmpty(): void
    {
        $filtered = $this->createTable()->lt('age', 20);
        $result = $filtered->eq('age', 30); // 30 is not < 20

        $this->assertTrue($result instanceof EmptyTable);
    }

    // =========================================================================
    // gt/gte -> eq (lower bound then equality)
    // =========================================================================

    public function testGtEqCompatibleDelegatesToSource(): void
    {
        $table = $this->createTable();
        $filtered = $table->gt('age', 20);
        $result = $filtered->eq('age', 30); // 30 > 20, narrows to single value

        $ids = [];
        foreach ($result as $row) {
            $ids[] = $row->id;
        }
        $this->assertSame([3], $ids);
    }

    public function testGtEqIncompatibleReturnsEmpty(): void
    {
        $filtered = $this->createTable()->gt('age', 30);
        $result = $filtered->eq('age', 20); // 20 is not > 30

        $this->assertTrue($result instanceof EmptyTable);
    }

    // =========================================================================
    // Range filters (lower + upper bound)
    // =========================================================================

    public function testLtGtValidRangeWraps(): void
    {
        $filtered = $this->createTable()->lt('age', 40);
        $result = $filtered->gt('age', 20); // Valid range: 20 < age < 40

        $ids = [];
        foreach ($result as $row) {
            $ids[] = $row->id;
        }
        $this->assertSame([3], $ids); // Only age 30
    }

    public function testLtGtInvalidRangeReturnsEmpty(): void
    {
        $filtered = $this->createTable()->lt('age', 20);
        $result = $filtered->gt('age', 30); // Invalid: nothing is < 20 AND > 30

        $this->assertTrue($result instanceof EmptyTable);
    }

    public function testGtLtValidRangeWraps(): void
    {
        $filtered = $this->createTable()->gt('age', 20);
        $result = $filtered->lt('age', 40); // Valid range: 20 < age < 40

        $ids = [];
        foreach ($result as $row) {
            $ids[] = $row->id;
        }
        $this->assertSame([3], $ids); // Only age 30
    }

    // =========================================================================
    // Edge cases: boundary values
    // =========================================================================

    public function testLtGtSameValueReturnsEmpty(): void
    {
        $filtered = $this->createTable()->lt('age', 30);
        $result = $filtered->gt('age', 30); // Nothing is both < 30 and > 30

        $this->assertTrue($result instanceof EmptyTable);
    }

    public function testGteLteCollapsesToEq(): void
    {
        $table = $this->createTable();
        $filtered = $table->gte('age', 30);
        $result = $filtered->lte('age', 30); // >= 30 AND <= 30 = exactly 30

        $ids = [];
        foreach ($result as $row) {
            $ids[] = $row->id;
        }
        $this->assertSame([3], $ids); // Only age 30
    }

    public function testLteGteCollapsesToEq(): void
    {
        $table = $this->createTable();
        $filtered = $table->lte('age', 30);
        $result = $filtered->gte('age', 30); // <= 30 AND >= 30 = exactly 30

        $ids = [];
        foreach ($result as $row) {
            $ids[] = $row->id;
        }
        $this->assertSame([3], $ids); // Only age 30
    }

    // =========================================================================
    // Different columns - no optimization, just wrap
    // =========================================================================

    public function testDifferentColumnWrapsNormally(): void
    {
        $filtered = $this->createTable()->eq('id', 2);
        $result = $filtered->eq('age', 20); // Different column

        // Should wrap, not optimize
        $this->assertFalse($result === $filtered);
        $this->assertFalse($result instanceof EmptyTable);

        $ids = [];
        foreach ($result as $row) {
            $ids[] = $row->id;
        }
        $this->assertSame([2], $ids);
    }

    // =========================================================================
    // Mixed lte/lt and gte/gt combinations
    // =========================================================================

    public function testLteLtNarrows(): void
    {
        $table = $this->createTable();
        $filtered = $table->lte('age', 40);
        $result = $filtered->lt('age', 30);

        $ids = [];
        foreach ($result as $row) {
            $ids[] = $row->id;
        }
        $this->assertSame([1, 2], $ids); // age < 30
    }

    public function testGteGtNarrows(): void
    {
        $table = $this->createTable();
        $filtered = $table->gte('age', 20);
        $result = $filtered->gt('age', 30);

        $ids = [];
        foreach ($result as $row) {
            $ids[] = $row->id;
        }
        $this->assertSame([4, 5], $ids); // age > 30
    }
};

exit($test->run());
