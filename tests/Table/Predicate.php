<?php
/**
 * Test Predicate and or() functionality
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use mini\Test;
use mini\Table\GeneratorTable;
use mini\Table\Predicate;

$test = new class extends Test {

    protected function createTable(): GeneratorTable
    {
        return new GeneratorTable(fn() => yield from [
            1 => (object)['id' => 1, 'name' => 'Alice', 'age' => 20, 'status' => 'active'],
            2 => (object)['id' => 2, 'name' => 'Bob', 'age' => 30, 'status' => 'inactive'],
            3 => (object)['id' => 3, 'name' => 'Carol', 'age' => 40, 'status' => 'active'],
            4 => (object)['id' => 4, 'name' => 'Dave', 'age' => 50, 'status' => 'pending'],
            5 => (object)['id' => 5, 'name' => 'Eve', 'age' => 60, 'status' => 'active'],
        ]);
    }

    // =========================================================================
    // Basic OR tests
    // =========================================================================

    public function testOrWithSinglePredicate(): void
    {
        $table = $this->createTable();
        $p = Predicate::from($table);

        $result = $table->or($p->eq('status', 'active'));

        $ids = [];
        foreach ($result as $row) {
            $ids[] = $row->id;
        }

        $this->assertSame([1, 3, 5], $ids);
    }

    public function testOrWithTwoPredicates(): void
    {
        $table = $this->createTable();
        $p = Predicate::from($table);

        // status = 'active' OR status = 'pending'
        $result = $table->or(
            $p->eq('status', 'active'),
            $p->eq('status', 'pending')
        );

        $ids = [];
        foreach ($result as $row) {
            $ids[] = $row->id;
        }
        sort($ids); // Union doesn't guarantee order

        // Alice(1), Carol(3), Dave(4), Eve(5)
        $this->assertSame([1, 3, 4, 5], $ids);
    }

    public function testOrWithComplexPredicates(): void
    {
        $table = $this->createTable();
        $p = Predicate::from($table);

        // (age < 25) OR (age >= 55)
        $result = $table->or(
            $p->lt('age', 25),
            $p->gte('age', 55)
        );

        $ids = [];
        foreach ($result as $row) {
            $ids[] = $row->id;
        }

        // Alice(20), Eve(60)
        $this->assertSame([1, 5], $ids);
    }

    public function testOrWithChainedPredicates(): void
    {
        $table = $this->createTable();
        $p = Predicate::from($table);

        // (status = 'active' AND age < 30) OR (status = 'pending')
        $result = $table->or(
            $p->eq('status', 'active')->lt('age', 30),
            $p->eq('status', 'pending')
        );

        $ids = [];
        foreach ($result as $row) {
            $ids[] = $row->id;
        }

        // Alice(1 - active & age 20), Dave(4 - pending)
        $this->assertSame([1, 4], $ids);
    }

    public function testOrWithThreePredicates(): void
    {
        $table = $this->createTable();
        $p = Predicate::from($table);

        // status = 'active' OR status = 'inactive' OR status = 'pending'
        $result = $table->or(
            $p->eq('status', 'active'),
            $p->eq('status', 'inactive'),
            $p->eq('status', 'pending')
        );

        $ids = [];
        foreach ($result as $row) {
            $ids[] = $row->id;
        }
        sort($ids); // Union doesn't guarantee order

        // All except none (all statuses covered)
        $this->assertSame([1, 2, 3, 4, 5], $ids);
    }

    public function testOrWithEmptyPredicatesReturnsEmptyTable(): void
    {
        $table = $this->createTable();

        $result = $table->or();

        // No predicates → EmptyTable
        $this->assertInstanceOf(\mini\Table\EmptyTable::class, $result);
        $this->assertSame(0, $result->count());
    }

    public function testOrFiltersOutEmptyTablePredicates(): void
    {
        $table = $this->createTable();
        $p = Predicate::from($table);
        $empty = \mini\Table\EmptyTable::from($table);

        // Mix of real predicates and EmptyTable
        $result = $table->or(
            $empty,
            $p->eq('status', 'active'),
            $empty
        );

        $ids = [];
        foreach ($result as $row) {
            $ids[] = $row->id;
        }

        // Only active rows (EmptyTable predicates filtered out)
        $this->assertSame([1, 3, 5], $ids);
    }

    public function testOrWithOnlyEmptyTablePredicatesReturnsEmpty(): void
    {
        $table = $this->createTable();
        $empty = \mini\Table\EmptyTable::from($table);

        $result = $table->or($empty, $empty);

        $this->assertInstanceOf(\mini\Table\EmptyTable::class, $result);
    }

    public function testOrWithSinglePredicateNoUnionOverhead(): void
    {
        $table = $this->createTable();
        $p = Predicate::from($table);

        // Single predicate should not create UnionTable
        $result = $table->or($p->eq('status', 'active'));

        // Should not be UnionTable
        $this->assertFalse($result instanceof \mini\Table\UnionTable);

        $ids = [];
        foreach ($result as $row) {
            $ids[] = $row->id;
        }
        $this->assertSame([1, 3, 5], $ids);
    }

    public function testOrDeduplicatesRows(): void
    {
        $table = $this->createTable();
        $p = Predicate::from($table);

        // age >= 20 OR age <= 60 (overlapping - all rows match both)
        $result = $table->or(
            $p->gte('age', 20),
            $p->lte('age', 60)
        );

        $ids = [];
        foreach ($result as $row) {
            $ids[] = $row->id;
        }

        // All rows, but deduplicated (union behavior)
        $this->assertSame([1, 2, 3, 4, 5], $ids);
    }

    // =========================================================================
    // Predicate inspection tests
    // =========================================================================

    public function testPredicateHasNoData(): void
    {
        $p = Predicate::from($this->createTable());

        $this->assertSame(0, $p->count());
        $this->assertFalse($p->exists());
    }

    public function testPredicateChainBuildsCorrectly(): void
    {
        $p = Predicate::from($this->createTable());

        $chain = $p->eq('status', 'active')->lt('age', 30);

        // Chain should be FilteredTable wrapping FilteredTable wrapping Predicate
        $this->assertInstanceOf(\mini\Table\FilteredTable::class, $chain);
    }
};

exit($test->run());
