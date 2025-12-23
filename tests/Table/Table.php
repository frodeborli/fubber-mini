<?php
/**
 * Test Table wrapper with parameter binding
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use mini\Test;
use mini\Table\Table;
use mini\Table\InMemoryTable;
use mini\Table\ColumnDef;
use mini\Table\Types\ColumnType;
use mini\Table\Types\IndexType;

$test = new class extends Test {

    private function createUsersTable(): InMemoryTable
    {
        $table = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('name', ColumnType::Text),
            new ColumnDef('age', ColumnType::Int),
            new ColumnDef('status', ColumnType::Text),
        );

        $table->insert(['id' => 1, 'name' => 'Alice', 'age' => 30, 'status' => 'active']);
        $table->insert(['id' => 2, 'name' => 'Bob', 'age' => 25, 'status' => 'active']);
        $table->insert(['id' => 3, 'name' => 'Charlie', 'age' => 35, 'status' => 'inactive']);

        return $table;
    }

    // =========================================================================
    // Basic wrapping tests
    // =========================================================================

    public function testFromWrapsTable(): void
    {
        $source = $this->createUsersTable();
        $table = Table::from($source);

        $rows = iterator_to_array($table);
        $this->assertCount(3, $rows);
    }

    public function testFromReturnsExistingTable(): void
    {
        $source = $this->createUsersTable();
        $table = Table::from($source);
        $table2 = Table::from($table);

        $this->assertSame($table, $table2);
    }

    public function testDelegatesEq(): void
    {
        $table = Table::from($this->createUsersTable());
        $filtered = $table->eq('status', 'active');

        $rows = iterator_to_array($filtered);
        $this->assertCount(2, $rows);
    }

    public function testDelegatesColumns(): void
    {
        $table = Table::from($this->createUsersTable());
        $projected = $table->columns('name');

        $rows = array_values(iterator_to_array($projected));
        $this->assertCount(3, $rows);
        $this->assertTrue(property_exists($rows[0], 'name'));
        $this->assertFalse(property_exists($rows[0], 'age'));
    }

    public function testDelegatesLimit(): void
    {
        $table = Table::from($this->createUsersTable());
        $limited = $table->limit(2);

        $rows = iterator_to_array($limited);
        $this->assertCount(2, $rows);
    }

    public function testDelegatesOrder(): void
    {
        $table = Table::from($this->createUsersTable());
        $ordered = $table->order('age DESC');

        $rows = array_values(iterator_to_array($ordered));
        $this->assertSame('Charlie', $rows[0]->name);
    }

    // =========================================================================
    // Binding tests
    // =========================================================================

    public function testEqBindAndBind(): void
    {
        $table = Table::from($this->createUsersTable())
            ->eqBind('status', ':status');

        // Before binding - should have unbound param
        $this->assertFalse($table->isBound());
        $this->assertSame([':status'], $table->getUnboundParameters());

        // After binding
        $bound = $table->bind([':status' => 'active']);
        $this->assertTrue($bound->isBound());

        $rows = iterator_to_array($bound);
        $this->assertCount(2, $rows);
    }

    public function testPositionalBinding(): void
    {
        $table = Table::from($this->createUsersTable())
            ->eqBind('status', 0)
            ->eqBind('age', 1);

        $bound = $table->bind([0 => 'active', 1 => 30]);

        $rows = array_values(iterator_to_array($bound));
        $this->assertCount(1, $rows);
        $this->assertSame('Alice', $rows[0]->name);
    }

    public function testPartialBinding(): void
    {
        $table = Table::from($this->createUsersTable())
            ->eqBind('status', ':status')
            ->gtBind('age', ':min_age');

        // Bind one param
        $partial = $table->bind([':status' => 'active']);
        $this->assertFalse($partial->isBound());
        $this->assertSame([':min_age'], $partial->getUnboundParameters());

        // Bind remaining
        $full = $partial->bind([':min_age' => 28]);
        $this->assertTrue($full->isBound());

        $rows = array_values(iterator_to_array($full));
        $this->assertCount(1, $rows);
        $this->assertSame('Alice', $rows[0]->name);
    }

    public function testRebindableQuery(): void
    {
        // Create reusable query template
        $template = Table::from($this->createUsersTable())
            ->eqBind('status', ':status')
            ->columns('name');

        // Bind different values
        $active = $template->bind([':status' => 'active']);
        $inactive = $template->bind([':status' => 'inactive']);

        $activeRows = iterator_to_array($active);
        $inactiveRows = array_values(iterator_to_array($inactive));

        $this->assertCount(2, $activeRows);
        $this->assertCount(1, $inactiveRows);
        $this->assertSame('Charlie', $inactiveRows[0]->name);
    }

    public function testSameParamMultiplePredicates(): void
    {
        // WHERE age > :val AND age < :val (range with same param)
        // Using age > 25 AND age < 35 should give Alice (30)
        $table = Table::from($this->createUsersTable())
            ->gtBind('age', ':val')
            ->ltBind('age', ':val2');

        // But more usefully: price > :margin AND cost < :margin
        // Simulating with: age > :bound AND id < :bound
        // age > 2 AND id < 2 = nobody
        // age > 1 AND id < 3 = Alice (age 30 > 1, id 1 < 3), Bob (age 25 > 1, id 2 < 3)
        $table2 = Table::from($this->createUsersTable())
            ->gtBind('age', ':bound')
            ->ltBind('id', ':bound');

        $rows = array_values(iterator_to_array($table2->bind([':bound' => 3])));
        // age > 3: all pass. id < 3: Alice (1), Bob (2)
        $this->assertCount(2, $rows);
    }

    public function testAllBindOperators(): void
    {
        $table = Table::from($this->createUsersTable());

        // ltBind
        $lt = $table->ltBind('age', ':max')->bind([':max' => 30]);
        $this->assertCount(1, iterator_to_array($lt)); // Bob (25)

        // lteBind
        $lte = $table->lteBind('age', ':max')->bind([':max' => 30]);
        $this->assertCount(2, iterator_to_array($lte)); // Bob (25), Alice (30)

        // gtBind
        $gt = $table->gtBind('age', ':min')->bind([':min' => 30]);
        $this->assertCount(1, iterator_to_array($gt)); // Charlie (35)

        // gteBind
        $gte = $table->gteBind('age', ':min')->bind([':min' => 30]);
        $this->assertCount(2, iterator_to_array($gte)); // Alice (30), Charlie (35)

        // likeBind
        $like = $table->likeBind('name', ':pattern')->bind([':pattern' => 'A%']);
        $this->assertCount(1, iterator_to_array($like)); // Alice
    }

    // =========================================================================
    // Error handling tests
    // =========================================================================

    public function testIteratingUnboundThrows(): void
    {
        $table = Table::from($this->createUsersTable())
            ->eqBind('status', ':status');

        $this->expectException(RuntimeException::class);
        iterator_to_array($table);
    }

    public function testCountUnboundThrows(): void
    {
        $table = Table::from($this->createUsersTable())
            ->eqBind('status', ':status');

        $this->expectException(RuntimeException::class);
        $table->count();
    }

    public function testExistsUnboundThrows(): void
    {
        $table = Table::from($this->createUsersTable())
            ->eqBind('status', ':status');

        $this->expectException(RuntimeException::class);
        $table->exists();
    }

    public function testBindUnknownParamThrows(): void
    {
        $table = Table::from($this->createUsersTable())
            ->eqBind('status', ':status');

        $this->expectException(InvalidArgumentException::class);
        $table->bind([':unknown' => 'value']);
    }

    // =========================================================================
    // Immutability tests
    // =========================================================================

    public function testBindIsImmutable(): void
    {
        $template = Table::from($this->createUsersTable())
            ->eqBind('status', ':status');

        $bound = $template->bind([':status' => 'active']);

        // Original should still have unbound param
        $this->assertFalse($template->isBound());
        $this->assertTrue($bound->isBound());
    }

    public function testEqBindIsImmutable(): void
    {
        $table = Table::from($this->createUsersTable());
        $withBind = $table->eqBind('status', ':status');

        // Original should have no binds
        $this->assertTrue($table->isBound());
        $this->assertFalse($withBind->isBound());
    }

    public function testDelegatedMethodsAreImmutable(): void
    {
        $table = Table::from($this->createUsersTable())
            ->eqBind('status', ':status');

        $filtered = $table->eq('age', 30);

        // Both should still have the unbound param
        $this->assertFalse($table->isBound());
        $this->assertFalse($filtered->isBound());
    }

    // =========================================================================
    // Deferred columns tests
    // =========================================================================

    public function testColumnsBeforeBindStillWorks(): void
    {
        // This is the key test: columns() before eqBind() should still allow
        // filtering on the projected-away column
        $table = Table::from($this->createUsersTable())
            ->columns('id', 'name')           // Project away 'status'
            ->eqBind('status', ':status')     // But we still want to filter on it
            ->bind([':status' => 'active']);

        $rows = array_values(iterator_to_array($table));
        $this->assertCount(2, $rows);
        // Should only have projected columns
        $this->assertTrue(property_exists($rows[0], 'name'));
        $this->assertFalse(property_exists($rows[0], 'status'));
    }

    public function testColumnsAfterBindAlsoWorks(): void
    {
        // The "normal" order should also work
        $table = Table::from($this->createUsersTable())
            ->eqBind('status', ':status')
            ->columns('id', 'name')
            ->bind([':status' => 'active']);

        $rows = array_values(iterator_to_array($table));
        $this->assertCount(2, $rows);
        $this->assertFalse(property_exists($rows[0], 'status'));
    }

    public function testGetColumnsReflectsDeferredProjection(): void
    {
        $table = Table::from($this->createUsersTable())
            ->columns('id', 'name');

        $cols = array_keys($table->getColumns());
        $this->assertSame(['id', 'name'], $cols);
    }

    public function testMultipleColumnsCallsNarrow(): void
    {
        $table = Table::from($this->createUsersTable())
            ->columns('id', 'name', 'age')
            ->columns('id', 'name');  // Narrows to intersection

        $cols = array_keys($table->getColumns());
        $this->assertSame(['id', 'name'], $cols);
    }

    public function testColumnsCannotExpand(): void
    {
        // Security: once projected, cannot re-add removed columns
        $table = Table::from($this->createUsersTable())
            ->columns('id', 'name');  // Hides 'status' and 'age'

        $attempted = $table->columns('id', 'name', 'status');  // Try to add 'status' back

        // Should still only have id, name (intersection)
        $cols = array_keys($attempted->getColumns());
        $this->assertSame(['id', 'name'], $cols);
    }

    public function testColumnsCanNarrowToEmpty(): void
    {
        $table = Table::from($this->createUsersTable())
            ->columns('id', 'name')
            ->columns('status');  // Not in previous projection

        // Intersection is empty
        $cols = array_keys($table->getColumns());
        $this->assertSame([], $cols);
    }
};

exit($test->run());
