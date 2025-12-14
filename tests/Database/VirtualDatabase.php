<?php
/**
 * Test VirtualDatabase Phase 1 implementation
 *
 * Tests: SELECT with WHERE/ORDER BY/LIMIT, column projection, INSERT/UPDATE/DELETE
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use mini\Test;
use mini\Database\VirtualDatabase;
use mini\Table\InMemoryTable;
use mini\Table\ColumnDef;
use mini\Table\ColumnType;
use mini\Table\IndexType;

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

    private function createVdb(): VirtualDatabase
    {
        $vdb = new VirtualDatabase();
        $vdb->registerTable('users', $this->createUsersTable());
        return $vdb;
    }

    // =========================================================================
    // Basic SELECT tests
    // =========================================================================

    public function testSelectAll(): void
    {
        $vdb = $this->createVdb();
        $rows = iterator_to_array($vdb->query('SELECT * FROM users'));

        $this->assertCount(3, $rows);
        $this->assertSame('Alice', $rows[0]->name);
        $this->assertSame('Bob', $rows[1]->name);
        $this->assertSame('Charlie', $rows[2]->name);
    }

    public function testSelectSpecificColumns(): void
    {
        $vdb = $this->createVdb();
        $rows = iterator_to_array($vdb->query('SELECT name, age FROM users'));

        $this->assertCount(3, $rows);
        $this->assertSame('Alice', $rows[0]->name);
        $this->assertSame(30, $rows[0]->age);
        $this->assertFalse(property_exists($rows[0], 'id'));
        $this->assertFalse(property_exists($rows[0], 'status'));
    }

    public function testSelectWithAlias(): void
    {
        $vdb = $this->createVdb();
        $rows = iterator_to_array($vdb->query('SELECT name AS username FROM users'));

        $this->assertSame('Alice', $rows[0]->username);
    }

    // =========================================================================
    // WHERE clause tests
    // =========================================================================

    public function testWhereEquals(): void
    {
        $vdb = $this->createVdb();
        $rows = iterator_to_array($vdb->query("SELECT * FROM users WHERE status = 'active'"));

        $this->assertCount(2, $rows);
        $this->assertSame('Alice', $rows[0]->name);
        $this->assertSame('Bob', $rows[1]->name);
    }

    public function testWhereWithPlaceholder(): void
    {
        $vdb = $this->createVdb();
        $rows = iterator_to_array($vdb->query('SELECT * FROM users WHERE status = ?', ['inactive']));

        $this->assertCount(1, $rows);
        $this->assertSame('Charlie', $rows[0]->name);
    }

    public function testWhereWithNamedPlaceholder(): void
    {
        $vdb = $this->createVdb();
        $rows = iterator_to_array($vdb->query('SELECT * FROM users WHERE status = :status', ['status' => 'active']));

        $this->assertCount(2, $rows);
    }

    public function testWhereGreaterThan(): void
    {
        $vdb = $this->createVdb();
        $rows = iterator_to_array($vdb->query('SELECT * FROM users WHERE age > 28'));

        $this->assertCount(2, $rows);
        $this->assertSame('Alice', $rows[0]->name);
        $this->assertSame('Charlie', $rows[1]->name);
    }

    public function testWhereAnd(): void
    {
        $vdb = $this->createVdb();
        $rows = iterator_to_array($vdb->query("SELECT * FROM users WHERE status = 'active' AND age > 28"));

        $this->assertCount(1, $rows);
        $this->assertSame('Alice', $rows[0]->name);
    }

    public function testWhereOr(): void
    {
        $vdb = $this->createVdb();
        $rows = iterator_to_array($vdb->query("SELECT * FROM users WHERE name = 'Alice' OR name = 'Charlie'"));

        $this->assertCount(2, $rows);
    }

    public function testWhereIn(): void
    {
        $vdb = $this->createVdb();
        $rows = iterator_to_array($vdb->query("SELECT * FROM users WHERE id IN (1, 3)"));

        $this->assertCount(2, $rows);
        $this->assertSame('Alice', $rows[0]->name);
        $this->assertSame('Charlie', $rows[1]->name);
    }

    public function testWhereBetween(): void
    {
        $vdb = $this->createVdb();
        $rows = iterator_to_array($vdb->query('SELECT * FROM users WHERE age BETWEEN 26 AND 32'));

        $this->assertCount(1, $rows);
        $this->assertSame('Alice', $rows[0]->name);
    }

    public function testWhereLike(): void
    {
        $vdb = $this->createVdb();
        $rows = iterator_to_array($vdb->query("SELECT * FROM users WHERE name LIKE 'A%'"));

        $this->assertCount(1, $rows);
        $this->assertSame('Alice', $rows[0]->name);
    }

    public function testWhereIsNull(): void
    {
        // Create table with NULL values
        $table = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('name', ColumnType::Text),
        );
        $table->insert(['id' => 1, 'name' => 'Alice']);
        $table->insert(['id' => 2, 'name' => null]);

        $vdb = new VirtualDatabase();
        $vdb->registerTable('test', $table);

        $rows = iterator_to_array($vdb->query('SELECT * FROM test WHERE name IS NULL'));
        $this->assertCount(1, $rows);
        $this->assertSame(2, $rows[0]->id);
    }

    // =========================================================================
    // ORDER BY tests
    // =========================================================================

    public function testOrderByAsc(): void
    {
        $vdb = $this->createVdb();
        $rows = iterator_to_array($vdb->query('SELECT * FROM users ORDER BY age ASC'));

        $this->assertSame('Bob', $rows[0]->name);
        $this->assertSame('Alice', $rows[1]->name);
        $this->assertSame('Charlie', $rows[2]->name);
    }

    public function testOrderByDesc(): void
    {
        $vdb = $this->createVdb();
        $rows = iterator_to_array($vdb->query('SELECT * FROM users ORDER BY age DESC'));

        $this->assertSame('Charlie', $rows[0]->name);
        $this->assertSame('Alice', $rows[1]->name);
        $this->assertSame('Bob', $rows[2]->name);
    }

    public function testOrderByMultiple(): void
    {
        $table = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('dept', ColumnType::Text),
            new ColumnDef('name', ColumnType::Text),
        );
        $table->insert(['id' => 1, 'dept' => 'A', 'name' => 'Zoe']);
        $table->insert(['id' => 2, 'dept' => 'B', 'name' => 'Alice']);
        $table->insert(['id' => 3, 'dept' => 'A', 'name' => 'Bob']);

        $vdb = new VirtualDatabase();
        $vdb->registerTable('staff', $table);

        $rows = iterator_to_array($vdb->query('SELECT * FROM staff ORDER BY dept ASC, name ASC'));

        $this->assertSame('Bob', $rows[0]->name);   // A, Bob
        $this->assertSame('Zoe', $rows[1]->name);   // A, Zoe
        $this->assertSame('Alice', $rows[2]->name); // B, Alice
    }

    // =========================================================================
    // LIMIT tests
    // =========================================================================

    public function testLimit(): void
    {
        $vdb = $this->createVdb();
        $rows = iterator_to_array($vdb->query('SELECT * FROM users LIMIT 2'));

        $this->assertCount(2, $rows);
    }

    public function testLimitWithPlaceholder(): void
    {
        $vdb = $this->createVdb();
        $rows = iterator_to_array($vdb->query('SELECT * FROM users LIMIT ?', [1]));

        $this->assertCount(1, $rows);
    }

    public function testOrderByWithLimit(): void
    {
        $vdb = $this->createVdb();
        $rows = iterator_to_array($vdb->query('SELECT * FROM users ORDER BY age DESC LIMIT 1'));

        $this->assertCount(1, $rows);
        $this->assertSame('Charlie', $rows[0]->name);
    }

    public function testOffset(): void
    {
        $vdb = $this->createVdb();
        $rows = iterator_to_array($vdb->query('SELECT * FROM users ORDER BY id OFFSET 1'));

        $this->assertCount(2, $rows);
        $this->assertSame('Bob', $rows[0]->name);
        $this->assertSame('Charlie', $rows[1]->name);
    }

    public function testLimitWithOffset(): void
    {
        $vdb = $this->createVdb();
        $rows = iterator_to_array($vdb->query('SELECT * FROM users ORDER BY id LIMIT 1 OFFSET 1'));

        $this->assertCount(1, $rows);
        $this->assertSame('Bob', $rows[0]->name);
    }

    public function testOffsetWithPlaceholder(): void
    {
        $vdb = $this->createVdb();
        $rows = iterator_to_array($vdb->query('SELECT * FROM users ORDER BY id LIMIT 2 OFFSET ?', [1]));

        $this->assertCount(2, $rows);
        $this->assertSame('Bob', $rows[0]->name);
    }

    // =========================================================================
    // Expression tests
    // =========================================================================

    public function testArithmeticExpression(): void
    {
        $vdb = $this->createVdb();
        $rows = iterator_to_array($vdb->query('SELECT name, age + 10 AS future_age FROM users WHERE id = 1'));

        $this->assertSame(40, $rows[0]->future_age);
    }

    public function testFunctionCall(): void
    {
        $vdb = $this->createVdb();
        $rows = iterator_to_array($vdb->query('SELECT UPPER(name) AS upper_name FROM users WHERE id = 1'));

        $this->assertSame('ALICE', $rows[0]->upper_name);
    }

    public function testCoalesce(): void
    {
        $table = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('nickname', ColumnType::Text),
            new ColumnDef('name', ColumnType::Text),
        );
        $table->insert(['id' => 1, 'nickname' => null, 'name' => 'Alice']);
        $table->insert(['id' => 2, 'nickname' => 'Bobby', 'name' => 'Bob']);

        $vdb = new VirtualDatabase();
        $vdb->registerTable('users', $table);

        $rows = iterator_to_array($vdb->query('SELECT COALESCE(nickname, name) AS display FROM users ORDER BY id'));

        $this->assertSame('Alice', $rows[0]->display);
        $this->assertSame('Bobby', $rows[1]->display);
    }

    // =========================================================================
    // INSERT tests
    // =========================================================================

    public function testInsert(): void
    {
        $table = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('name', ColumnType::Text),
        );

        $vdb = new VirtualDatabase();
        $vdb->registerTable('users', $table);

        $id = $vdb->exec("INSERT INTO users (id, name) VALUES (1, 'Alice')");
        $this->assertSame(1, $id);

        $rows = iterator_to_array($vdb->query('SELECT * FROM users'));
        $this->assertCount(1, $rows);
        $this->assertSame('Alice', $rows[0]->name);
    }

    public function testInsertWithPlaceholders(): void
    {
        $table = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('name', ColumnType::Text),
        );

        $vdb = new VirtualDatabase();
        $vdb->registerTable('users', $table);

        $id = $vdb->exec('INSERT INTO users (id, name) VALUES (?, ?)', [1, 'Bob']);
        $this->assertSame(1, $id);

        $rows = iterator_to_array($vdb->query('SELECT * FROM users'));
        $this->assertSame('Bob', $rows[0]->name);
    }

    // =========================================================================
    // UPDATE tests
    // =========================================================================

    public function testUpdate(): void
    {
        $vdb = $this->createVdb();
        $affected = $vdb->exec("UPDATE users SET status = 'archived' WHERE status = 'inactive'");

        $this->assertSame(1, $affected);

        $rows = iterator_to_array($vdb->query("SELECT * FROM users WHERE status = 'archived'"));
        $this->assertCount(1, $rows);
        $this->assertSame('Charlie', $rows[0]->name);
    }

    public function testUpdateWithPlaceholders(): void
    {
        $vdb = $this->createVdb();
        $affected = $vdb->exec('UPDATE users SET status = ? WHERE id = ?', ['banned', 2]);

        $this->assertSame(1, $affected);

        $rows = iterator_to_array($vdb->query('SELECT * FROM users WHERE id = 2'));
        $this->assertSame('banned', $rows[0]->status);
    }

    public function testUpdateMultipleRows(): void
    {
        $vdb = $this->createVdb();
        $affected = $vdb->exec("UPDATE users SET status = 'modified' WHERE status = 'active'");

        $this->assertSame(2, $affected);
    }

    // =========================================================================
    // DELETE tests
    // =========================================================================

    public function testDelete(): void
    {
        $vdb = $this->createVdb();
        $affected = $vdb->exec("DELETE FROM users WHERE status = 'inactive'");

        $this->assertSame(1, $affected);

        $rows = iterator_to_array($vdb->query('SELECT * FROM users'));
        $this->assertCount(2, $rows);
    }

    public function testDeleteWithPlaceholder(): void
    {
        $vdb = $this->createVdb();
        $affected = $vdb->exec('DELETE FROM users WHERE id = ?', [1]);

        $this->assertSame(1, $affected);

        $rows = iterator_to_array($vdb->query('SELECT * FROM users'));
        $this->assertCount(2, $rows);
    }

    public function testDeleteMultipleRows(): void
    {
        $vdb = $this->createVdb();
        $affected = $vdb->exec("DELETE FROM users WHERE status = 'active'");

        $this->assertSame(2, $affected);

        $rows = iterator_to_array($vdb->query('SELECT * FROM users'));
        $this->assertCount(1, $rows);
    }

    // =========================================================================
    // Error handling tests
    // =========================================================================

    public function testSelectFromUnknownTableThrows(): void
    {
        $vdb = $this->createVdb();

        $this->expectException(RuntimeException::class);
        iterator_to_array($vdb->query('SELECT * FROM nonexistent'));
    }

    public function testQueryWithNonSelectThrows(): void
    {
        $vdb = $this->createVdb();

        $this->expectException(RuntimeException::class);
        iterator_to_array($vdb->query("INSERT INTO users (id, name) VALUES (1, 'test')"));
    }

    public function testExecWithSelectThrows(): void
    {
        $vdb = $this->createVdb();

        $this->expectException(RuntimeException::class);
        $vdb->exec('SELECT * FROM users');
    }

    // =========================================================================
    // Table registration tests
    // =========================================================================

    public function testCaseInsensitiveTableNames(): void
    {
        $vdb = $this->createVdb();

        $rows1 = iterator_to_array($vdb->query('SELECT * FROM users'));
        $rows2 = iterator_to_array($vdb->query('SELECT * FROM USERS'));
        $rows3 = iterator_to_array($vdb->query('SELECT * FROM Users'));

        $this->assertCount(3, $rows1);
        $this->assertCount(3, $rows2);
        $this->assertCount(3, $rows3);
    }

    public function testGetTable(): void
    {
        $table = $this->createUsersTable();
        $vdb = new VirtualDatabase();
        $vdb->registerTable('users', $table);

        $this->assertSame($table, $vdb->getTable('users'));
        $this->assertSame($table, $vdb->getTable('USERS'));
        $this->assertNull($vdb->getTable('nonexistent'));
    }
};

exit($test->run());
