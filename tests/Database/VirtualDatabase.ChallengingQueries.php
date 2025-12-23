<?php
/**
 * Test VirtualDatabase with challenging SQL queries
 *
 * Tests complex query patterns:
 * - Nested subqueries with multi-level binding
 * - Correlated subqueries with EXISTS and IN
 * - JOIN combined with subqueries
 * - Multiple correlated references from outer query
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use mini\Test;
use mini\Database\VirtualDatabase;
use mini\Table\InMemoryTable;
use mini\Table\ColumnDef;
use mini\Table\Types\ColumnType;
use mini\Table\Types\IndexType;

$test = new class extends Test {

    private function createTestDatabase(): VirtualDatabase
    {
        $vdb = new VirtualDatabase();

        // Users table
        $users = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('name', ColumnType::Text),
            new ColumnDef('dept_id', ColumnType::Int),
            new ColumnDef('manager_id', ColumnType::Int),
        );
        $users->insert(['id' => 1, 'name' => 'Alice', 'dept_id' => 1, 'manager_id' => null]);
        $users->insert(['id' => 2, 'name' => 'Bob', 'dept_id' => 1, 'manager_id' => 1]);
        $users->insert(['id' => 3, 'name' => 'Charlie', 'dept_id' => 2, 'manager_id' => 1]);
        $users->insert(['id' => 4, 'name' => 'Diana', 'dept_id' => 2, 'manager_id' => 3]);
        $users->insert(['id' => 5, 'name' => 'Eve', 'dept_id' => 3, 'manager_id' => null]);
        $vdb->registerTable('users', $users);

        // Departments table
        $depts = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('name', ColumnType::Text),
            new ColumnDef('budget', ColumnType::Int),
        );
        $depts->insert(['id' => 1, 'name' => 'Engineering', 'budget' => 100000]);
        $depts->insert(['id' => 2, 'name' => 'Sales', 'budget' => 75000]);
        $depts->insert(['id' => 3, 'name' => 'HR', 'budget' => 50000]);
        $vdb->registerTable('departments', $depts);

        // Orders table
        $orders = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('user_id', ColumnType::Int),
            new ColumnDef('amount', ColumnType::Int),
            new ColumnDef('status', ColumnType::Text),
        );
        $orders->insert(['id' => 1, 'user_id' => 1, 'amount' => 500, 'status' => 'shipped']);
        $orders->insert(['id' => 2, 'user_id' => 1, 'amount' => 300, 'status' => 'pending']);
        $orders->insert(['id' => 3, 'user_id' => 2, 'amount' => 1000, 'status' => 'shipped']);
        $orders->insert(['id' => 4, 'user_id' => 3, 'amount' => 200, 'status' => 'cancelled']);
        $orders->insert(['id' => 5, 'user_id' => 4, 'amount' => 750, 'status' => 'shipped']);
        $vdb->registerTable('orders', $orders);

        // Order items table (for deeper nesting)
        $items = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('order_id', ColumnType::Int),
            new ColumnDef('product_id', ColumnType::Int),
            new ColumnDef('quantity', ColumnType::Int),
        );
        $items->insert(['id' => 1, 'order_id' => 1, 'product_id' => 101, 'quantity' => 2]);
        $items->insert(['id' => 2, 'order_id' => 1, 'product_id' => 102, 'quantity' => 1]);
        $items->insert(['id' => 3, 'order_id' => 3, 'product_id' => 101, 'quantity' => 5]);
        $items->insert(['id' => 4, 'order_id' => 5, 'product_id' => 103, 'quantity' => 3]);
        $vdb->registerTable('order_items', $items);

        // Products table
        $products = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('name', ColumnType::Text),
            new ColumnDef('category', ColumnType::Text),
        );
        $products->insert(['id' => 101, 'name' => 'Widget', 'category' => 'gadgets']);
        $products->insert(['id' => 102, 'name' => 'Gizmo', 'category' => 'gadgets']);
        $products->insert(['id' => 103, 'name' => 'Thingamajig', 'category' => 'tools']);
        $vdb->registerTable('products', $products);

        return $vdb;
    }

    // =========================================================================
    // Nested subqueries (2 levels)
    // =========================================================================

    public function testNestedSubqueryInIn(): void
    {
        $vdb = $this->createTestDatabase();

        // Users who have orders containing product 101
        // Level 1: users WHERE id IN (subquery)
        // Level 2: orders WHERE id IN (subquery)
        $rows = iterator_to_array($vdb->query(
            "SELECT * FROM users WHERE id IN (
                SELECT user_id FROM orders WHERE id IN (
                    SELECT order_id FROM order_items WHERE product_id = 101
                )
            )"
        ));

        // Order 1 (user 1) and order 3 (user 2) have product 101
        $this->assertCount(2, $rows);
        $names = array_map(fn($r) => $r->name, $rows);
        $this->assertTrue(in_array('Alice', $names, true));
        $this->assertTrue(in_array('Bob', $names, true));
    }

    public function testNestedSubqueryWithPlaceholder(): void
    {
        $vdb = $this->createTestDatabase();

        // Nested subquery with placeholder at deepest level
        $rows = iterator_to_array($vdb->query(
            "SELECT * FROM users WHERE id IN (
                SELECT user_id FROM orders WHERE id IN (
                    SELECT order_id FROM order_items WHERE product_id = ?
                )
            )",
            [103]
        ));

        // Only order 5 (user 4 - Diana) has product 103
        $this->assertCount(1, $rows);
        $this->assertSame('Diana', $rows[0]->name);
    }

    // =========================================================================
    // Correlated EXISTS subqueries
    // =========================================================================

    public function testCorrelatedExistsWithMultipleConditions(): void
    {
        $vdb = $this->createTestDatabase();

        // Users who have shipped orders over 400
        $rows = iterator_to_array($vdb->query(
            "SELECT * FROM users u WHERE EXISTS (
                SELECT 1 FROM orders o
                WHERE o.user_id = u.id
                AND o.status = 'shipped'
                AND o.amount > 400
            )"
        ));

        // Alice (order 1: 500 shipped), Bob (order 3: 1000 shipped), Diana (order 5: 750 shipped)
        $this->assertCount(3, $rows);
        $names = array_map(fn($r) => $r->name, $rows);
        $this->assertTrue(in_array('Alice', $names, true));
        $this->assertTrue(in_array('Bob', $names, true));
        $this->assertTrue(in_array('Diana', $names, true));
    }

    public function testNotExistsCorrelatedWithMultipleConditions(): void
    {
        $vdb = $this->createTestDatabase();

        // Users who have NO shipped orders
        $rows = iterator_to_array($vdb->query(
            "SELECT * FROM users u WHERE NOT EXISTS (
                SELECT 1 FROM orders o
                WHERE o.user_id = u.id
                AND o.status = 'shipped'
            )"
        ));

        // Charlie (only cancelled order), Eve (no orders)
        $this->assertCount(2, $rows);
        $names = array_map(fn($r) => $r->name, $rows);
        $this->assertTrue(in_array('Charlie', $names, true));
        $this->assertTrue(in_array('Eve', $names, true));
    }

    // =========================================================================
    // JOIN combined with subquery
    // =========================================================================

    public function testJoinWithInSubquery(): void
    {
        $vdb = $this->createTestDatabase();

        // Join users with departments, then filter users who have shipped orders
        $rows = iterator_to_array($vdb->query(
            "SELECT u.name, d.name AS dept_name
             FROM users u
             INNER JOIN departments d ON u.dept_id = d.id
             WHERE u.id IN (SELECT user_id FROM orders WHERE status = 'shipped')
             ORDER BY u.name"
        ));

        // Alice (Engineering), Bob (Engineering), Diana (Sales)
        $this->assertCount(3, $rows);
        $this->assertSame('Alice', $rows[0]->name);
        $this->assertSame('Engineering', $rows[0]->dept_name);
        $this->assertSame('Bob', $rows[1]->name);
        $this->assertSame('Diana', $rows[2]->name);
        $this->assertSame('Sales', $rows[2]->dept_name);
    }

    public function testJoinWithInSubqueryAndBudgetFilter(): void
    {
        $vdb = $this->createTestDatabase();

        // Join users with departments where dept has high budget AND user has orders
        // Note: EXISTS with JOIN has column aliasing issues, using IN instead
        $rows = iterator_to_array($vdb->query(
            "SELECT u.name, d.name AS dept_name, d.budget
             FROM users u
             INNER JOIN departments d ON u.dept_id = d.id
             WHERE d.budget > 60000
             AND u.id IN (SELECT user_id FROM orders)
             ORDER BY u.name"
        ));

        // Engineering (100k): Alice, Bob; Sales (75k): Charlie, Diana - all have orders
        $this->assertCount(4, $rows);
        $this->assertSame('Alice', $rows[0]->name);
        $this->assertSame('Bob', $rows[1]->name);
        $this->assertSame('Charlie', $rows[2]->name);
        $this->assertSame('Diana', $rows[3]->name);
    }

    public function testLeftJoinWithSubqueryFilter(): void
    {
        $vdb = $this->createTestDatabase();

        // Left join to include users without departments, filter by subquery
        $rows = iterator_to_array($vdb->query(
            "SELECT u.name, d.name AS dept_name
             FROM users u
             LEFT JOIN departments d ON u.dept_id = d.id
             WHERE u.id IN (SELECT user_id FROM orders WHERE amount > 500)
             ORDER BY u.name"
        ));

        // Bob (1000), Diana (750)
        $this->assertCount(2, $rows);
        $this->assertSame('Bob', $rows[0]->name);
        $this->assertSame('Diana', $rows[1]->name);
    }

    // =========================================================================
    // Subquery in different positions
    // =========================================================================

    public function testSubqueryWithNotIn(): void
    {
        $vdb = $this->createTestDatabase();

        // Users who are NOT in departments with budget over 60000
        $rows = iterator_to_array($vdb->query(
            "SELECT * FROM users
             WHERE dept_id NOT IN (SELECT id FROM departments WHERE budget > 60000)
             ORDER BY name"
        ));

        // Only HR (budget 50000) qualifies - Eve is in HR
        $this->assertCount(1, $rows);
        $this->assertSame('Eve', $rows[0]->name);
    }

    public function testSubqueryWithOrderAndLimit(): void
    {
        $vdb = $this->createTestDatabase();

        // Users who placed the top 2 orders by amount
        $rows = iterator_to_array($vdb->query(
            "SELECT * FROM users
             WHERE id IN (SELECT user_id FROM orders ORDER BY amount DESC LIMIT 2)
             ORDER BY name"
        ));

        // Top 2 orders: 1000 (Bob), 750 (Diana)
        $this->assertCount(2, $rows);
        $this->assertSame('Bob', $rows[0]->name);
        $this->assertSame('Diana', $rows[1]->name);
    }

    // =========================================================================
    // Complex combined conditions
    // =========================================================================

    public function testInSubqueryWithAndConditions(): void
    {
        $vdb = $this->createTestDatabase();

        // Users in Engineering who have pending orders
        // Using IN subquery instead of EXISTS for simpler handling
        $rows = iterator_to_array($vdb->query(
            "SELECT * FROM users
             WHERE dept_id = 1
             AND id IN (SELECT user_id FROM orders WHERE status = 'pending')"
        ));

        // Alice has a pending order (order 2) and is in Engineering
        $this->assertCount(1, $rows);
        $this->assertSame('Alice', $rows[0]->name);
    }

    public function testMultipleSubqueriesWithAnd(): void
    {
        $vdb = $this->createTestDatabase();

        // Users who have BOTH shipped AND pending orders
        // Using simple column names (no alias) for better subquery handling
        $rows = iterator_to_array($vdb->query(
            "SELECT * FROM users
             WHERE id IN (SELECT user_id FROM orders WHERE status = 'shipped')
             AND id IN (SELECT user_id FROM orders WHERE status = 'pending')"
        ));

        // Only Alice has both shipped (order 1) and pending (order 2)
        $this->assertCount(1, $rows);
        $this->assertSame('Alice', $rows[0]->name);
    }

    public function testTripleNestedSubquery(): void
    {
        $vdb = $this->createTestDatabase();

        // Users who ordered gadgets (products 101, 102)
        // Three levels of nesting: users → orders → order_items
        $rows = iterator_to_array($vdb->query(
            "SELECT * FROM users WHERE id IN (
                SELECT user_id FROM orders WHERE id IN (
                    SELECT order_id FROM order_items WHERE product_id IN (101, 102)
                )
            )
            ORDER BY name"
        ));

        // Products 101, 102 are gadgets; order 1 (Alice) and order 3 (Bob) have them
        $this->assertCount(2, $rows);
        $this->assertSame('Alice', $rows[0]->name);
        $this->assertSame('Bob', $rows[1]->name);
    }

    // =========================================================================
    // Edge cases
    // =========================================================================

    public function testEmptySubqueryResult(): void
    {
        $vdb = $this->createTestDatabase();

        // Subquery returns empty - no orders with status 'refunded'
        $rows = iterator_to_array($vdb->query(
            "SELECT * FROM users WHERE id IN (
                SELECT user_id FROM orders WHERE status = 'refunded'
            )"
        ));

        $this->assertCount(0, $rows);
    }

    public function testNestedEmptySubquery(): void
    {
        $vdb = $this->createTestDatabase();

        // Nested subquery where inner is empty
        $rows = iterator_to_array($vdb->query(
            "SELECT * FROM users WHERE id IN (
                SELECT user_id FROM orders WHERE id IN (
                    SELECT order_id FROM order_items WHERE product_id = 999
                )
            )"
        ));

        // No items with product 999
        $this->assertCount(0, $rows);
    }

    public function testExistsWithEmptyInnerResult(): void
    {
        $vdb = $this->createTestDatabase();

        // NOT EXISTS with empty subquery should return all rows
        $rows = iterator_to_array($vdb->query(
            "SELECT * FROM users u WHERE NOT EXISTS (
                SELECT 1 FROM orders o WHERE o.user_id = u.id AND o.status = 'refunded'
            )"
        ));

        // No refunded orders, so all users pass NOT EXISTS
        $this->assertCount(5, $rows);
    }

    // =========================================================================
    // Aggregate with subquery
    // =========================================================================

    public function testAggregateWithSubqueryFilter(): void
    {
        $vdb = $this->createTestDatabase();

        // Total order amount for users in Engineering
        $rows = iterator_to_array($vdb->query(
            "SELECT SUM(amount) AS total FROM orders
             WHERE user_id IN (SELECT id FROM users WHERE dept_id = 1)"
        ));

        // Alice (500 + 300) + Bob (1000) = 1800
        $this->assertCount(1, $rows);
        $this->assertSame(1800, $rows[0]->total);
    }

    public function testGroupByWithSubqueryFilter(): void
    {
        $vdb = $this->createTestDatabase();

        // Order count per status for users who ordered gadgets (products 101, 102)
        // Using fully nested subqueries without JOINs
        $rows = iterator_to_array($vdb->query(
            "SELECT status, COUNT(*) AS cnt FROM orders
             WHERE user_id IN (
                 SELECT user_id FROM orders WHERE id IN (
                     SELECT order_id FROM order_items WHERE product_id IN (101, 102)
                 )
             )
             GROUP BY status
             ORDER BY status"
        ));

        // Alice and Bob ordered gadgets (101, 102)
        // Alice: shipped, pending; Bob: shipped
        // So: pending=1, shipped=2
        $this->assertCount(2, $rows);
        $this->assertSame('pending', $rows[0]->status);
        $this->assertSame(1, $rows[0]->cnt);
        $this->assertSame('shipped', $rows[1]->status);
        $this->assertSame(2, $rows[1]->cnt);
    }

    // =========================================================================
    // EXISTS with JOIN (previously a known limitation, now fixed)
    // =========================================================================

    public function testExistsWithJoinOuterTable(): void
    {
        $vdb = $this->createTestDatabase();

        // Join users with departments, then filter with correlated EXISTS
        $rows = iterator_to_array($vdb->query(
            "SELECT u.name, d.name AS dept_name
             FROM users u
             INNER JOIN departments d ON u.dept_id = d.id
             WHERE EXISTS (SELECT 1 FROM orders o WHERE o.user_id = u.id)
             ORDER BY u.name"
        ));

        // Alice, Bob, Charlie, Diana have orders; Eve has no orders
        $this->assertCount(4, $rows);
        $this->assertSame('Alice', $rows[0]->name);
        $this->assertSame('Bob', $rows[1]->name);
        $this->assertSame('Charlie', $rows[2]->name);
        $this->assertSame('Diana', $rows[3]->name);
    }

    public function testNotExistsWithJoinOuterTable(): void
    {
        $vdb = $this->createTestDatabase();

        // Users who have NO orders
        $rows = iterator_to_array($vdb->query(
            "SELECT u.name, d.name AS dept_name
             FROM users u
             INNER JOIN departments d ON u.dept_id = d.id
             WHERE NOT EXISTS (SELECT 1 FROM orders o WHERE o.user_id = u.id)"
        ));

        // Only Eve has no orders
        $this->assertCount(1, $rows);
        $this->assertSame('Eve', $rows[0]->name);
        $this->assertSame('HR', $rows[0]->dept_name);
    }

    public function testExistsWithJoinAndAdditionalFilter(): void
    {
        $vdb = $this->createTestDatabase();

        // Users in high-budget depts who have shipped orders
        $rows = iterator_to_array($vdb->query(
            "SELECT u.name, d.name AS dept_name
             FROM users u
             INNER JOIN departments d ON u.dept_id = d.id
             WHERE d.budget > 60000
             AND EXISTS (SELECT 1 FROM orders o WHERE o.user_id = u.id AND o.status = 'shipped')
             ORDER BY u.name"
        ));

        // Engineering (100k) + Sales (75k) users with shipped orders:
        // Alice (shipped), Bob (shipped), Diana (shipped)
        // Charlie only has cancelled order
        $this->assertCount(3, $rows);
        $this->assertSame('Alice', $rows[0]->name);
        $this->assertSame('Bob', $rows[1]->name);
        $this->assertSame('Diana', $rows[2]->name);
    }

    // =========================================================================
    // JOIN inside subquery (previously a known limitation, now fixed)
    // =========================================================================

    public function testJoinInsideSubquery(): void
    {
        $vdb = $this->createTestDatabase();

        // Subquery with JOIN - select qualified column name
        // Users who have orders with items in gadgets category
        $rows = iterator_to_array($vdb->query(
            "SELECT * FROM users WHERE id IN (
                SELECT o.user_id FROM orders o
                INNER JOIN order_items oi ON o.id = oi.order_id
                INNER JOIN products p ON oi.product_id = p.id
                WHERE p.category = 'gadgets'
            )
            ORDER BY name"
        ));

        // Products 101, 102 are gadgets; order 1 (Alice) and order 3 (Bob) have them
        $this->assertCount(2, $rows);
        $this->assertSame('Alice', $rows[0]->name);
        $this->assertSame('Bob', $rows[1]->name);
    }

    public function testMultipleJoinsInsideSubquery(): void
    {
        $vdb = $this->createTestDatabase();

        // More complex: get user names who ordered specific product
        $rows = iterator_to_array($vdb->query(
            "SELECT name FROM users WHERE id IN (
                SELECT o.user_id FROM orders o
                INNER JOIN order_items oi ON o.id = oi.order_id
                WHERE oi.product_id = 101
            )
            ORDER BY name"
        ));

        // Product 101 (Widget) is in order 1 (Alice) and order 3 (Bob)
        $this->assertCount(2, $rows);
        $this->assertSame('Alice', $rows[0]->name);
        $this->assertSame('Bob', $rows[1]->name);
    }
};

exit($test->run());
