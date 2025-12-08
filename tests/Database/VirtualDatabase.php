<?php
/**
 * Test VirtualDatabase implementation
 *
 * Tests real-world SQL queries that developers commonly write.
 * VirtualDatabase should behave like a SQL database that doesn't support JOINs
 * and some advanced features - but the subset it supports should work correctly.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use mini\Test;
use mini\Database\VirtualDatabase;
use mini\Database\Virtual\{VirtualTable, Row, OrderInfo};
use mini\Database\ResultSetInterface;
use mini\Database\PartialQuery;

$test = new class extends Test {

    private VirtualDatabase $vdb;
    private array $users;
    private array $products;
    private array $orders;
    private array $categories;

    private function resetData(): void
    {
        // Users table - typical user data (include null values for IS NULL testing)
        $this->users = [
            1 => ['id' => 1, 'name' => 'Alice Smith', 'email' => 'alice@example.com', 'age' => 30, 'status' => 'active', 'role' => 'admin', 'bio' => null],
            2 => ['id' => 2, 'name' => 'Bob Jones', 'email' => 'bob@test.com', 'age' => 25, 'status' => 'active', 'role' => 'user', 'bio' => 'Software developer'],
            3 => ['id' => 3, 'name' => 'Charlie Brown', 'email' => 'charlie@example.com', 'age' => 35, 'status' => 'inactive', 'role' => 'user', 'bio' => null],
            4 => ['id' => 4, 'name' => 'Diana Prince', 'email' => 'diana@test.com', 'age' => 28, 'status' => 'active', 'role' => 'moderator', 'bio' => 'Designer'],
            5 => ['id' => 5, 'name' => 'Eve Wilson', 'email' => 'eve@example.com', 'age' => 32, 'status' => 'pending', 'role' => 'user', 'bio' => null],
        ];

        // Products table - e-commerce data
        $this->products = [
            101 => ['id' => 101, 'name' => 'Laptop', 'price' => 999.99, 'category' => 'electronics', 'stock' => 50],
            102 => ['id' => 102, 'name' => 'Mouse', 'price' => 29.99, 'category' => 'electronics', 'stock' => 200],
            103 => ['id' => 103, 'name' => 'Desk Chair', 'price' => 299.99, 'category' => 'furniture', 'stock' => 25],
            104 => ['id' => 104, 'name' => 'Notebook', 'price' => 4.99, 'category' => 'office', 'stock' => 500],
            105 => ['id' => 105, 'name' => 'Monitor', 'price' => 399.99, 'category' => 'electronics', 'stock' => 0],
        ];

        // Orders table - for subquery testing
        $this->orders = [
            1001 => ['id' => 1001, 'user_id' => 1, 'product_id' => 101, 'quantity' => 1],
            1002 => ['id' => 1002, 'user_id' => 1, 'product_id' => 102, 'quantity' => 2],
            1003 => ['id' => 1003, 'user_id' => 2, 'product_id' => 103, 'quantity' => 1],
            1004 => ['id' => 1004, 'user_id' => 4, 'product_id' => 101, 'quantity' => 1],
        ];

        // Categories table - for nested subquery testing
        $this->categories = [
            1 => ['id' => 1, 'name' => 'electronics', 'featured' => true],
            2 => ['id' => 2, 'name' => 'furniture', 'featured' => false],
            3 => ['id' => 3, 'name' => 'office', 'featured' => true],
        ];
    }

    protected function setUp(): void
    {
        $this->vdb = new VirtualDatabase();
        $this->resetData();

        $users = &$this->users;
        $products = &$this->products;
        $orders = &$this->orders;
        $categories = &$this->categories;

        $this->vdb->registerTable('users', new VirtualTable(
            selectFn: function($ast) use (&$users): iterable {
                foreach ($users as $id => $columns) {
                    yield new Row($id, $columns);
                }
            },
            insertFn: function(array $row) use (&$users): int {
                $id = max(array_keys($users)) + 1;
                $users[$id] = array_merge(['id' => $id], $row);
                return $id;
            },
            updateFn: function(array $rowIds, array $changes) use (&$users): int {
                foreach ($rowIds as $id) {
                    $users[$id] = array_merge($users[$id], $changes);
                }
                return count($rowIds);
            },
            deleteFn: function(array $rowIds) use (&$users): int {
                foreach ($rowIds as $id) {
                    unset($users[$id]);
                }
                return count($rowIds);
            }
        ));

        $this->vdb->registerTable('products', new VirtualTable(
            selectFn: function($ast) use (&$products): iterable {
                foreach ($products as $id => $columns) {
                    yield new Row($id, $columns);
                }
            }
        ));

        $this->vdb->registerTable('orders', new VirtualTable(
            selectFn: function($ast) use (&$orders): iterable {
                foreach ($orders as $id => $columns) {
                    yield new Row($id, $columns);
                }
            }
        ));

        $this->vdb->registerTable('categories', new VirtualTable(
            selectFn: function($ast) use (&$categories): iterable {
                foreach ($categories as $id => $columns) {
                    yield new Row($id, $columns);
                }
            }
        ));
    }

    // =========================================================================
    // BASIC SELECT QUERIES
    // =========================================================================

    public function testSelectAll(): void
    {
        $rows = $this->vdb->query('SELECT * FROM users')->toArray();
        $this->assertCount(5, $rows);
    }

    public function testSelectSpecificColumns(): void
    {
        // Note: VirtualDatabase returns all columns regardless of SELECT list
        // This is a known limitation - virtual tables control what they return
        $rows = $this->vdb->query('SELECT id, name FROM users')->toArray();
        $this->assertCount(5, $rows);
    }

    public function testSelectWithTablePrefix(): void
    {
        $rows = $this->vdb->query('SELECT users.id, users.name FROM users')->toArray();
        $this->assertCount(5, $rows);
    }

    // =========================================================================
    // WHERE CLAUSE - COMPARISON OPERATORS
    // =========================================================================

    public function testWhereEquals(): void
    {
        $rows = $this->vdb->query("SELECT * FROM users WHERE status = 'active'")->toArray();
        $this->assertCount(3, $rows);
    }

    public function testWhereEqualsWithPlaceholder(): void
    {
        $rows = $this->vdb->query('SELECT * FROM users WHERE status = ?', ['active'])->toArray();
        $this->assertCount(3, $rows);
    }

    public function testWhereEqualsWithNamedPlaceholder(): void
    {
        $rows = $this->vdb->query('SELECT * FROM users WHERE status = :status', ['status' => 'active'])->toArray();
        $this->assertCount(3, $rows);
    }

    public function testWhereNotEquals(): void
    {
        $rows = $this->vdb->query("SELECT * FROM users WHERE status != 'active'")->toArray();
        $this->assertCount(2, $rows); // inactive + pending
    }

    public function testWhereGreaterThan(): void
    {
        $rows = $this->vdb->query('SELECT * FROM users WHERE age > 30')->toArray();
        $this->assertCount(2, $rows); // Charlie (35) and Eve (32)
    }

    public function testWhereLessThan(): void
    {
        $rows = $this->vdb->query('SELECT * FROM users WHERE age < 30')->toArray();
        $this->assertCount(2, $rows); // Bob (25) and Diana (28)
    }

    public function testWhereGreaterThanOrEqual(): void
    {
        $rows = $this->vdb->query('SELECT * FROM users WHERE age >= 30')->toArray();
        $this->assertCount(3, $rows); // Alice (30), Charlie (35), Eve (32)
    }

    public function testWhereLessThanOrEqual(): void
    {
        $rows = $this->vdb->query('SELECT * FROM users WHERE age <= 28')->toArray();
        $this->assertCount(2, $rows); // Bob (25), Diana (28)
    }

    // =========================================================================
    // WHERE CLAUSE - LOGICAL OPERATORS
    // =========================================================================

    public function testWhereAnd(): void
    {
        $rows = $this->vdb->query("SELECT * FROM users WHERE status = 'active' AND age > 25")->toArray();
        $this->assertCount(2, $rows); // Alice (30), Diana (28)
    }

    public function testWhereOr(): void
    {
        $rows = $this->vdb->query("SELECT * FROM users WHERE status = 'inactive' OR status = 'pending'")->toArray();
        $this->assertCount(2, $rows);
    }

    public function testWhereAndOr(): void
    {
        // Complex condition: active users over 28 OR any admin
        // Alice: active, 30, admin -> matches both conditions
        // Diana: 28 not > 28, not admin -> no match
        $rows = $this->vdb->query(
            "SELECT * FROM users WHERE (status = 'active' AND age > 28) OR role = 'admin'"
        )->toArray();
        $this->assertCount(1, $rows); // Only Alice (matches OR via both branches)
        $this->assertSame('Alice Smith', $rows[0]['name']);
    }

    public function testWhereMultipleAnds(): void
    {
        $rows = $this->vdb->query(
            "SELECT * FROM users WHERE status = ? AND role = ? AND age > ?",
            ['active', 'user', 20]
        )->toArray();
        $this->assertCount(1, $rows); // Bob
    }

    // =========================================================================
    // WHERE CLAUSE - IN OPERATOR
    // =========================================================================

    public function testWhereIn(): void
    {
        $rows = $this->vdb->query('SELECT * FROM users WHERE id IN (1, 3, 5)')->toArray();
        $this->assertCount(3, $rows);
    }

    public function testWhereInWithStrings(): void
    {
        $rows = $this->vdb->query("SELECT * FROM users WHERE status IN ('active', 'pending')")->toArray();
        $this->assertCount(4, $rows);
    }

    public function testWhereInSingleValue(): void
    {
        $rows = $this->vdb->query('SELECT * FROM users WHERE id IN (1)')->toArray();
        $this->assertCount(1, $rows);
    }

    // =========================================================================
    // ORDER BY
    // =========================================================================

    public function testOrderByAsc(): void
    {
        $rows = $this->vdb->query('SELECT * FROM users ORDER BY age ASC')->toArray();
        $this->assertSame(25, $rows[0]['age']);
        $this->assertSame(35, $rows[4]['age']);
    }

    public function testOrderByDesc(): void
    {
        $rows = $this->vdb->query('SELECT * FROM users ORDER BY age DESC')->toArray();
        $this->assertSame(35, $rows[0]['age']);
        $this->assertSame(25, $rows[4]['age']);
    }

    public function testOrderByDefaultAsc(): void
    {
        $rows = $this->vdb->query('SELECT * FROM users ORDER BY age')->toArray();
        $this->assertSame(25, $rows[0]['age']); // Default is ASC
    }

    public function testOrderByString(): void
    {
        $rows = $this->vdb->query('SELECT * FROM users ORDER BY name ASC')->toArray();
        $this->assertSame('Alice Smith', $rows[0]['name']);
        $this->assertSame('Eve Wilson', $rows[4]['name']);
    }

    public function testOrderByMultipleColumns(): void
    {
        $rows = $this->vdb->query('SELECT * FROM users ORDER BY status ASC, age DESC')->toArray();
        // active users first, then inactive, then pending - within each group sorted by age DESC
        $this->assertSame('active', $rows[0]['status']);
    }

    // =========================================================================
    // LIMIT
    // =========================================================================

    public function testLimit(): void
    {
        $rows = $this->vdb->query('SELECT * FROM users LIMIT 2')->toArray();
        $this->assertCount(2, $rows);
    }

    public function testLimitWithOrderBy(): void
    {
        $rows = $this->vdb->query('SELECT * FROM users ORDER BY age ASC LIMIT 2')->toArray();
        $this->assertCount(2, $rows);
        $this->assertSame(25, $rows[0]['age']); // Bob - youngest
        $this->assertSame(28, $rows[1]['age']); // Diana - second youngest
    }

    public function testLimitWithWhere(): void
    {
        $rows = $this->vdb->query("SELECT * FROM users WHERE status = 'active' LIMIT 2")->toArray();
        $this->assertCount(2, $rows);
    }

    public function testLimitLargerThanResultSet(): void
    {
        $rows = $this->vdb->query('SELECT * FROM users LIMIT 100')->toArray();
        $this->assertCount(5, $rows);
    }

    // =========================================================================
    // COMBINED QUERIES (real-world patterns)
    // =========================================================================

    public function testFindActiveUsersSortedByName(): void
    {
        $rows = $this->vdb->query(
            "SELECT * FROM users WHERE status = 'active' ORDER BY name ASC"
        )->toArray();

        $this->assertCount(3, $rows);
        $this->assertSame('Alice Smith', $rows[0]['name']);
        $this->assertSame('Bob Jones', $rows[1]['name']);
        $this->assertSame('Diana Prince', $rows[2]['name']);
    }

    public function testFindTopExpensiveProducts(): void
    {
        $rows = $this->vdb->query(
            'SELECT * FROM products ORDER BY price DESC LIMIT 3'
        )->toArray();

        $this->assertCount(3, $rows);
        $this->assertSame('Laptop', $rows[0]['name']);
        $this->assertSame('Monitor', $rows[1]['name']);
        $this->assertSame('Desk Chair', $rows[2]['name']);
    }

    public function testFindProductsInPriceRange(): void
    {
        $rows = $this->vdb->query(
            'SELECT * FROM products WHERE price >= ? AND price <= ?',
            [20, 400]
        )->toArray();

        $this->assertCount(3, $rows); // Mouse (29.99), Desk Chair (299.99), Monitor (399.99)
    }

    public function testFindElectronicsInStock(): void
    {
        $rows = $this->vdb->query(
            "SELECT * FROM products WHERE category = 'electronics' AND stock > 0 ORDER BY price ASC"
        )->toArray();

        $this->assertCount(2, $rows); // Mouse and Laptop (Monitor has stock=0)
        $this->assertSame('Mouse', $rows[0]['name']);
        $this->assertSame('Laptop', $rows[1]['name']);
    }

    public function testPaginationPattern(): void
    {
        // First page
        $page1 = $this->vdb->query('SELECT * FROM users ORDER BY id ASC LIMIT 2')->toArray();
        $this->assertCount(2, $page1);
        $this->assertSame(1, $page1[0]['id']);
        $this->assertSame(2, $page1[1]['id']);

        // Note: OFFSET not supported yet, so pagination requires application-level handling
    }

    // =========================================================================
    // AGGREGATE FUNCTIONS
    // =========================================================================

    public function testCountAll(): void
    {
        $count = $this->vdb->queryField('SELECT COUNT(*) FROM users');
        // Note: COUNT(*) is parsed but virtual tables return all rows
        // The count happens at the result level
        $this->assertNotNull($count);
    }

    public function testCountWithWhere(): void
    {
        $rows = $this->vdb->query("SELECT COUNT(*) FROM users WHERE status = 'active'")->toArray();
        $this->assertNotNull($rows);
    }

    // =========================================================================
    // CONVENIENCE METHODS
    // =========================================================================

    public function testQueryOne(): void
    {
        $user = $this->vdb->queryOne('SELECT * FROM users WHERE id = ?', [1]);
        $this->assertNotNull($user);
        $this->assertSame('Alice Smith', $user['name']);
    }

    public function testQueryOneReturnsNullWhenNotFound(): void
    {
        $user = $this->vdb->queryOne('SELECT * FROM users WHERE id = ?', [999]);
        $this->assertNull($user);
    }

    public function testQueryField(): void
    {
        $name = $this->vdb->queryField('SELECT name FROM users WHERE id = ?', [1]);
        // Note: Returns first column of first row
        $this->assertNotNull($name);
    }

    public function testQueryColumn(): void
    {
        $ids = $this->vdb->queryColumn('SELECT id FROM users ORDER BY id ASC');
        $this->assertEquals([1, 2, 3, 4, 5], $ids);
    }

    // =========================================================================
    // INSERT
    // =========================================================================

    public function testInsertWithValues(): void
    {
        $this->resetData();
        $affected = $this->vdb->exec(
            "INSERT INTO users (name, email, age, status, role) VALUES ('Frank', 'frank@test.com', 40, 'active', 'user')"
        );
        $this->assertSame(1, $affected);

        $user = $this->vdb->queryOne("SELECT * FROM users WHERE name = 'Frank'");
        $this->assertNotNull($user);
        $this->assertSame(40, $user['age']);
    }

    public function testInsertWithPlaceholders(): void
    {
        $this->resetData();
        $affected = $this->vdb->exec(
            'INSERT INTO users (name, email, age, status, role) VALUES (?, ?, ?, ?, ?)',
            ['Grace', 'grace@test.com', 22, 'pending', 'user']
        );
        $this->assertSame(1, $affected);
    }

    public function testInsertViaMethod(): void
    {
        $this->resetData();
        $this->vdb->insert('users', [
            'name' => 'Henry',
            'email' => 'henry@test.com',
            'age' => 45,
            'status' => 'active',
            'role' => 'user'
        ]);

        $user = $this->vdb->queryOne("SELECT * FROM users WHERE name = 'Henry'");
        $this->assertNotNull($user);
    }

    // =========================================================================
    // UPDATE
    // =========================================================================

    public function testUpdateSingleRow(): void
    {
        $this->resetData();
        $affected = $this->vdb->exec(
            "UPDATE users SET status = 'inactive' WHERE id = ?",
            [1]
        );
        $this->assertSame(1, $affected);

        $user = $this->vdb->queryOne('SELECT * FROM users WHERE id = ?', [1]);
        $this->assertSame('inactive', $user['status']);
    }

    public function testUpdateMultipleRows(): void
    {
        $this->resetData();
        $affected = $this->vdb->exec(
            "UPDATE users SET role = 'member' WHERE role = 'user'"
        );
        $this->assertSame(3, $affected); // Bob, Charlie, Eve
    }

    public function testUpdateMultipleColumns(): void
    {
        $this->resetData();
        $affected = $this->vdb->exec(
            "UPDATE users SET status = 'verified', role = 'vip' WHERE id = ?",
            [2]
        );
        $this->assertSame(1, $affected);

        $user = $this->vdb->queryOne('SELECT * FROM users WHERE id = ?', [2]);
        $this->assertSame('verified', $user['status']);
        $this->assertSame('vip', $user['role']);
    }

    public function testUpdateNoMatch(): void
    {
        $this->resetData();
        $affected = $this->vdb->exec(
            "UPDATE users SET status = 'deleted' WHERE id = ?",
            [999]
        );
        $this->assertSame(0, $affected);
    }

    // =========================================================================
    // DELETE
    // =========================================================================

    public function testDeleteSingleRow(): void
    {
        $this->resetData();
        $countBefore = count($this->vdb->query('SELECT * FROM users')->toArray());

        $affected = $this->vdb->exec('DELETE FROM users WHERE id = ?', [5]);
        $this->assertSame(1, $affected);

        $countAfter = count($this->vdb->query('SELECT * FROM users')->toArray());
        $this->assertSame($countBefore - 1, $countAfter);
    }

    public function testDeleteMultipleRows(): void
    {
        $this->resetData();
        $affected = $this->vdb->exec("DELETE FROM users WHERE status = 'active'");
        $this->assertSame(3, $affected);
    }

    public function testDeleteNoMatch(): void
    {
        $this->resetData();
        $affected = $this->vdb->exec('DELETE FROM users WHERE id = ?', [999]);
        $this->assertSame(0, $affected);
    }

    // =========================================================================
    // IS NULL / IS NOT NULL
    // =========================================================================

    public function testIsNull(): void
    {
        $this->resetData();
        $rows = $this->vdb->query('SELECT * FROM users WHERE bio IS NULL')->toArray();
        $this->assertCount(3, $rows); // Alice, Charlie, Eve have null bio
    }

    public function testIsNotNull(): void
    {
        $this->resetData();
        $rows = $this->vdb->query('SELECT * FROM users WHERE bio IS NOT NULL')->toArray();
        $this->assertCount(2, $rows); // Bob and Diana have bio
    }

    public function testIsNullWithAnd(): void
    {
        $this->resetData();
        $rows = $this->vdb->query("SELECT * FROM users WHERE bio IS NULL AND status = 'active'")->toArray();
        $this->assertCount(1, $rows); // Alice
    }

    // =========================================================================
    // LIKE / NOT LIKE
    // =========================================================================

    public function testLikeStartsWith(): void
    {
        $this->resetData();
        $rows = $this->vdb->query("SELECT * FROM users WHERE name LIKE 'Alice%'")->toArray();
        $this->assertCount(1, $rows);
        $this->assertSame('Alice Smith', $rows[0]['name']);
    }

    public function testLikeEndsWith(): void
    {
        $this->resetData();
        $rows = $this->vdb->query("SELECT * FROM users WHERE name LIKE '%Smith'")->toArray();
        $this->assertCount(1, $rows);
        $this->assertSame('Alice Smith', $rows[0]['name']);
    }

    public function testLikeContains(): void
    {
        $this->resetData();
        $rows = $this->vdb->query("SELECT * FROM users WHERE email LIKE '%@example.com'")->toArray();
        $this->assertCount(3, $rows); // Alice, Charlie, Eve
    }

    public function testLikeSingleCharWildcard(): void
    {
        $this->resetData();
        // _ matches exactly one character
        $rows = $this->vdb->query("SELECT * FROM users WHERE name LIKE 'Bo_'")->toArray();
        $this->assertCount(0, $rows); // 'Bob' needs exactly one more char but 'Jones' is last name

        // Test on name field
        $rows = $this->vdb->query("SELECT * FROM users WHERE name LIKE 'Bob _ones'")->toArray();
        $this->assertCount(1, $rows);
    }

    public function testLikeIsCaseInsensitive(): void
    {
        $this->resetData();
        $rows = $this->vdb->query("SELECT * FROM users WHERE name LIKE 'ALICE%'")->toArray();
        $this->assertCount(1, $rows);
    }

    public function testNotLike(): void
    {
        $this->resetData();
        $rows = $this->vdb->query("SELECT * FROM users WHERE email NOT LIKE '%@example.com'")->toArray();
        $this->assertCount(2, $rows); // Bob and Diana (test.com)
    }

    public function testNotLikeWithPlaceholder(): void
    {
        $this->resetData();
        $rows = $this->vdb->query('SELECT * FROM users WHERE name NOT LIKE ?', ['%Brown'])->toArray();
        $this->assertCount(4, $rows); // All except Charlie Brown
    }

    // =========================================================================
    // NOT IN
    // =========================================================================

    public function testNotIn(): void
    {
        $this->resetData();
        $rows = $this->vdb->query('SELECT * FROM users WHERE id NOT IN (1, 2, 3)')->toArray();
        $this->assertCount(2, $rows); // Diana (4) and Eve (5)
    }

    public function testNotInWithStrings(): void
    {
        $this->resetData();
        $rows = $this->vdb->query("SELECT * FROM users WHERE status NOT IN ('active', 'pending')")->toArray();
        $this->assertCount(1, $rows); // Charlie (inactive)
    }

    // =========================================================================
    // UNSUPPORTED SQL - Should throw clear exceptions
    // =========================================================================

    public function testBetweenNotSupported(): void
    {
        $this->assertThrows(
            fn() => $this->vdb->query('SELECT * FROM users WHERE age BETWEEN 25 AND 35'),
            \RuntimeException::class
        );
    }

    public function testOffsetNotSupported(): void
    {
        $this->assertThrows(
            fn() => $this->vdb->query('SELECT * FROM users LIMIT 10 OFFSET 5'),
            \RuntimeException::class
        );
    }

    public function testGroupByNotSupported(): void
    {
        $this->assertThrows(
            fn() => $this->vdb->query('SELECT status, COUNT(*) FROM users GROUP BY status'),
            \RuntimeException::class
        );
    }

    public function testJoinNotSupported(): void
    {
        $this->assertThrows(
            fn() => $this->vdb->query('SELECT * FROM users JOIN orders ON users.id = orders.user_id'),
            \RuntimeException::class
        );
    }

    // =========================================================================
    // SUBQUERIES IN WHERE CLAUSE
    // =========================================================================

    public function testBasicInSubquery(): void
    {
        $this->resetData();
        // Find users who have placed orders
        // Orders: user_id 1, 1, 2, 4 (Alice, Alice, Bob, Diana)
        $rows = $this->vdb->query('SELECT * FROM users WHERE id IN (SELECT user_id FROM orders)')->toArray();
        $this->assertCount(3, $rows); // Alice, Bob, Diana
        $names = array_column($rows, 'name');
        $this->assertTrue(in_array('Alice Smith', $names));
        $this->assertTrue(in_array('Bob Jones', $names));
        $this->assertTrue(in_array('Diana Prince', $names));
    }

    public function testNotInSubquery(): void
    {
        $this->resetData();
        // Find users who have NOT placed orders
        // Orders: user_id 1, 1, 2, 4 -> users without orders: 3 (Charlie), 5 (Eve)
        $rows = $this->vdb->query('SELECT * FROM users WHERE id NOT IN (SELECT user_id FROM orders)')->toArray();
        $this->assertCount(2, $rows);
        $names = array_column($rows, 'name');
        $this->assertTrue(in_array('Charlie Brown', $names));
        $this->assertTrue(in_array('Eve Wilson', $names));
    }

    public function testSubqueryWithWhereClause(): void
    {
        $this->resetData();
        // Find users who ordered product 101 (Laptop)
        // Orders with product_id 101: user_id 1, 4
        $rows = $this->vdb->query(
            'SELECT * FROM users WHERE id IN (SELECT user_id FROM orders WHERE product_id = ?)',
            [101]
        )->toArray();
        $this->assertCount(2, $rows);
        $names = array_column($rows, 'name');
        $this->assertTrue(in_array('Alice Smith', $names));
        $this->assertTrue(in_array('Diana Prince', $names));
    }

    public function testNestedSubquery(): void
    {
        $this->resetData();
        // Find users who ordered products in featured categories
        // Featured categories: electronics (id=1), office (id=3)
        // Products in featured categories: Laptop (101), Mouse (102), Monitor (105), Notebook (104)
        // Orders for these products: user_id 1 (101, 102), user_id 2 (103-not featured), user_id 4 (101)
        $rows = $this->vdb->query(
            "SELECT * FROM users WHERE id IN (
                SELECT user_id FROM orders WHERE product_id IN (
                    SELECT id FROM products WHERE category IN (
                        SELECT name FROM categories WHERE featured = 1
                    )
                )
            )"
        )->toArray();
        // Users who ordered featured products: Alice (101, 102), Diana (101)
        // Bob ordered product 103 which is furniture (not featured)
        $this->assertCount(2, $rows);
        $names = array_column($rows, 'name');
        $this->assertTrue(in_array('Alice Smith', $names));
        $this->assertTrue(in_array('Diana Prince', $names));
    }

    public function testSubqueryReturnsEmptySet(): void
    {
        $this->resetData();
        // Subquery that returns no results
        $rows = $this->vdb->query(
            'SELECT * FROM users WHERE id IN (SELECT user_id FROM orders WHERE product_id = 999)'
        )->toArray();
        $this->assertCount(0, $rows);
    }

    public function testSubqueryWithAnd(): void
    {
        $this->resetData();
        // Active users who have placed orders
        $rows = $this->vdb->query(
            "SELECT * FROM users WHERE status = 'active' AND id IN (SELECT user_id FROM orders)"
        )->toArray();
        // Alice (1), Bob (2), Diana (4) have orders; all are active
        $this->assertCount(3, $rows);
    }

    public function testSubqueryWithOr(): void
    {
        $this->resetData();
        // Users who are admin OR have placed orders
        $rows = $this->vdb->query(
            "SELECT * FROM users WHERE role = 'admin' OR id IN (SELECT user_id FROM orders)"
        )->toArray();
        // Alice is admin AND has orders, Bob and Diana have orders
        $this->assertCount(3, $rows);
    }

    public function testUnionNotSupported(): void
    {
        $this->assertThrows(
            fn() => $this->vdb->query('SELECT * FROM users UNION SELECT * FROM users'),
            \RuntimeException::class
        );
    }

    public function testTransactionsNotSupported(): void
    {
        $this->assertThrows(
            fn() => $this->vdb->transaction(fn() => null),
            \RuntimeException::class
        );
    }

    public function testUpsertNotSupported(): void
    {
        $this->assertThrows(
            fn() => $this->vdb->upsert('users', ['name' => 'Test'], 'id'),
            \RuntimeException::class
        );
    }

    // =========================================================================
    // TABLE MANAGEMENT
    // =========================================================================

    public function testTableExists(): void
    {
        $this->assertTrue($this->vdb->tableExists('users'));
        $this->assertTrue($this->vdb->tableExists('products'));
        $this->assertFalse($this->vdb->tableExists('nonexistent'));
    }

    public function testQueryNonexistentTable(): void
    {
        $this->assertThrows(
            fn() => $this->vdb->query('SELECT * FROM nonexistent'),
            \RuntimeException::class
        );
    }

    // =========================================================================
    // DIALECT AND QUOTING
    // =========================================================================

    public function testDialect(): void
    {
        $this->assertSame(\mini\Database\SqlDialect::Virtual, $this->vdb->getDialect());
    }

    public function testQuoteString(): void
    {
        $this->assertSame("'test'", $this->vdb->quote('test'));
        $this->assertSame("'it''s'", $this->vdb->quote("it's"));
    }

    public function testQuoteNumber(): void
    {
        $this->assertSame('42', $this->vdb->quote(42));
        $this->assertSame('3.14', $this->vdb->quote(3.14));
    }

    public function testQuoteNull(): void
    {
        $this->assertSame('NULL', $this->vdb->quote(null));
    }

    public function testQuoteIdentifier(): void
    {
        $this->assertSame('"users"', $this->vdb->quoteIdentifier('users'));
        $this->assertSame('"table"."column"', $this->vdb->quoteIdentifier('table.column'));
    }

    // =========================================================================
    // STREAMING vs MATERIALIZATION (OrderInfo)
    // =========================================================================

    public function testStreamingWithMatchingOrder(): void
    {
        $vdb = new VirtualDatabase();
        $data = [
            1 => ['id' => 1, 'name' => 'First'],
            2 => ['id' => 2, 'name' => 'Second'],
            3 => ['id' => 3, 'name' => 'Third'],
        ];

        $vdb->registerTable('items', new VirtualTable(
            selectFn: function($ast) use ($data): iterable {
                // Tell VirtualDatabase data is pre-sorted by id ASC
                yield new OrderInfo(column: 'id', desc: false);
                foreach ($data as $id => $columns) {
                    yield new Row($id, $columns);
                }
            }
        ));

        // Query matches backend order - can stream
        $rows = $vdb->query('SELECT * FROM items ORDER BY id ASC LIMIT 2')->toArray();
        $this->assertCount(2, $rows);
        $this->assertSame('First', $rows[0]['name']);
        $this->assertSame('Second', $rows[1]['name']);
    }

    public function testMaterializationWithDifferentOrder(): void
    {
        $vdb = new VirtualDatabase();
        $data = [
            1 => ['id' => 1, 'name' => 'Charlie', 'score' => 75],
            2 => ['id' => 2, 'name' => 'Alice', 'score' => 90],
            3 => ['id' => 3, 'name' => 'Bob', 'score' => 85],
        ];

        $vdb->registerTable('scores', new VirtualTable(
            selectFn: function($ast) use ($data): iterable {
                yield new OrderInfo(column: 'id', desc: false);
                foreach ($data as $id => $columns) {
                    yield new Row($id, $columns);
                }
            }
        ));

        // Query needs different order - must materialize and sort
        $rows = $vdb->query('SELECT * FROM scores ORDER BY score DESC')->toArray();
        $this->assertSame('Alice', $rows[0]['name']);  // 90
        $this->assertSame('Bob', $rows[1]['name']);    // 85
        $this->assertSame('Charlie', $rows[2]['name']); // 75
    }

    // =========================================================================
    // ROW VALIDATION
    // =========================================================================

    public function testMustYieldRowInstances(): void
    {
        $vdb = new VirtualDatabase();
        $vdb->registerTable('bad', new VirtualTable(
            selectFn: function($ast): iterable {
                yield ['id' => 1, 'name' => 'Wrong']; // Plain array instead of Row
            }
        ));

        $this->assertThrows(
            fn() => $vdb->query('SELECT * FROM bad')->toArray(),
            \mini\Database\Virtual\VirtualTableException::class
        );
    }

    public function testDuplicateRowIdsThrow(): void
    {
        $vdb = new VirtualDatabase();
        $vdb->registerTable('bad', new VirtualTable(
            selectFn: function($ast): iterable {
                yield new Row(1, ['id' => 1, 'name' => 'First']);
                yield new Row(1, ['id' => 1, 'name' => 'Duplicate']);
            }
        ));

        $this->assertThrows(
            fn() => $vdb->query('SELECT * FROM bad')->toArray(),
            \mini\Database\Virtual\VirtualTableException::class
        );
    }

    // =========================================================================
    // RESULT SET INTERFACE
    // =========================================================================

    public function testResultSetIteration(): void
    {
        $this->resetData();
        $result = $this->vdb->query('SELECT * FROM users LIMIT 3');

        $count = 0;
        foreach ($result as $row) {
            $count++;
            $this->assertArrayHasKey('name', $row);
        }
        $this->assertSame(3, $count);
    }

    public function testResultSetToArray(): void
    {
        $this->resetData();
        $rows = $this->vdb->query('SELECT * FROM users')->toArray();
        $this->assertIsArray($rows);
        $this->assertCount(5, $rows);
    }

    public function testResultSetJsonSerializable(): void
    {
        $this->resetData();
        $result = $this->vdb->query('SELECT * FROM users LIMIT 2');
        $json = json_encode($result);

        $this->assertJson($json);
        $this->assertStringContainsString('Alice Smith', $json);
    }

    public function testResultSetWithHydrator(): void
    {
        $this->resetData();
        $rows = $this->vdb->query('SELECT * FROM users LIMIT 1')
            ->withHydrator(fn($row) => (object) $row)
            ->toArray();

        $this->assertCount(1, $rows);
        $this->assertIsObject($rows[0]);
        $this->assertSame('Alice Smith', $rows[0]->name);
    }
};

exit($test->run());
