<?php
/**
 * Test AliasTable - table/column aliasing for JOINs and correlated subqueries
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Test;
use mini\Table\Wrappers\AliasTable;
use mini\Table\InMemoryTable;
use mini\Table\ColumnDef;
use mini\Table\Types\ColumnType;
use mini\Table\Types\IndexType;
use mini\Table\Predicate;

$test = new class extends Test {

    private function createUsersTable(): InMemoryTable
    {
        $table = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('name', ColumnType::Text),
            new ColumnDef('age', ColumnType::Int),
        );

        $table->insert(['id' => 1, 'name' => 'Alice', 'age' => 30]);
        $table->insert(['id' => 2, 'name' => 'Bob', 'age' => 25]);
        $table->insert(['id' => 3, 'name' => 'Charlie', 'age' => 35]);

        return $table;
    }

    // =========================================================================
    // Basic table aliasing
    // =========================================================================

    public function testWithAliasReturnsAliasTable(): void
    {
        $users = $this->createUsersTable();
        $aliased = $users->withAlias('u');

        $this->assertInstanceOf(AliasTable::class, $aliased);
    }

    public function testColumnsArePrefixedWithAlias(): void
    {
        $aliased = $this->createUsersTable()->withAlias('u');

        $columns = array_keys($aliased->getColumns());
        $this->assertSame(['u.id', 'u.name', 'u.age'], $columns);
    }

    public function testRowsHaveAliasedPropertyNames(): void
    {
        $aliased = $this->createUsersTable()->withAlias('u');

        $rows = array_values(iterator_to_array($aliased));
        $this->assertCount(3, $rows);

        // Check aliased property names
        $this->assertTrue(property_exists($rows[0], 'u.id'));
        $this->assertTrue(property_exists($rows[0], 'u.name'));
        $this->assertTrue(property_exists($rows[0], 'u.age'));

        // Original names should not exist
        $this->assertFalse(property_exists($rows[0], 'id'));
        $this->assertFalse(property_exists($rows[0], 'name'));
    }

    public function testRowValuesAreCorrect(): void
    {
        $aliased = $this->createUsersTable()->withAlias('u');

        $rows = array_values(iterator_to_array($aliased));
        $this->assertSame(1, $rows[0]->{'u.id'});
        $this->assertSame('Alice', $rows[0]->{'u.name'});
        $this->assertSame(30, $rows[0]->{'u.age'});
    }

    public function testColumnDefPreservesIndexType(): void
    {
        $aliased = $this->createUsersTable()->withAlias('u');

        $columns = $aliased->getColumns();
        $this->assertSame(IndexType::Primary, $columns['u.id']->index);
    }

    // =========================================================================
    // Column aliasing (renaming)
    // =========================================================================

    public function testColumnAliasRenamesColumn(): void
    {
        $aliased = $this->createUsersTable()->withAlias('u', ['name' => 'username']);

        $columns = array_keys($aliased->getColumns());
        $this->assertSame(['u.id', 'u.username', 'u.age'], $columns);
    }

    public function testColumnAliasInRows(): void
    {
        $aliased = $this->createUsersTable()->withAlias('u', ['name' => 'username']);

        $rows = array_values(iterator_to_array($aliased));
        $this->assertTrue(property_exists($rows[0], 'u.username'));
        $this->assertFalse(property_exists($rows[0], 'u.name'));
        $this->assertSame('Alice', $rows[0]->{'u.username'});
    }

    // =========================================================================
    // Stacking aliases - re-aliasing an aliased table
    // =========================================================================

    public function testRealiasReplacesTablePrefix(): void
    {
        $aliased = $this->createUsersTable()->withAlias('x');
        $realiased = $aliased->withAlias('y');

        // Should be y.id, not y.x.id
        $columns = array_keys($realiased->getColumns());
        $this->assertSame(['y.id', 'y.name', 'y.age'], $columns);
    }

    public function testRealiasPreservesColumnAliases(): void
    {
        $aliased = $this->createUsersTable()->withAlias('x', ['name' => 'username']);
        $realiased = $aliased->withAlias('y');

        // Column alias should be preserved: y.username, not y.name
        $columns = array_keys($realiased->getColumns());
        $this->assertSame(['y.id', 'y.username', 'y.age'], $columns);
    }

    public function testRealiasCanAddColumnAliases(): void
    {
        $aliased = $this->createUsersTable()->withAlias('x', ['name' => 'username']);
        $realiased = $aliased->withAlias('y', ['age' => 'years']);

        // Should merge: username preserved, age renamed to years
        $columns = array_keys($realiased->getColumns());
        $this->assertSame(['y.id', 'y.username', 'y.years'], $columns);
    }

    public function testRealiasCanOverrideColumnAliases(): void
    {
        $aliased = $this->createUsersTable()->withAlias('x', ['name' => 'username']);
        $realiased = $aliased->withAlias('y', ['name' => 'fullname']);

        // New column alias should override old
        $columns = array_keys($realiased->getColumns());
        $this->assertSame(['y.id', 'y.fullname', 'y.age'], $columns);
    }

    public function testRealiasedRowsHaveCorrectValues(): void
    {
        $aliased = $this->createUsersTable()->withAlias('x');
        $realiased = $aliased->withAlias('y');

        $rows = array_values(iterator_to_array($realiased));
        $this->assertSame(1, $rows[0]->{'y.id'});
        $this->assertSame('Alice', $rows[0]->{'y.name'});
    }

    // =========================================================================
    // Strict column name requirement
    // =========================================================================

    public function testEqRequiresAliasedColumnName(): void
    {
        $aliased = $this->createUsersTable()->withAlias('u');

        $this->expectException(InvalidArgumentException::class);
        $aliased->eq('id', 1);  // Should fail - must use 'u.id'
    }

    public function testEqWithAliasedNameWorks(): void
    {
        $aliased = $this->createUsersTable()->withAlias('u');
        $filtered = $aliased->eq('u.id', 1);

        $rows = array_values(iterator_to_array($filtered));
        $this->assertCount(1, $rows);
        $this->assertSame('Alice', $rows[0]->{'u.name'});
    }

    // =========================================================================
    // Filter methods delegate to source with translated column names
    // =========================================================================

    public function testLtDelegatesToSource(): void
    {
        $aliased = $this->createUsersTable()->withAlias('u');
        $filtered = $aliased->lt('u.age', 30);

        $rows = array_values(iterator_to_array($filtered));
        $this->assertCount(1, $rows);
        $this->assertSame('Bob', $rows[0]->{'u.name'});
    }

    public function testLteDelegatesToSource(): void
    {
        $aliased = $this->createUsersTable()->withAlias('u');
        $filtered = $aliased->lte('u.age', 30);

        $rows = array_values(iterator_to_array($filtered));
        $this->assertCount(2, $rows);
    }

    public function testGtDelegatesToSource(): void
    {
        $aliased = $this->createUsersTable()->withAlias('u');
        $filtered = $aliased->gt('u.age', 30);

        $rows = array_values(iterator_to_array($filtered));
        $this->assertCount(1, $rows);
        $this->assertSame('Charlie', $rows[0]->{'u.name'});
    }

    public function testGteDelegatesToSource(): void
    {
        $aliased = $this->createUsersTable()->withAlias('u');
        $filtered = $aliased->gte('u.age', 30);

        $rows = array_values(iterator_to_array($filtered));
        $this->assertCount(2, $rows);
    }

    public function testLikeDelegatesToSource(): void
    {
        $aliased = $this->createUsersTable()->withAlias('u');
        $filtered = $aliased->like('u.name', 'A%');

        $rows = array_values(iterator_to_array($filtered));
        $this->assertCount(1, $rows);
        $this->assertSame('Alice', $rows[0]->{'u.name'});
    }

    public function testInDelegatesToSource(): void
    {
        $aliased = $this->createUsersTable()->withAlias('u');
        $set = new \mini\Table\Utility\Set('u.id', [1, 2]);
        $filtered = $aliased->in('u.id', $set);

        $rows = array_values(iterator_to_array($filtered));
        $this->assertCount(2, $rows);
    }

    public function testChainedFilters(): void
    {
        $aliased = $this->createUsersTable()->withAlias('u');
        $filtered = $aliased
            ->gte('u.age', 25)
            ->lte('u.age', 30);

        $rows = array_values(iterator_to_array($filtered));
        $this->assertCount(2, $rows);  // Alice (30), Bob (25)
    }

    // =========================================================================
    // Order, limit, offset
    // =========================================================================

    public function testOrderWithAliasedColumn(): void
    {
        $aliased = $this->createUsersTable()->withAlias('u');
        $ordered = $aliased->order('u.age DESC');

        $rows = array_values(iterator_to_array($ordered));
        $this->assertSame('Charlie', $rows[0]->{'u.name'});  // age 35
        $this->assertSame('Alice', $rows[1]->{'u.name'});    // age 30
        $this->assertSame('Bob', $rows[2]->{'u.name'});      // age 25
    }

    public function testOrderRequiresAliasedColumn(): void
    {
        $aliased = $this->createUsersTable()->withAlias('u');

        $this->expectException(InvalidArgumentException::class);
        $aliased->order('age DESC');  // Should fail - must use 'u.age'
    }

    public function testLimitWorks(): void
    {
        $aliased = $this->createUsersTable()->withAlias('u');
        $limited = $aliased->limit(2);

        $this->assertCount(2, iterator_to_array($limited));
    }

    public function testOffsetWorks(): void
    {
        $aliased = $this->createUsersTable()->withAlias('u');
        $offset = $aliased->offset(1);

        $this->assertCount(2, iterator_to_array($offset));
    }

    public function testOrderLimitOffsetCombined(): void
    {
        $aliased = $this->createUsersTable()->withAlias('u');
        $result = $aliased
            ->order('u.age ASC')
            ->offset(1)
            ->limit(1);

        $rows = array_values(iterator_to_array($result));
        $this->assertCount(1, $rows);
        $this->assertSame('Alice', $rows[0]->{'u.name'});  // Middle age (30)
    }

    // =========================================================================
    // OR predicates with aliased columns
    // =========================================================================

    public function testOrWithAliasedPredicates(): void
    {
        $aliased = $this->createUsersTable()->withAlias('u');

        // WHERE u.age < 26 OR u.age > 34
        $filtered = $aliased->or(
            (new Predicate())->lt('u.age', 26),
            (new Predicate())->gt('u.age', 34)
        );

        $rows = array_values(iterator_to_array($filtered));
        $this->assertCount(2, $rows);  // Bob (25), Charlie (35)

        $names = array_map(fn($r) => $r->{'u.name'}, $rows);
        sort($names);
        $this->assertSame(['Bob', 'Charlie'], $names);
    }

    public function testOrWithCompoundPredicates(): void
    {
        $aliased = $this->createUsersTable()->withAlias('u');

        // WHERE (u.age < 26) OR (u.age > 30 AND u.name LIKE 'C%')
        $filtered = $aliased->or(
            (new Predicate())->lt('u.age', 26),
            (new Predicate())->gt('u.age', 30)->like('u.name', 'C%')
        );

        $rows = array_values(iterator_to_array($filtered));
        $this->assertCount(2, $rows);  // Bob (25), Charlie (35 and starts with C)
    }

    // =========================================================================
    // Column projection
    // =========================================================================

    public function testColumnsWithAliasedNames(): void
    {
        $aliased = $this->createUsersTable()->withAlias('u');
        $projected = $aliased->columns('u.id', 'u.name');

        $columns = array_keys($projected->getColumns());
        $this->assertSame(['u.id', 'u.name'], $columns);

        $rows = array_values(iterator_to_array($projected));
        $this->assertTrue(property_exists($rows[0], 'u.id'));
        $this->assertTrue(property_exists($rows[0], 'u.name'));
        $this->assertFalse(property_exists($rows[0], 'u.age'));
    }

    public function testColumnsRequiresAliasedNames(): void
    {
        $aliased = $this->createUsersTable()->withAlias('u');

        $this->expectException(InvalidArgumentException::class);
        $aliased->columns('id', 'name');  // Should fail
    }

    // =========================================================================
    // Other methods
    // =========================================================================

    public function testCountWorks(): void
    {
        $aliased = $this->createUsersTable()->withAlias('u');
        $this->assertSame(3, $aliased->count());

        $filtered = $aliased->eq('u.id', 1);
        $this->assertSame(1, $filtered->count());
    }

    public function testExistsWorks(): void
    {
        $aliased = $this->createUsersTable()->withAlias('u');
        $this->assertTrue($aliased->exists());

        $filtered = $aliased->eq('u.id', 999);
        $this->assertFalse($filtered->exists());
    }

    public function testLoadReturnsAliasedRow(): void
    {
        $aliased = $this->createUsersTable()->withAlias('u');
        $row = $aliased->load(1);

        $this->assertNotNull($row);
        $this->assertTrue(property_exists($row, 'u.id'));
        $this->assertSame(1, $row->{'u.id'});
        $this->assertSame('Alice', $row->{'u.name'});
    }

    public function testHasWithAliasedMember(): void
    {
        $aliased = $this->createUsersTable()->withAlias('u')->columns('u.id');

        $this->assertTrue($aliased->has((object)['u.id' => 1]));
        $this->assertFalse($aliased->has((object)['u.id' => 999]));
    }

    public function testDistinctWorks(): void
    {
        // Create table with duplicates
        $table = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('status', ColumnType::Text),
        );
        $table->insert(['id' => 1, 'status' => 'active']);
        $table->insert(['id' => 2, 'status' => 'active']);
        $table->insert(['id' => 3, 'status' => 'inactive']);

        $aliased = $table->withAlias('t')->columns('t.status')->distinct();

        $rows = array_values(iterator_to_array($aliased));
        $this->assertCount(2, $rows);  // 'active' and 'inactive'
    }
};

exit($test->run());
