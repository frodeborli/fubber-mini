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

        // May throw SqlSyntaxException (when wrapping fails) or RuntimeException (if it gets to executor)
        $this->expectException(\Exception::class);
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

    // =========================================================================
    // IN Subquery tests
    // =========================================================================

    private function createOrdersTable(): InMemoryTable
    {
        $table = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('user_id', ColumnType::Int),
            new ColumnDef('amount', ColumnType::Int),
            new ColumnDef('status', ColumnType::Text),
        );

        $table->insert(['id' => 1, 'user_id' => 1, 'amount' => 100, 'status' => 'shipped']);
        $table->insert(['id' => 2, 'user_id' => 1, 'amount' => 200, 'status' => 'pending']);
        $table->insert(['id' => 3, 'user_id' => 2, 'amount' => 150, 'status' => 'shipped']);
        $table->insert(['id' => 4, 'user_id' => 3, 'amount' => 300, 'status' => 'cancelled']);

        return $table;
    }

    private function createVdbWithOrders(): VirtualDatabase
    {
        $vdb = new VirtualDatabase();
        $vdb->registerTable('users', $this->createUsersTable());
        $vdb->registerTable('orders', $this->createOrdersTable());
        return $vdb;
    }

    public function testInSubquerySameColumnName(): void
    {
        // Users who have shipped orders (using id = id match)
        $vdb = $this->createVdbWithOrders();
        $rows = iterator_to_array($vdb->query(
            "SELECT * FROM users WHERE id IN (SELECT user_id FROM orders WHERE status = 'shipped')"
        ));

        // Users 1 and 2 have shipped orders
        $this->assertCount(2, $rows);
        $names = array_map(fn($r) => $r->name, $rows);
        $this->assertTrue(in_array('Alice', $names, true));
        $this->assertTrue(in_array('Bob', $names, true));
    }

    public function testInSubqueryDifferentColumnName(): void
    {
        // This tests the ColumnMappedSet wrapper
        // user_id from orders maps to id in users
        $vdb = $this->createVdbWithOrders();
        $rows = iterator_to_array($vdb->query(
            "SELECT * FROM users WHERE id IN (SELECT user_id FROM orders WHERE amount > 200)"
        ));

        // Only user 3 (Charlie) has an order > 200
        $this->assertCount(1, $rows);
        $this->assertSame('Charlie', $rows[0]->name);
    }

    public function testNotInSubquery(): void
    {
        // Users who don't have shipped orders
        $vdb = $this->createVdbWithOrders();
        $rows = iterator_to_array($vdb->query(
            "SELECT * FROM users WHERE id NOT IN (SELECT user_id FROM orders WHERE status = 'shipped')"
        ));

        // Only Charlie (id=3) has no shipped orders
        $this->assertCount(1, $rows);
        $this->assertSame('Charlie', $rows[0]->name);
    }

    public function testInSubqueryWithPlaceholder(): void
    {
        $vdb = $this->createVdbWithOrders();
        $rows = iterator_to_array($vdb->query(
            "SELECT * FROM users WHERE id IN (SELECT user_id FROM orders WHERE status = ?)",
            ['pending']
        ));

        // Only user 1 (Alice) has pending orders
        $this->assertCount(1, $rows);
        $this->assertSame('Alice', $rows[0]->name);
    }

    public function testInSubqueryWithLimit(): void
    {
        $vdb = $this->createVdbWithOrders();
        $rows = iterator_to_array($vdb->query(
            "SELECT * FROM users WHERE id IN (SELECT user_id FROM orders ORDER BY amount DESC LIMIT 2)"
        ));

        // Top 2 orders by amount: id=4 (300, user 3), id=2 (200, user 1)
        $this->assertCount(2, $rows);
        $names = array_map(fn($r) => $r->name, $rows);
        $this->assertTrue(in_array('Alice', $names, true));
        $this->assertTrue(in_array('Charlie', $names, true));
    }

    public function testInSubqueryReturnsEmpty(): void
    {
        $vdb = $this->createVdbWithOrders();
        $rows = iterator_to_array($vdb->query(
            "SELECT * FROM users WHERE id IN (SELECT user_id FROM orders WHERE status = 'nonexistent')"
        ));

        $this->assertCount(0, $rows);
    }

    public function testInSubqueryWithMultipleConditions(): void
    {
        $vdb = $this->createVdbWithOrders();
        // Users who are active AND have shipped orders
        $rows = iterator_to_array($vdb->query(
            "SELECT * FROM users WHERE status = 'active' AND id IN (SELECT user_id FROM orders WHERE status = 'shipped')"
        ));

        // Alice (active, has shipped) and Bob (active, has shipped)
        $this->assertCount(2, $rows);
    }

    // =========================================================================
    // EXISTS Subquery tests
    // =========================================================================

    public function testExistsNonCorrelated(): void
    {
        $vdb = $this->createVdbWithOrders();
        // If any shipped orders exist, return all users
        $rows = iterator_to_array($vdb->query(
            "SELECT * FROM users WHERE EXISTS (SELECT 1 FROM orders WHERE status = 'shipped')"
        ));

        // Shipped orders exist, so all 3 users returned
        $this->assertCount(3, $rows);
    }

    public function testExistsNonCorrelatedFalse(): void
    {
        $vdb = $this->createVdbWithOrders();
        // No orders with status 'nonexistent', so EXISTS is false
        $rows = iterator_to_array($vdb->query(
            "SELECT * FROM users WHERE EXISTS (SELECT 1 FROM orders WHERE status = 'nonexistent')"
        ));

        // No rows returned
        $this->assertCount(0, $rows);
    }

    public function testNotExistsNonCorrelated(): void
    {
        $vdb = $this->createVdbWithOrders();
        // NOT EXISTS on existing data
        $rows = iterator_to_array($vdb->query(
            "SELECT * FROM users WHERE NOT EXISTS (SELECT 1 FROM orders WHERE status = 'shipped')"
        ));

        // Shipped orders exist, so NOT EXISTS is false, no users returned
        $this->assertCount(0, $rows);
    }

    public function testExistsCorrelated(): void
    {
        $vdb = $this->createVdbWithOrders();
        // Users who have at least one order
        $rows = iterator_to_array($vdb->query(
            "SELECT * FROM users WHERE EXISTS (SELECT 1 FROM orders WHERE orders.user_id = users.id)"
        ));

        // Users 1, 2, 3 all have orders
        $this->assertCount(3, $rows);
    }

    public function testExistsCorrelatedWithCondition(): void
    {
        $vdb = $this->createVdbWithOrders();
        // Users who have at least one shipped order
        $rows = iterator_to_array($vdb->query(
            "SELECT * FROM users WHERE EXISTS (SELECT 1 FROM orders WHERE orders.user_id = users.id AND status = 'shipped')"
        ));

        // Users 1 (Alice) and 2 (Bob) have shipped orders
        $this->assertCount(2, $rows);
        $names = array_map(fn($r) => $r->name, $rows);
        $this->assertTrue(in_array('Alice', $names, true));
        $this->assertTrue(in_array('Bob', $names, true));
    }

    public function testNotExistsCorrelated(): void
    {
        $vdb = $this->createVdbWithOrders();
        // Users who have NO shipped orders
        $rows = iterator_to_array($vdb->query(
            "SELECT * FROM users WHERE NOT EXISTS (SELECT 1 FROM orders WHERE orders.user_id = users.id AND status = 'shipped')"
        ));

        // Only Charlie (id=3) has no shipped orders
        $this->assertCount(1, $rows);
        $this->assertSame('Charlie', $rows[0]->name);
    }

    // =========================================================================
    // Aggregate function tests
    // =========================================================================

    public function testCountAll(): void
    {
        $vdb = $this->createVdb();
        $rows = iterator_to_array($vdb->query('SELECT COUNT(*) FROM users'));

        $this->assertCount(1, $rows);
        $this->assertSame(3, $rows[0]->{'COUNT(*)'});
    }

    public function testCountWithAlias(): void
    {
        $vdb = $this->createVdb();
        $rows = iterator_to_array($vdb->query('SELECT COUNT(*) AS total FROM users'));

        $this->assertCount(1, $rows);
        $this->assertSame(3, $rows[0]->total);
    }

    public function testCountWithWhere(): void
    {
        $vdb = $this->createVdb();
        $rows = iterator_to_array($vdb->query("SELECT COUNT(*) AS active_count FROM users WHERE status = 'active'"));

        $this->assertCount(1, $rows);
        $this->assertSame(2, $rows[0]->active_count);
    }

    public function testSumAggregate(): void
    {
        $vdb = $this->createVdb();
        $rows = iterator_to_array($vdb->query('SELECT SUM(age) AS total_age FROM users'));

        $this->assertCount(1, $rows);
        // 30 + 25 + 35 = 90
        $this->assertSame(90, $rows[0]->total_age);
    }

    public function testAvgAggregate(): void
    {
        $vdb = $this->createVdb();
        $rows = iterator_to_array($vdb->query('SELECT AVG(age) AS avg_age FROM users'));

        $this->assertCount(1, $rows);
        // (30 + 25 + 35) / 3 = 30
        $this->assertSame(30.0, $rows[0]->avg_age);
    }

    public function testMinAggregate(): void
    {
        $vdb = $this->createVdb();
        $rows = iterator_to_array($vdb->query('SELECT MIN(age) AS min_age FROM users'));

        $this->assertCount(1, $rows);
        $this->assertSame(25, $rows[0]->min_age);
    }

    public function testMaxAggregate(): void
    {
        $vdb = $this->createVdb();
        $rows = iterator_to_array($vdb->query('SELECT MAX(age) AS max_age FROM users'));

        $this->assertCount(1, $rows);
        $this->assertSame(35, $rows[0]->max_age);
    }

    public function testMultipleAggregates(): void
    {
        $vdb = $this->createVdb();
        $rows = iterator_to_array($vdb->query(
            'SELECT COUNT(*) AS cnt, SUM(age) AS total, AVG(age) AS average, MIN(age) AS youngest, MAX(age) AS oldest FROM users'
        ));

        $this->assertCount(1, $rows);
        $this->assertSame(3, $rows[0]->cnt);
        $this->assertSame(90, $rows[0]->total);
        $this->assertSame(30.0, $rows[0]->average);
        $this->assertSame(25, $rows[0]->youngest);
        $this->assertSame(35, $rows[0]->oldest);
    }

    public function testAggregateEmptyResult(): void
    {
        $vdb = $this->createVdb();
        // No users with status 'deleted'
        $rows = iterator_to_array($vdb->query("SELECT COUNT(*) AS cnt, SUM(age) AS total, AVG(age) AS avg FROM users WHERE status = 'deleted'"));

        $this->assertCount(1, $rows);
        $this->assertSame(0, $rows[0]->cnt);
        $this->assertNull($rows[0]->total);
        $this->assertNull($rows[0]->avg);
    }

    public function testCustomAggregate(): void
    {
        $vdb = $this->createVdb();

        // Register a custom GROUP_CONCAT-style aggregate
        $vdb->createAggregate(
            'GROUP_CONCAT',
            function (&$context, $value) {
                if ($value !== null) {
                    $context[] = $value;
                }
            },
            function (&$context) {
                return implode(',', $context ?? []);
            },
            1
        );

        $rows = iterator_to_array($vdb->query('SELECT GROUP_CONCAT(name) AS names FROM users'));

        $this->assertCount(1, $rows);
        $this->assertSame('Alice,Bob,Charlie', $rows[0]->names);
    }

    public function testAggregateWithFilteredSubset(): void
    {
        $vdb = $this->createVdbWithOrders();
        // Sum of shipped order amounts
        $rows = iterator_to_array($vdb->query("SELECT SUM(amount) AS shipped_total FROM orders WHERE status = 'shipped'"));

        $this->assertCount(1, $rows);
        // Orders: 100 (shipped), 200 (pending), 150 (shipped), 300 (pending)
        // Shipped: 100 + 150 = 250
        $this->assertSame(250, $rows[0]->shipped_total);
    }

    // =========================================================================
    // GROUP BY tests
    // =========================================================================

    private function createProductsTable(): InMemoryTable
    {
        $table = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('name', ColumnType::Text),
            new ColumnDef('category', ColumnType::Text),
            new ColumnDef('price', ColumnType::Float),
            new ColumnDef('stock', ColumnType::Int),
        );

        $table->insert(['id' => 1, 'name' => 'Widget', 'category' => 'gadgets', 'price' => 9.99, 'stock' => 100]);
        $table->insert(['id' => 2, 'name' => 'Gizmo', 'category' => 'gadgets', 'price' => 24.99, 'stock' => 50]);
        $table->insert(['id' => 3, 'name' => 'Thingamajig', 'category' => 'tools', 'price' => 14.99, 'stock' => 75]);
        $table->insert(['id' => 4, 'name' => 'Doohickey', 'category' => 'tools', 'price' => 4.99, 'stock' => 200]);

        return $table;
    }

    public function testGroupByBasic(): void
    {
        $vdb = new VirtualDatabase();
        $vdb->registerTable('products', $this->createProductsTable());

        $rows = iterator_to_array($vdb->query(
            'SELECT category, COUNT(*) AS cnt FROM products GROUP BY category ORDER BY category'
        ));

        $this->assertCount(2, $rows);
        $this->assertSame('gadgets', $rows[0]->category);
        $this->assertSame(2, $rows[0]->cnt);
        $this->assertSame('tools', $rows[1]->category);
        $this->assertSame(2, $rows[1]->cnt);
    }

    public function testGroupByWithSum(): void
    {
        $vdb = new VirtualDatabase();
        $vdb->registerTable('products', $this->createProductsTable());

        $rows = iterator_to_array($vdb->query(
            'SELECT category, SUM(price) AS total FROM products GROUP BY category ORDER BY category'
        ));

        $this->assertCount(2, $rows);
        $this->assertSame('gadgets', $rows[0]->category);
        $this->assertEquals(34.98, $rows[0]->total, '', 0.01);
        $this->assertSame('tools', $rows[1]->category);
        $this->assertEquals(19.98, $rows[1]->total, '', 0.01);
    }

    public function testGroupByMultipleAggregates(): void
    {
        $vdb = new VirtualDatabase();
        $vdb->registerTable('products', $this->createProductsTable());

        $rows = iterator_to_array($vdb->query(
            'SELECT category, COUNT(*) AS cnt, SUM(price) AS total, AVG(price) AS avg_price, MIN(price) AS min_price, MAX(price) AS max_price FROM products GROUP BY category ORDER BY category'
        ));

        $this->assertCount(2, $rows);

        // gadgets: 9.99, 24.99
        $this->assertSame('gadgets', $rows[0]->category);
        $this->assertSame(2, $rows[0]->cnt);
        $this->assertEquals(34.98, $rows[0]->total, '', 0.01);
        $this->assertEquals(17.49, $rows[0]->avg_price, '', 0.01);
        $this->assertEquals(9.99, $rows[0]->min_price, '', 0.01);
        $this->assertEquals(24.99, $rows[0]->max_price, '', 0.01);
    }

    public function testGroupByOrderByAggregate(): void
    {
        $vdb = new VirtualDatabase();
        $vdb->registerTable('products', $this->createProductsTable());

        $rows = iterator_to_array($vdb->query(
            'SELECT category, SUM(price) AS total FROM products GROUP BY category ORDER BY total DESC'
        ));

        $this->assertCount(2, $rows);
        // gadgets has higher total (34.98) than tools (19.98)
        $this->assertSame('gadgets', $rows[0]->category);
        $this->assertSame('tools', $rows[1]->category);
    }

    public function testGroupByWithLimit(): void
    {
        $vdb = new VirtualDatabase();
        $vdb->registerTable('products', $this->createProductsTable());

        $rows = iterator_to_array($vdb->query(
            'SELECT category, COUNT(*) AS cnt FROM products GROUP BY category ORDER BY category LIMIT 1'
        ));

        $this->assertCount(1, $rows);
        $this->assertSame('gadgets', $rows[0]->category);
    }

    public function testGroupByMultipleColumns(): void
    {
        $table = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('user_id', ColumnType::Int),
            new ColumnDef('status', ColumnType::Text),
            new ColumnDef('amount', ColumnType::Float),
        );

        $table->insert(['id' => 1, 'user_id' => 1, 'status' => 'shipped', 'amount' => 100]);
        $table->insert(['id' => 2, 'user_id' => 1, 'status' => 'pending', 'amount' => 50]);
        $table->insert(['id' => 3, 'user_id' => 2, 'status' => 'shipped', 'amount' => 200]);
        $table->insert(['id' => 4, 'user_id' => 2, 'status' => 'shipped', 'amount' => 150]);
        $table->insert(['id' => 5, 'user_id' => 3, 'status' => 'pending', 'amount' => 75]);

        $vdb = new VirtualDatabase();
        $vdb->registerTable('orders', $table);

        $rows = iterator_to_array($vdb->query(
            'SELECT user_id, status, COUNT(*) AS cnt, SUM(amount) AS total FROM orders GROUP BY user_id, status ORDER BY user_id, status'
        ));

        $this->assertCount(4, $rows);

        // user_id=1, pending
        $this->assertSame(1, $rows[0]->user_id);
        $this->assertSame('pending', $rows[0]->status);
        $this->assertSame(1, $rows[0]->cnt);
        $this->assertEquals(50, $rows[0]->total);

        // user_id=1, shipped
        $this->assertSame(1, $rows[1]->user_id);
        $this->assertSame('shipped', $rows[1]->status);
        $this->assertSame(1, $rows[1]->cnt);
        $this->assertEquals(100, $rows[1]->total);

        // user_id=2, shipped (2 orders)
        $this->assertSame(2, $rows[2]->user_id);
        $this->assertSame('shipped', $rows[2]->status);
        $this->assertSame(2, $rows[2]->cnt);
        $this->assertEquals(350, $rows[2]->total);

        // user_id=3, pending
        $this->assertSame(3, $rows[3]->user_id);
        $this->assertSame('pending', $rows[3]->status);
        $this->assertSame(1, $rows[3]->cnt);
        $this->assertEquals(75, $rows[3]->total);
    }

    // =========================================================================
    // HAVING tests
    // =========================================================================

    public function testHavingBasic(): void
    {
        $vdb = new VirtualDatabase();
        $vdb->registerTable('products', $this->createProductsTable());

        // Both categories have 2 products, so both pass HAVING cnt >= 2
        $rows = iterator_to_array($vdb->query(
            'SELECT category, COUNT(*) AS cnt FROM products GROUP BY category HAVING cnt >= 2 ORDER BY category'
        ));

        $this->assertCount(2, $rows);
    }

    public function testHavingFiltersGroups(): void
    {
        $table = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('category', ColumnType::Text),
        );

        $table->insert(['id' => 1, 'category' => 'A']);
        $table->insert(['id' => 2, 'category' => 'A']);
        $table->insert(['id' => 3, 'category' => 'A']);
        $table->insert(['id' => 4, 'category' => 'B']);
        $table->insert(['id' => 5, 'category' => 'C']);
        $table->insert(['id' => 6, 'category' => 'C']);

        $vdb = new VirtualDatabase();
        $vdb->registerTable('items', $table);

        // Only category A has 3 items, B has 1, C has 2
        $rows = iterator_to_array($vdb->query(
            'SELECT category, COUNT(*) AS cnt FROM items GROUP BY category HAVING cnt > 2'
        ));

        $this->assertCount(1, $rows);
        $this->assertSame('A', $rows[0]->category);
        $this->assertSame(3, $rows[0]->cnt);
    }

    public function testHavingWithSum(): void
    {
        $vdb = new VirtualDatabase();
        $vdb->registerTable('products', $this->createProductsTable());

        // gadgets total: 34.98, tools total: 19.98
        $rows = iterator_to_array($vdb->query(
            'SELECT category, SUM(price) AS total FROM products GROUP BY category HAVING total > 30'
        ));

        $this->assertCount(1, $rows);
        $this->assertSame('gadgets', $rows[0]->category);
    }

    public function testHavingWithOrderBy(): void
    {
        $vdb = new VirtualDatabase();
        $vdb->registerTable('products', $this->createProductsTable());

        $rows = iterator_to_array($vdb->query(
            'SELECT category, SUM(price) AS total FROM products GROUP BY category HAVING total > 10 ORDER BY total DESC'
        ));

        $this->assertCount(2, $rows);
        $this->assertSame('gadgets', $rows[0]->category);
        $this->assertSame('tools', $rows[1]->category);
    }

    public function testHavingWithLimit(): void
    {
        $vdb = new VirtualDatabase();
        $vdb->registerTable('products', $this->createProductsTable());

        $rows = iterator_to_array($vdb->query(
            'SELECT category, COUNT(*) AS cnt FROM products GROUP BY category HAVING cnt >= 1 ORDER BY category LIMIT 1'
        ));

        $this->assertCount(1, $rows);
        $this->assertSame('gadgets', $rows[0]->category);
    }

    public function testGroupByWithWhere(): void
    {
        $vdb = new VirtualDatabase();
        $vdb->registerTable('products', $this->createProductsTable());

        // Filter to only products with price > 10, then group
        $rows = iterator_to_array($vdb->query(
            'SELECT category, COUNT(*) AS cnt, SUM(price) AS total FROM products WHERE price > 10 GROUP BY category ORDER BY category'
        ));

        // gadgets: only Gizmo (24.99), tools: only Thingamajig (14.99)
        $this->assertCount(2, $rows);
        $this->assertSame('gadgets', $rows[0]->category);
        $this->assertSame(1, $rows[0]->cnt);
        $this->assertSame('tools', $rows[1]->category);
        $this->assertSame(1, $rows[1]->cnt);
    }

    public function testGroupByWithWhereAndHaving(): void
    {
        $vdb = new VirtualDatabase();
        $vdb->registerTable('products', $this->createProductsTable());

        // Filter products with price < 20, group, then filter groups with count >= 2
        $rows = iterator_to_array($vdb->query(
            'SELECT category, COUNT(*) AS cnt FROM products WHERE price < 20 GROUP BY category HAVING cnt >= 2'
        ));

        // gadgets: only Widget (9.99) - count 1, doesn't pass
        // tools: Thingamajig (14.99) and Doohickey (4.99) - count 2, passes
        $this->assertCount(1, $rows);
        $this->assertSame('tools', $rows[0]->category);
        $this->assertSame(2, $rows[0]->cnt);
    }

    public function testHavingComparisonOperators(): void
    {
        $table = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('category', ColumnType::Text),
            new ColumnDef('value', ColumnType::Int),
        );

        $table->insert(['id' => 1, 'category' => 'A', 'value' => 10]);
        $table->insert(['id' => 2, 'category' => 'A', 'value' => 20]);
        $table->insert(['id' => 3, 'category' => 'B', 'value' => 5]);
        $table->insert(['id' => 4, 'category' => 'C', 'value' => 100]);

        $vdb = new VirtualDatabase();
        $vdb->registerTable('items', $table);

        // Test HAVING with = operator
        $rows = iterator_to_array($vdb->query(
            'SELECT category, SUM(value) AS total FROM items GROUP BY category HAVING total = 30'
        ));
        $this->assertCount(1, $rows);
        $this->assertSame('A', $rows[0]->category);

        // Test HAVING with < operator
        $rows = iterator_to_array($vdb->query(
            'SELECT category, SUM(value) AS total FROM items GROUP BY category HAVING total < 10 ORDER BY category'
        ));
        $this->assertCount(1, $rows);
        $this->assertSame('B', $rows[0]->category);
    }
};

exit($test->run());
