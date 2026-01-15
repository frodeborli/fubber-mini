<?php
/**
 * Test Predicate and or() functionality
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Test;
use mini\Table\GeneratorTable;
use mini\Table\Predicate;
use mini\Table\ColumnDef;
use mini\Table\Types\ColumnType;
use mini\Table\Types\IndexType;

$test = new class extends Test {

    protected function createTable(): GeneratorTable
    {
        return new GeneratorTable(
            fn() => yield from [
                1 => (object)['id' => 1, 'name' => 'Alice', 'age' => 20, 'status' => 'active'],
                2 => (object)['id' => 2, 'name' => 'Bob', 'age' => 30, 'status' => 'inactive'],
                3 => (object)['id' => 3, 'name' => 'Carol', 'age' => 40, 'status' => 'active'],
                4 => (object)['id' => 4, 'name' => 'Dave', 'age' => 50, 'status' => 'pending'],
                5 => (object)['id' => 5, 'name' => 'Eve', 'age' => 60, 'status' => 'active'],
            ],
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('name', ColumnType::Text),
            new ColumnDef('age', ColumnType::Int),
            new ColumnDef('status', ColumnType::Text),
        );
    }

    // =========================================================================
    // Basic OR tests
    // =========================================================================

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

    public function testOrFiltersOutNeverPredicates(): void
    {
        $table = $this->createTable();
        $p = Predicate::from($table);
        $never = Predicate::never();

        // Mix of real predicates and never() predicates
        // or() now requires at least 2 predicates
        $result = $table->or(
            $never,
            $p->eq('status', 'active'),
            $p->eq('status', 'pending')
        );

        $ids = [];
        foreach ($result as $row) {
            $ids[] = $row->id;
        }
        sort($ids);

        // Active (1, 3, 5) and pending (4) rows
        $this->assertSame([1, 3, 4, 5], $ids);
    }

    public function testOrWithOnlyNeverPredicatesReturnsEmpty(): void
    {
        $table = $this->createTable();
        $never = Predicate::never();

        $result = $table->or($never, $never);

        $this->assertInstanceOf(\mini\Table\Utility\EmptyTable::class, $result);
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

    public function testPredicateIsEmpty(): void
    {
        $p = Predicate::from($this->createTable());

        // Fresh predicate has no conditions
        $this->assertTrue($p->isEmpty());
        $this->assertTrue($p->isBound());
    }

    public function testPredicateChainBuildsCorrectly(): void
    {
        $p = Predicate::from($this->createTable());

        $chain = $p->eq('status', 'active')->lt('age', 30);

        // Chain should be a Predicate with multiple conditions
        $this->assertInstanceOf(Predicate::class, $chain);
        $this->assertFalse($chain->isEmpty());
        $this->assertCount(2, $chain->getConditions());
    }
};

exit($test->run());
