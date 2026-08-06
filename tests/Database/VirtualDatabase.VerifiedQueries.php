<?php
/**
 * VirtualDatabase Verified Queries - CLI Integration Tests
 *
 * These tests run actual CLI queries against `bin/mini db -v` and compare
 * the output to known-good results. This freezes working query behavior.
 *
 * Run with: bin/mini test tests/Database/VirtualDatabase.VerifiedQueries.php
 *
 * KNOWN UNSUPPORTED FEATURES (as of 2025-12-22):
 * - Reserved words as aliases (DESC, ASC, etc.) - use different alias names
 * - CTEs / WITH clause
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Test;

$test = new class extends Test {

    private function query(string $sql, string $format = 'csv'): string
    {
        $cmd = sprintf(
            'bin/mini db -v --format=%s %s 2>&1',
            escapeshellarg($format),
            escapeshellarg($sql)
        );
        return trim(shell_exec($cmd));
    }

    // =========================================================================
    // Basic SELECT
    // =========================================================================

    public function testSelectAllUsers(): void
    {
        $result = $this->query('SELECT * FROM users;');
        $expected = <<<'CSV'
id,name,email,role,active
1,Alice,alice@example.com,admin,1
2,Bob,bob@example.com,user,1
3,Charlie,charlie@example.com,user,0
CSV;
        $this->assertSame($expected, $result);
    }

    public function testSelectSpecificColumns(): void
    {
        $result = $this->query('SELECT id, name FROM users;');
        $expected = <<<'CSV'
id,name
1,Alice
2,Bob
3,Charlie
CSV;
        $this->assertSame($expected, $result);
    }

    public function testSelectAllProducts(): void
    {
        $result = $this->query('SELECT * FROM products;');
        $expected = <<<'CSV'
id,name,price,category,stock
1,Widget,9.99,gadgets,100
2,Gizmo,24.99,gadgets,50
3,Thingamajig,14.99,tools,75
4,Doohickey,4.99,tools,200
CSV;
        $this->assertSame($expected, $result);
    }

    // =========================================================================
    // WHERE clause - equality
    // =========================================================================

    public function testWhereEqualsString(): void
    {
        $result = $this->query("SELECT id, name FROM users WHERE role = 'admin';");
        $expected = <<<'CSV'
id,name
1,Alice
CSV;
        $this->assertSame($expected, $result);
    }

    public function testWhereEqualsInt(): void
    {
        $result = $this->query('SELECT id, name FROM users WHERE active = 1;');
        $expected = <<<'CSV'
id,name
1,Alice
2,Bob
CSV;
        $this->assertSame($expected, $result);
    }

    // =========================================================================
    // WHERE clause - comparisons
    // =========================================================================

    public function testWhereGreaterThan(): void
    {
        $result = $this->query('SELECT name, price FROM products WHERE price > 10;');
        $expected = <<<'CSV'
name,price
Gizmo,24.99
Thingamajig,14.99
CSV;
        $this->assertSame($expected, $result);
    }

    public function testWhereLessThan(): void
    {
        $result = $this->query('SELECT name, price FROM products WHERE price < 10;');
        $expected = <<<'CSV'
name,price
Widget,9.99
Doohickey,4.99
CSV;
        $this->assertSame($expected, $result);
    }

    public function testWhereRange(): void
    {
        $result = $this->query('SELECT name, price FROM products WHERE price >= 10 AND price <= 20;');
        $expected = <<<'CSV'
name,price
Thingamajig,14.99
CSV;
        $this->assertSame($expected, $result);
    }

    // =========================================================================
    // WHERE clause - AND/OR
    // =========================================================================

    public function testWhereAnd(): void
    {
        $result = $this->query("SELECT name FROM products WHERE category = 'gadgets' AND price > 10;");
        $expected = <<<'CSV'
name
Gizmo
CSV;
        $this->assertSame($expected, $result);
    }

    public function testWhereOr(): void
    {
        $result = $this->query("SELECT name FROM users WHERE role = 'admin' OR active = 0;");
        $expected = <<<'CSV'
name
Alice
Charlie
CSV;
        $this->assertSame($expected, $result);
    }

    // =========================================================================
    // WHERE clause - IN / LIKE
    // =========================================================================

    public function testWhereIn(): void
    {
        $result = $this->query('SELECT name FROM users WHERE id IN (1, 3);');
        $expected = <<<'CSV'
name
Alice
Charlie
CSV;
        $this->assertSame($expected, $result);
    }

    public function testWhereLikePrefix(): void
    {
        $result = $this->query("SELECT name FROM users WHERE name LIKE 'A%';");
        $expected = <<<'CSV'
name
Alice
CSV;
        $this->assertSame($expected, $result);
    }

    public function testWhereLikeContains(): void
    {
        $result = $this->query("SELECT name FROM users WHERE name LIKE '%ob%';");
        $expected = <<<'CSV'
name
Bob
CSV;
        $this->assertSame($expected, $result);
    }

    // =========================================================================
    // ORDER BY
    // =========================================================================

    public function testOrderByAsc(): void
    {
        $result = $this->query('SELECT name, price FROM products ORDER BY price;');
        $expected = <<<'CSV'
name,price
Doohickey,4.99
Widget,9.99
Thingamajig,14.99
Gizmo,24.99
CSV;
        $this->assertSame($expected, $result);
    }

    public function testOrderByDesc(): void
    {
        $result = $this->query('SELECT name, price FROM products ORDER BY price DESC;');
        $expected = <<<'CSV'
name,price
Gizmo,24.99
Thingamajig,14.99
Widget,9.99
Doohickey,4.99
CSV;
        $this->assertSame($expected, $result);
    }

    public function testOrderByMultipleColumns(): void
    {
        $result = $this->query('SELECT name, category, price FROM products ORDER BY category, price DESC;');
        $expected = <<<'CSV'
name,category,price
Gizmo,gadgets,24.99
Widget,gadgets,9.99
Thingamajig,tools,14.99
Doohickey,tools,4.99
CSV;
        $this->assertSame($expected, $result);
    }

    public function testOrderByExpression(): void
    {
        // ORDER BY arithmetic expression
        $result = $this->query('SELECT name, price * stock AS value FROM products ORDER BY price * stock DESC LIMIT 2;');
        $expected = <<<'CSV'
name,value
Gizmo,1249.5
Thingamajig,1124.25
CSV;
        $this->assertSame($expected, $result);
    }

    public function testOrderByAlias(): void
    {
        // ORDER BY column alias from SELECT
        $result = $this->query('SELECT name, price * stock AS value FROM products ORDER BY value DESC LIMIT 2;');
        $expected = <<<'CSV'
name,value
Gizmo,1249.5
Thingamajig,1124.25
CSV;
        $this->assertSame($expected, $result);
    }

    // =========================================================================
    // LIMIT and OFFSET
    // =========================================================================

    public function testLimit(): void
    {
        $result = $this->query('SELECT name FROM users LIMIT 2;');
        $expected = <<<'CSV'
name
Alice
Bob
CSV;
        $this->assertSame($expected, $result);
    }

    public function testLimitOffset(): void
    {
        $result = $this->query('SELECT name FROM users LIMIT 2 OFFSET 1;');
        $expected = <<<'CSV'
name
Bob
Charlie
CSV;
        $this->assertSame($expected, $result);
    }

    // =========================================================================
    // DISTINCT
    // =========================================================================

    public function testDistinctRole(): void
    {
        $result = $this->query('SELECT DISTINCT role FROM users;');
        $expected = <<<'CSV'
role
admin
user
CSV;
        $this->assertSame($expected, $result);
    }

    public function testDistinctCategory(): void
    {
        $result = $this->query('SELECT DISTINCT category FROM products;');
        $expected = <<<'CSV'
category
gadgets
tools
CSV;
        $this->assertSame($expected, $result);
    }

    // =========================================================================
    // Aggregate functions
    // =========================================================================

    public function testCount(): void
    {
        $result = $this->query('SELECT COUNT(*) AS cnt FROM users;');
        $expected = <<<'CSV'
cnt
3
CSV;
        $this->assertSame($expected, $result);
    }

    public function testCountWithWhere(): void
    {
        $result = $this->query('SELECT COUNT(*) AS cnt FROM users WHERE active = 1;');
        $expected = <<<'CSV'
cnt
2
CSV;
        $this->assertSame($expected, $result);
    }

    public function testSum(): void
    {
        $result = $this->query('SELECT SUM(total) AS total_sales FROM orders;');
        $expected = <<<'CSV'
total_sales
109.94
CSV;
        $this->assertSame($expected, $result);
    }

    public function testMinMax(): void
    {
        $result = $this->query('SELECT MIN(price) AS cheapest, MAX(price) AS expensive FROM products;');
        $expected = <<<'CSV'
cheapest,expensive
4.99,24.99
CSV;
        $this->assertSame($expected, $result);
    }

    public function testAvg(): void
    {
        $result = $this->query('SELECT AVG(price) AS avg_price FROM products;');
        $expected = <<<'CSV'
avg_price
13.74
CSV;
        $this->assertSame($expected, $result);
    }

    // =========================================================================
    // Expressions
    // =========================================================================

    public function testArithmeticExpression(): void
    {
        $result = $this->query('SELECT name, price * 2 AS double_price FROM products LIMIT 2;');
        $expected = <<<'CSV'
name,double_price
Widget,19.98
Gizmo,49.98
CSV;
        $this->assertSame($expected, $result);
    }

    // =========================================================================
    // Subqueries
    // =========================================================================

    public function testInSubquery(): void
    {
        $result = $this->query('SELECT name FROM users WHERE id IN (SELECT user_id FROM orders);');
        $expected = <<<'CSV'
name
Alice
Bob
CSV;
        $this->assertSame($expected, $result);
    }

    public function testNotInSubquery(): void
    {
        $result = $this->query('SELECT name FROM products WHERE id NOT IN (SELECT product_id FROM orders);');
        $expected = <<<'CSV'
name
Doohickey
CSV;
        $this->assertSame($expected, $result);
    }

    public function testScalarSubqueryInSelect(): void
    {
        // Correlated scalar subquery in SELECT list
        $result = $this->query('SELECT name, (SELECT COUNT(*) FROM orders WHERE orders.user_id = users.id) AS order_count FROM users;');
        $expected = <<<'CSV'
name,order_count
Alice,2
Bob,1
Charlie,0
CSV;
        $this->assertSame($expected, $result);
    }

    public function testScalarSubqueryInWhere(): void
    {
        // Scalar subquery as comparison value in nested IN subquery
        $result = $this->query('SELECT name FROM users WHERE id IN (SELECT user_id FROM orders WHERE total > (SELECT AVG(total) FROM orders));');
        $expected = <<<'CSV'
name
Bob
CSV;
        $this->assertSame($expected, $result);
    }

    public function testDerivedTable(): void
    {
        // Subquery in FROM position
        $result = $this->query('SELECT * FROM (SELECT id, name FROM users WHERE active = 1) AS active_users;');
        $expected = <<<'CSV'
id,name
1,Alice
2,Bob
CSV;
        $this->assertSame($expected, $result);
    }

    public function testDerivedTableWithJoin(): void
    {
        // JOIN with derived table containing GROUP BY
        $result = $this->query('SELECT u.name, o.total FROM users u JOIN (SELECT user_id, SUM(total) AS total FROM orders GROUP BY user_id) o ON u.id = o.user_id;');
        $expected = <<<'CSV'
name,total
Alice,34.97
Bob,74.97
CSV;
        $this->assertSame($expected, $result);
    }

    public function testAllQuantifier(): void
    {
        // ALL: comparison must be true for all values in subquery
        // Tool prices: 14.99 and 4.99. Only Gizmo (24.99) > both.
        $result = $this->query("SELECT name FROM products WHERE price > ALL (SELECT price FROM products WHERE category = 'tools');");
        $expected = <<<'CSV'
name
Gizmo
CSV;
        $this->assertSame($expected, $result);
    }

    public function testAnyQuantifier(): void
    {
        // ANY: comparison must be true for at least one value
        // Tool prices: 14.99 and 4.99. Gizmo, Thingamajig, Widget are all > 4.99.
        $result = $this->query("SELECT name FROM products WHERE price > ANY (SELECT price FROM products WHERE category = 'tools') ORDER BY name;");
        $expected = <<<'CSV'
name
Gizmo
Thingamajig
Widget
CSV;
        $this->assertSame($expected, $result);
    }

    // =========================================================================
    // Generated tables
    // =========================================================================

    public function testFibonacciFirst10(): void
    {
        $result = $this->query('SELECT value FROM fibonacci LIMIT 10;');
        $expected = <<<'CSV'
value
0
1
1
2
3
5
8
13
21
34
CSV;
        $this->assertSame($expected, $result);
    }

    public function testSequenceSquares(): void
    {
        $result = $this->query('SELECT id, value FROM sequence WHERE id <= 5;');
        $expected = <<<'CSV'
id,value
1,1
2,4
3,9
4,16
5,25
CSV;
        $this->assertSame($expected, $result);
    }

    public function testPrimesFirst10(): void
    {
        $result = $this->query('SELECT value FROM primes LIMIT 10;');
        $expected = <<<'CSV'
value
2
3
5
7
11
13
17
19
23
29
CSV;
        $this->assertSame($expected, $result);
    }

    public function testPrimesFiltered(): void
    {
        $result = $this->query('SELECT value FROM primes WHERE value > 20 AND value < 40;');
        $expected = <<<'CSV'
value
23
29
31
37
CSV;
        $this->assertSame($expected, $result);
    }

    // =========================================================================
    // Combined queries
    // =========================================================================

    public function testCombinedWhereOrderLimit(): void
    {
        $result = $this->query("SELECT name, price FROM products WHERE category = 'tools' ORDER BY price DESC LIMIT 1;");
        $expected = <<<'CSV'
name,price
Thingamajig,14.99
CSV;
        $this->assertSame($expected, $result);
    }

    // =========================================================================
    // EXISTS subqueries (correlated and non-correlated)
    // =========================================================================

    public function testExistsCorrelated(): void
    {
        $result = $this->query('SELECT name FROM users WHERE EXISTS (SELECT 1 FROM orders WHERE orders.user_id = users.id);');
        $expected = <<<'CSV'
name
Alice
Bob
CSV;
        $this->assertSame($expected, $result);
    }

    public function testNotExistsCorrelated(): void
    {
        $result = $this->query('SELECT name FROM users WHERE NOT EXISTS (SELECT 1 FROM orders WHERE orders.user_id = users.id AND orders.total > 50);');
        $expected = <<<'CSV'
name
Alice
Charlie
CSV;
        $this->assertSame($expected, $result);
    }

    // =========================================================================
    // UNION
    // =========================================================================

    public function testUnionBasic(): void
    {
        $result = $this->query('SELECT id, name FROM users UNION SELECT id, name FROM products;');
        $expected = <<<'CSV'
id,name
1,Alice
2,Bob
3,Charlie
1,Widget
2,Gizmo
3,Thingamajig
4,Doohickey
CSV;
        $this->assertSame($expected, $result);
    }

    public function testUnionWithWhere(): void
    {
        $result = $this->query("SELECT name FROM users WHERE id IN (1,2) UNION SELECT name FROM users WHERE id = 3;");
        $expected = <<<'CSV'
name
Alice
Bob
Charlie
CSV;
        $this->assertSame($expected, $result);
    }

    // =========================================================================
    // Nested subqueries
    // =========================================================================

    public function testNestedSubqueries(): void
    {
        // Users who ordered products in the 'gadgets' category
        $result = $this->query("SELECT name FROM users WHERE id IN (SELECT user_id FROM orders WHERE product_id IN (SELECT id FROM products WHERE category = 'gadgets'));");
        $expected = <<<'CSV'
name
Alice
Bob
CSV;
        $this->assertSame($expected, $result);
    }

    // =========================================================================
    // SQL Functions
    // =========================================================================

    public function testUpperFunction(): void
    {
        $result = $this->query('SELECT UPPER(name) AS upper_name FROM users;');
        $expected = <<<'CSV'
upper_name
ALICE
BOB
CHARLIE
CSV;
        $this->assertSame($expected, $result);
    }

    public function testLowerAndLengthFunctions(): void
    {
        $result = $this->query('SELECT LOWER(name) AS lower_name, LENGTH(name) AS len FROM users;');
        $expected = <<<'CSV'
lower_name,len
alice,5
bob,3
charlie,7
CSV;
        $this->assertSame($expected, $result);
    }

    public function testConcatFunction(): void
    {
        $result = $this->query("SELECT CONCAT(name, ' - ', role) AS info FROM users LIMIT 2;");
        $expected = <<<'CSV'
info
Alice - admin
Bob - user
CSV;
        $this->assertSame($expected, $result);
    }

    public function testSubstrFunction(): void
    {
        $result = $this->query('SELECT SUBSTR(name, 1, 3) AS short FROM users;');
        $expected = <<<'CSV'
short
Ali
Bob
Cha
CSV;
        $this->assertSame($expected, $result);
    }

    public function testTrimFunction(): void
    {
        $result = $this->query("SELECT TRIM('  hello  ') AS trimmed;");
        $expected = <<<'CSV'
trimmed
hello
CSV;
        $this->assertSame($expected, $result);
    }

    public function testCoalesceFunction(): void
    {
        $result = $this->query("SELECT COALESCE(NULL, name) AS result FROM users LIMIT 1;");
        $expected = <<<'CSV'
result
Alice
CSV;
        $this->assertSame($expected, $result);
    }

    public function testIfnullFunction(): void
    {
        $result = $this->query("SELECT IFNULL(NULL, 'default') AS result;");
        $expected = <<<'CSV'
result
default
CSV;
        $this->assertSame($expected, $result);
    }

    public function testAbsAndRoundFunctions(): void
    {
        $result = $this->query('SELECT ABS(-5) AS abs_val, ROUND(3.7) AS rounded;');
        $expected = <<<'CSV'
abs_val,rounded
5,4
CSV;
        $this->assertSame($expected, $result);
    }

    // =========================================================================
    // BETWEEN
    // =========================================================================

    public function testBetween(): void
    {
        $result = $this->query('SELECT name, price FROM products WHERE price BETWEEN 5 AND 15;');
        $expected = <<<'CSV'
name,price
Widget,9.99
Thingamajig,14.99
CSV;
        $this->assertSame($expected, $result);
    }

    // =========================================================================
    // NULL handling (uses contacts table with real NULL values)
    // =========================================================================

    public function testSelectWithNulls(): void
    {
        $result = $this->query('SELECT id, name, email FROM contacts;');
        $expected = <<<'CSV'
id,name,email
1,Alice,alice@test.com
2,Bob,
3,Charlie,charlie@test.com
4,Diana,
5,,unknown@test.com
CSV;
        $this->assertSame($expected, $result);
    }

    public function testIsNull(): void
    {
        $result = $this->query('SELECT id, name FROM contacts WHERE email IS NULL;');
        $expected = <<<'CSV'
id,name
2,Bob
4,Diana
CSV;
        $this->assertSame($expected, $result);
    }

    public function testIsNotNull(): void
    {
        $result = $this->query('SELECT id, name FROM contacts WHERE email IS NOT NULL;');
        $expected = <<<'CSV'
id,name
1,Alice
3,Charlie
5,
CSV;
        $this->assertSame($expected, $result);
    }

    public function testIsNullAndIsNotNull(): void
    {
        // Contacts with phone but no email
        $result = $this->query('SELECT id, name FROM contacts WHERE phone IS NOT NULL AND email IS NULL;');
        $expected = <<<'CSV'
id,name
2,Bob
CSV;
        $this->assertSame($expected, $result);
    }

    public function testCoalesceWithNulls(): void
    {
        $result = $this->query("SELECT id, COALESCE(name, 'Unknown') AS display_name FROM contacts;");
        $expected = <<<'CSV'
id,display_name
1,Alice
2,Bob
3,Charlie
4,Diana
5,Unknown
CSV;
        $this->assertSame($expected, $result);
    }

    public function testIfnullWithNulls(): void
    {
        $result = $this->query("SELECT id, IFNULL(phone, 'N/A') AS phone_display FROM contacts;");
        $expected = <<<'CSV'
id,phone_display
1,555-0001
2,555-0002
3,N/A
4,N/A
5,555-0005
CSV;
        $this->assertSame($expected, $result);
    }

    public function testMultipleNullColumns(): void
    {
        // Contacts where both email and phone are NULL
        $result = $this->query('SELECT id, name FROM contacts WHERE email IS NULL AND phone IS NULL;');
        $expected = <<<'CSV'
id,name
4,Diana
CSV;
        $this->assertSame($expected, $result);
    }

    public function testEqualsNullReturnsNoRows(): void
    {
        // SQL standard: col = NULL always returns no rows (NULL = NULL is UNKNOWN, not TRUE)
        // Use IS NULL for NULL comparison instead
        $result = $this->query('SELECT id, name FROM contacts WHERE email = NULL;');
        // Should return only header, no data rows
        $expected = '';
        $this->assertSame($expected, $result);
    }

    public function testEqualsNullInOrReturnsOtherBranch(): void
    {
        // In an OR clause, the = NULL branch should match nothing, but other branch works
        $result = $this->query("SELECT id, name FROM contacts WHERE email = NULL OR name = 'Alice';");
        $expected = <<<'CSV'
id,name
1,Alice
CSV;
        $this->assertSame($expected, $result);
    }

    // =========================================================================
    // Literal expressions
    // =========================================================================

    public function testLiteralArithmetic(): void
    {
        $result = $this->query('SELECT 1 + 2 AS three, 10 / 3 AS division;');
        $expected = <<<'CSV'
three,division
3,3.3333333333333
CSV;
        $this->assertSame($expected, $result);
    }

    public function testArithmeticInSelect(): void
    {
        $result = $this->query('SELECT id + 1 AS next_id, price - 1 AS discounted FROM products LIMIT 2;');
        $expected = <<<'CSV'
next_id,discounted
2,8.99
3,23.99
CSV;
        $this->assertSame($expected, $result);
    }

    // =========================================================================
    // JOIN operations
    // =========================================================================

    public function testCrossJoin(): void
    {
        $result = $this->query('SELECT u.name, p.name AS product FROM users u CROSS JOIN products p WHERE u.id = 1 LIMIT 2;');
        $expected = <<<'CSV'
name,product
Alice,Widget
Alice,Gizmo
CSV;
        $this->assertSame($expected, $result);
    }

    public function testInnerJoin(): void
    {
        $result = $this->query('SELECT u.name, o.total FROM users u INNER JOIN orders o ON u.id = o.user_id;');
        $expected = <<<'CSV'
name,total
Alice,19.98
Alice,14.99
Bob,74.97
CSV;
        $this->assertSame($expected, $result);
    }

    public function testInnerJoinWithWhere(): void
    {
        $result = $this->query('SELECT u.name, o.total FROM users u INNER JOIN orders o ON u.id = o.user_id WHERE o.total > 20;');
        $expected = <<<'CSV'
name,total
Bob,74.97
CSV;
        $this->assertSame($expected, $result);
    }

    public function testLeftJoin(): void
    {
        $result = $this->query('SELECT u.name, o.total FROM users u LEFT JOIN orders o ON u.id = o.user_id;');
        $expected = <<<'CSV'
name,total
Alice,19.98
Alice,14.99
Bob,74.97
Charlie,
CSV;
        $this->assertSame($expected, $result);
    }

    public function testLeftJoinNoMatch(): void
    {
        // Charlie has no orders - should show NULL for order columns
        $result = $this->query("SELECT u.name, o.id AS order_id FROM users u LEFT JOIN orders o ON u.id = o.user_id WHERE u.name = 'Charlie';");
        $expected = <<<'CSV'
name,order_id
Charlie,
CSV;
        $this->assertSame($expected, $result);
    }

    public function testThreeWayJoin(): void
    {
        $result = $this->query('SELECT u.name, p.name AS product, o.quantity FROM users u INNER JOIN orders o ON u.id = o.user_id INNER JOIN products p ON o.product_id = p.id ORDER BY o.id;');
        $expected = <<<'CSV'
name,product,quantity
Alice,Widget,2
Alice,Thingamajig,1
Bob,Gizmo,3
CSV;
        $this->assertSame($expected, $result);
    }

    public function testJoinWithOrderBy(): void
    {
        $result = $this->query('SELECT u.name, o.total FROM users u INNER JOIN orders o ON u.id = o.user_id ORDER BY o.total DESC;');
        $expected = <<<'CSV'
name,total
Bob,74.97
Alice,19.98
Alice,14.99
CSV;
        $this->assertSame($expected, $result);
    }

    public function testJoinSelectAll(): void
    {
        // Select * from join - columns are prefixed with table alias
        $result = $this->query('SELECT * FROM users u INNER JOIN orders o ON u.id = o.user_id WHERE o.id = 1;');
        $expected = <<<'CSV'
u.id,u.name,u.email,u.role,u.active,o.id,o.user_id,o.product_id,o.quantity,o.total
1,Alice,alice@example.com,admin,1,1,1,1,2,19.98
CSV;
        $this->assertSame($expected, $result);
    }

    public function testJoinWithLimit(): void
    {
        $result = $this->query('SELECT u.name, o.total FROM users u INNER JOIN orders o ON u.id = o.user_id LIMIT 2;');
        $expected = <<<'CSV'
name,total
Alice,19.98
Alice,14.99
CSV;
        $this->assertSame($expected, $result);
    }

    public function testRightJoin(): void
    {
        // RIGHT JOIN: all orders, with user data where available
        // Order id=4 has user_id=99 which doesn't exist - should show NULL for user columns
        $result = $this->query('SELECT u.name, o.total FROM users u RIGHT JOIN orders o ON u.id = o.user_id ORDER BY o.id;');
        $expected = <<<'CSV'
name,total
Alice,19.98
Alice,14.99
Bob,74.97
CSV;
        $this->assertSame($expected, $result);
    }

    public function testRightJoinWithNullLeft(): void
    {
        // Test RIGHT JOIN with unmatched right row (need test data with unmatched order)
        // Using products RIGHT JOIN orders - products 4 (Doohickey) has no orders
        $result = $this->query('SELECT p.name, o.quantity FROM products p RIGHT JOIN orders o ON p.id = o.product_id ORDER BY o.id;');
        $expected = <<<'CSV'
name,quantity
Widget,2
Thingamajig,1
Gizmo,3
CSV;
        $this->assertSame($expected, $result);
    }

    public function testFullJoin(): void
    {
        // FULL JOIN: all users and all orders, matched where possible
        $result = $this->query('SELECT u.name, o.total FROM users u FULL JOIN orders o ON u.id = o.user_id ORDER BY u.id, o.id;');
        $expected = <<<'CSV'
name,total
Alice,19.98
Alice,14.99
Bob,74.97
Charlie,
CSV;
        $this->assertSame($expected, $result);
    }

    public function testFullJoinWithUnmatchedBothSides(): void
    {
        // FULL JOIN between products and orders - shows unmatched on both sides
        // Product 4 (Doohickey) has no orders
        $result = $this->query('SELECT p.name, o.id AS order_id FROM products p FULL JOIN orders o ON p.id = o.product_id ORDER BY p.id, o.id;');
        $expected = <<<'CSV'
name,order_id
Widget,1
Gizmo,3
Thingamajig,2
Doohickey,
CSV;
        $this->assertSame($expected, $result);
    }

    // =========================================================================
    // WHERE clause - NOT EQUAL operators
    // =========================================================================

    public function testWhereNotEqual(): void
    {
        $result = $this->query("SELECT name FROM users WHERE role != 'admin';");
        $expected = <<<'CSV'
name
Bob
Charlie
CSV;
        $this->assertSame($expected, $result);
    }

    public function testWhereNotEqualAngleBrackets(): void
    {
        $result = $this->query("SELECT name FROM users WHERE role <> 'admin';");
        $expected = <<<'CSV'
name
Bob
Charlie
CSV;
        $this->assertSame($expected, $result);
    }

    // =========================================================================
    // WHERE clause - NOT BETWEEN
    // =========================================================================

    public function testNotBetween(): void
    {
        $result = $this->query('SELECT name, price FROM products WHERE price NOT BETWEEN 5 AND 15;');
        $expected = <<<'CSV'
name,price
Gizmo,24.99
Doohickey,4.99
CSV;
        $this->assertSame($expected, $result);
    }

    // =========================================================================
    // WHERE clause - NOT LIKE
    // =========================================================================

    public function testNotLike(): void
    {
        $result = $this->query("SELECT name FROM users WHERE name NOT LIKE 'A%';");
        $expected = <<<'CSV'
name
Bob
Charlie
CSV;
        $this->assertSame($expected, $result);
    }

    public function testLikeSuffix(): void
    {
        $result = $this->query("SELECT name FROM users WHERE name LIKE '%e';");
        $expected = <<<'CSV'
name
Alice
Charlie
CSV;
        $this->assertSame($expected, $result);
    }

    // =========================================================================
    // WHERE clause - NOT IN
    // =========================================================================

    public function testNotIn(): void
    {
        $result = $this->query('SELECT name FROM users WHERE id NOT IN (1, 2);');
        $expected = <<<'CSV'
name
Charlie
CSV;
        $this->assertSame($expected, $result);
    }

    // =========================================================================
    // Column aliases
    // =========================================================================

    public function testColumnAlias(): void
    {
        $result = $this->query('SELECT name AS user_name, email AS contact FROM users LIMIT 2;');
        $expected = <<<'CSV'
user_name,contact
Alice,alice@example.com
Bob,bob@example.com
CSV;
        $this->assertSame($expected, $result);
    }

    public function testExpressionAlias(): void
    {
        // Expression aliases work in SELECT
        $result = $this->query('SELECT name, price * stock AS inventory_value FROM products LIMIT 2;');
        $expected = <<<'CSV'
name,inventory_value
Widget,999
Gizmo,1249.5
CSV;
        $this->assertSame($expected, $result);
    }

    // =========================================================================
    // Complex WHERE conditions
    // =========================================================================

    public function testComplexAndOr(): void
    {
        // (gadgets AND price > 10) OR (tools AND price < 10)
        $result = $this->query("SELECT name FROM products WHERE (category = 'gadgets' AND price > 10) OR (category = 'tools' AND price < 10);");
        $expected = <<<'CSV'
name
Gizmo
Doohickey
CSV;
        $this->assertSame($expected, $result);
    }

    public function testMultipleAnds(): void
    {
        $result = $this->query("SELECT name FROM products WHERE category = 'gadgets' AND price > 5 AND stock < 100;");
        $expected = <<<'CSV'
name
Gizmo
CSV;
        $this->assertSame($expected, $result);
    }

    // =========================================================================
    // Aggregate with expressions
    // =========================================================================

    public function testSumExpression(): void
    {
        $result = $this->query('SELECT SUM(price * stock) AS total_inventory FROM products;');
        $expected = <<<'CSV'
total_inventory
4370.75
CSV;
        $this->assertSame($expected, $result);
    }

    // =========================================================================
    // Edge cases
    // =========================================================================

    public function testEmptyResult(): void
    {
        $result = $this->query("SELECT name FROM users WHERE name = 'NonExistent';");
        $expected = '';
        $this->assertSame($expected, $result);
    }

    public function testLimitZero(): void
    {
        $result = $this->query('SELECT name FROM users LIMIT 0;');
        $expected = '';
        $this->assertSame($expected, $result);
    }

    public function testOffsetBeyondData(): void
    {
        $result = $this->query('SELECT name FROM users LIMIT 10 OFFSET 100;');
        $expected = '';
        $this->assertSame($expected, $result);
    }

    // =========================================================================
    // More SQL functions
    // =========================================================================

    public function testReplaceFunction(): void
    {
        $result = $this->query("SELECT REPLACE(email, '@example.com', '@test.com') AS new_email FROM users LIMIT 1;");
        $expected = <<<'CSV'
new_email
alice@test.com
CSV;
        $this->assertSame($expected, $result);
    }

    public function testNullIfFunction(): void
    {
        $result = $this->query("SELECT NULLIF(1, 1) AS same, NULLIF(1, 2) AS different;");
        $expected = <<<'CSV'
same,different
,1
CSV;
        $this->assertSame($expected, $result);
    }

    // =========================================================================
    // GROUP BY
    // =========================================================================

    public function testGroupByBasic(): void
    {
        $result = $this->query('SELECT category, COUNT(*) AS cnt FROM products GROUP BY category ORDER BY category;');
        $expected = <<<'CSV'
category,cnt
gadgets,2
tools,2
CSV;
        $this->assertSame($expected, $result);
    }

    public function testGroupByWithSum(): void
    {
        $result = $this->query('SELECT category, SUM(price) AS total FROM products GROUP BY category ORDER BY category;');
        $expected = <<<'CSV'
category,total
gadgets,34.98
tools,19.98
CSV;
        $this->assertSame($expected, $result);
    }

    public function testGroupByMultipleAggregates(): void
    {
        $result = $this->query('SELECT category, COUNT(*) AS cnt, MIN(price) AS min_price, MAX(price) AS max_price FROM products GROUP BY category ORDER BY category;');
        $expected = <<<'CSV'
category,cnt,min_price,max_price
gadgets,2,9.99,24.99
tools,2,4.99,14.99
CSV;
        $this->assertSame($expected, $result);
    }

    public function testGroupByOrderByAggregate(): void
    {
        $result = $this->query('SELECT category, SUM(price) AS total FROM products GROUP BY category ORDER BY total DESC;');
        $expected = <<<'CSV'
category,total
gadgets,34.98
tools,19.98
CSV;
        $this->assertSame($expected, $result);
    }

    public function testGroupByWithLimit(): void
    {
        $result = $this->query('SELECT category, COUNT(*) AS cnt FROM products GROUP BY category ORDER BY category LIMIT 1;');
        $expected = <<<'CSV'
category,cnt
gadgets,2
CSV;
        $this->assertSame($expected, $result);
    }

    public function testGroupByWithWhere(): void
    {
        $result = $this->query('SELECT category, COUNT(*) AS cnt FROM products WHERE price > 10 GROUP BY category ORDER BY category;');
        $expected = <<<'CSV'
category,cnt
gadgets,1
tools,1
CSV;
        $this->assertSame($expected, $result);
    }

    // =========================================================================
    // HAVING
    // =========================================================================

    public function testHavingBasic(): void
    {
        $result = $this->query('SELECT role, COUNT(*) AS cnt FROM users GROUP BY role HAVING cnt > 1;');
        $expected = <<<'CSV'
role,cnt
user,2
CSV;
        $this->assertSame($expected, $result);
    }

    public function testHavingWithSum(): void
    {
        $result = $this->query('SELECT category, SUM(price) AS total FROM products GROUP BY category HAVING total > 30;');
        $expected = <<<'CSV'
category,total
gadgets,34.98
CSV;
        $this->assertSame($expected, $result);
    }

    public function testHavingWithOrderBy(): void
    {
        $result = $this->query('SELECT category, SUM(price) AS total FROM products GROUP BY category HAVING total > 10 ORDER BY total DESC;');
        $expected = <<<'CSV'
category,total
gadgets,34.98
tools,19.98
CSV;
        $this->assertSame($expected, $result);
    }

    public function testGroupByWhereHaving(): void
    {
        // Filter products with price < 20, group, then filter groups with count >= 2
        $result = $this->query('SELECT category, COUNT(*) AS cnt FROM products WHERE price < 20 GROUP BY category HAVING cnt >= 2;');
        $expected = <<<'CSV'
category,cnt
tools,2
CSV;
        $this->assertSame($expected, $result);
    }

    // =========================================================================
    // CASE WHEN expressions
    // =========================================================================

    public function testCaseWhenSearched(): void
    {
        $result = $this->query("SELECT name, CASE WHEN price > 20 THEN 'expensive' WHEN price > 10 THEN 'moderate' ELSE 'cheap' END AS price_tier FROM products;");
        $expected = <<<'CSV'
name,price_tier
Widget,cheap
Gizmo,expensive
Thingamajig,moderate
Doohickey,cheap
CSV;
        $this->assertSame($expected, $result);
    }

    public function testCaseWhenSimple(): void
    {
        $result = $this->query("SELECT name, CASE role WHEN 'admin' THEN 'Administrator' WHEN 'user' THEN 'Regular User' ELSE 'Unknown' END AS role_name FROM users;");
        $expected = <<<'CSV'
name,role_name
Alice,Administrator
Bob,Regular User
Charlie,Regular User
CSV;
        $this->assertSame($expected, $result);
    }

    public function testCaseWhenNoElse(): void
    {
        // No ELSE means NULL when no match
        $result = $this->query("SELECT name, CASE WHEN active = 1 THEN 'Active' END AS status FROM users;");
        $expected = <<<'CSV'
name,status
Alice,Active
Bob,Active
Charlie,
CSV;
        $this->assertSame($expected, $result);
    }

    public function testCaseExpressionInWhere(): void
    {
        // CASE expression in WHERE predicate
        $result = $this->query("SELECT name FROM products WHERE CASE WHEN price < 10 THEN 'cheap' ELSE 'expensive' END = 'cheap';");
        $expected = <<<'CSV'
name
Widget
Doohickey
CSV;
        $this->assertSame($expected, $result);
    }

    // =========================================================================
    // String concatenation operator (||)
    // =========================================================================

    public function testStringConcatenation(): void
    {
        $result = $this->query("SELECT name || ' - ' || role AS info FROM users LIMIT 2;");
        $expected = <<<'CSV'
info
Alice - admin
Bob - user
CSV;
        $this->assertSame($expected, $result);
    }

    // =========================================================================
    // Modulo operator (%)
    // =========================================================================

    public function testModuloOperator(): void
    {
        $result = $this->query('SELECT id, id % 2 AS is_odd FROM users;');
        $expected = <<<'CSV'
id,is_odd
1,1
2,0
3,1
CSV;
        $this->assertSame($expected, $result);
    }

    // =========================================================================
    // COUNT(DISTINCT)
    // =========================================================================

    public function testCountDistinct(): void
    {
        $result = $this->query('SELECT COUNT(DISTINCT role) AS unique_roles FROM users;');
        $expected = <<<'CSV'
unique_roles
2
CSV;
        $this->assertSame($expected, $result);
    }

    public function testCountDistinctWithGroupBy(): void
    {
        $result = $this->query('SELECT active, COUNT(DISTINCT role) AS unique_roles FROM users GROUP BY active ORDER BY active;');
        $expected = <<<'CSV'
active,unique_roles
0,1
1,2
CSV;
        $this->assertSame($expected, $result);
    }

    // =========================================================================
    // INTERSECT / EXCEPT
    // =========================================================================

    public function testIntersect(): void
    {
        $result = $this->query('SELECT id FROM users INTERSECT SELECT user_id FROM orders;');
        $expected = <<<'CSV'
id
1
2
CSV;
        $this->assertSame($expected, $result);
    }

    public function testExcept(): void
    {
        $result = $this->query('SELECT id FROM users EXCEPT SELECT user_id FROM orders;');
        $expected = <<<'CSV'
id
3
CSV;
        $this->assertSame($expected, $result);
    }

    // =========================================================================
    // INSTR function
    // =========================================================================

    public function testInstrFunction(): void
    {
        $result = $this->query("SELECT name, INSTR(email, '@') AS at_pos FROM users LIMIT 1;");
        $expected = <<<'CSV'
name,at_pos
Alice,6
CSV;
        $this->assertSame($expected, $result);
    }

    public function testInstrNotFound(): void
    {
        $result = $this->query("SELECT INSTR('hello', 'x') AS pos;");
        $expected = <<<'CSV'
pos
0
CSV;
        $this->assertSame($expected, $result);
    }

    // =========================================================================
    // Window Functions (SQL:2003)
    // =========================================================================

    public function testRowNumber(): void
    {
        // ROW_NUMBER() OVER (ORDER BY ...) - assigns row numbers by order
        $result = $this->query('SELECT name, ROW_NUMBER() OVER (ORDER BY id) AS rn FROM users;');
        $expected = <<<'CSV'
name,rn
Alice,1
Bob,2
Charlie,3
CSV;
        $this->assertSame($expected, $result);
    }

    public function testRankWithPartition(): void
    {
        // RANK() OVER (PARTITION BY ... ORDER BY ...) - assigns rank within partitions
        $result = $this->query('SELECT name, category, RANK() OVER (PARTITION BY category ORDER BY price DESC) AS rank FROM products;');
        // Expected: rank within each category by price DESC, output order is original table order
        $expected = <<<'CSV'
name,category,rank
Widget,gadgets,2
Gizmo,gadgets,1
Thingamajig,tools,1
Doohickey,tools,2
CSV;
        $this->assertSame($expected, $result);
    }

    public function testDenseRank(): void
    {
        // DENSE_RANK() - like RANK but no gaps in rank values
        $result = $this->query('SELECT name, DENSE_RANK() OVER (ORDER BY role) AS dr FROM users;');
        // Alice is admin (rank 1), Bob and Charlie are user (rank 2, no gap)
        $expected = <<<'CSV'
name,dr
Alice,1
Bob,2
Charlie,2
CSV;
        $this->assertSame($expected, $result);
    }

    // =========================================================================
    // Common Table Expressions (CTEs) - SQL:2003
    // =========================================================================

    public function testCteBasic(): void
    {
        // Basic CTE: WITH cte AS (SELECT ...) SELECT * FROM cte
        $result = $this->query('WITH active_users AS (SELECT * FROM users WHERE active = 1) SELECT name FROM active_users;');
        $expected = <<<'CSV'
name
Alice
Bob
CSV;
        $this->assertSame($expected, $result);
    }

    public function testCteWithJoin(): void
    {
        // CTE used in a JOIN
        $result = $this->query('WITH user_orders AS (SELECT user_id, SUM(total) AS order_total FROM orders GROUP BY user_id) SELECT u.name, uo.order_total FROM users u JOIN user_orders uo ON u.id = uo.user_id ORDER BY u.name;');
        $expected = <<<'CSV'
name,order_total
Alice,34.97
Bob,74.97
CSV;
        $this->assertSame($expected, $result);
    }

    public function testCteMultiple(): void
    {
        // Multiple CTEs
        $result = $this->query('WITH admins AS (SELECT * FROM users WHERE role = \'admin\'), regular AS (SELECT * FROM users WHERE role = \'user\') SELECT name FROM admins UNION SELECT name FROM regular ORDER BY name;');
        $expected = <<<'CSV'
name
Alice
Bob
Charlie
CSV;
        $this->assertSame($expected, $result);
    }

    public function testCteChained(): void
    {
        // CTEs referencing earlier CTEs
        $result = $this->query('WITH active AS (SELECT * FROM users WHERE active = 1), active_admins AS (SELECT * FROM active WHERE role = \'admin\') SELECT name FROM active_admins;');
        $expected = <<<'CSV'
name
Alice
CSV;
        $this->assertSame($expected, $result);
    }

    public function testCteRecursive(): void
    {
        // Recursive CTE: generate numbers 1-5
        $result = $this->query('WITH RECURSIVE nums AS (SELECT 1 AS n UNION ALL SELECT n + 1 FROM nums WHERE n < 5) SELECT n FROM nums;');
        $expected = <<<'CSV'
n
1
2
3
4
5
CSV;
        $this->assertSame($expected, $result);
    }

    public function testCteColumnList(): void
    {
        // Declared column list renames the CTE's output columns
        $result = $this->query('WITH c(x) AS (SELECT 1 AS a) SELECT x FROM c;');
        $expected = <<<'CSV'
x
1
CSV;
        $this->assertSame($expected, $result);
    }

    public function testCteColumnListMultipleColumns(): void
    {
        $result = $this->query('WITH c(x, y) AS (SELECT 1 AS a, 2 AS b) SELECT x, y FROM c;');
        $expected = <<<'CSV'
x,y
1,2
CSV;
        $this->assertSame($expected, $result);
    }

    public function testCteColumnListCountMismatchThrows(): void
    {
        // Two names declared for a one-column query - fail fast, don't guess
        $result = $this->query('WITH c(x, y) AS (SELECT 1 AS a) SELECT x FROM c;');
        $this->assertStringContainsString('column count mismatch', strtolower($result));
    }

    public function testCteRecursiveWithColumnList(): void
    {
        // The canonical form from the SQLite/PostgreSQL manuals: the declared
        // name `n` must be in scope for the recursive term's self-reference,
        // even though the anchor (SELECT 1) produces no column named `n`.
        $result = $this->query('WITH RECURSIVE c(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM c WHERE n < 5) SELECT n FROM c;');
        $expected = <<<'CSV'
n
1
2
3
4
5
CSV;
        $this->assertSame($expected, $result);
    }

    public function testCteRecursiveWithColumnListMultipleColumns(): void
    {
        // Fibonacci via a two-column recursive CTE with a declared column list
        $result = $this->query('WITH RECURSIVE f(a, b) AS (SELECT 0, 1 UNION ALL SELECT b, a + b FROM f WHERE b < 50) SELECT a FROM f;');
        $expected = <<<'CSV'
a
0
1
1
2
3
5
8
13
21
34
CSV;
        $this->assertSame($expected, $result);
    }

    // =========================================================================
    // Niladic Functions (SQL standard functions without parentheses)
    // =========================================================================

    public function testCurrentDate(): void
    {
        // CURRENT_DATE returns date in YYYY-MM-DD format
        $result = $this->query('SELECT CURRENT_DATE AS dt;');
        $lines = explode("\n", $result);
        $this->assertSame('dt', $lines[0]);
        $this->assertTrue(preg_match('/^\d{4}-\d{2}-\d{2}$/', $lines[1]) === 1);
    }

    public function testCurrentTime(): void
    {
        // CURRENT_TIME returns time in HH:MM:SS format
        $result = $this->query('SELECT CURRENT_TIME AS tm;');
        $lines = explode("\n", $result);
        $this->assertSame('tm', $lines[0]);
        $this->assertTrue(preg_match('/^\d{2}:\d{2}:\d{2}$/', $lines[1]) === 1);
    }

    public function testCurrentTimestamp(): void
    {
        // CURRENT_TIMESTAMP returns datetime in YYYY-MM-DD HH:MM:SS format
        $result = $this->query('SELECT CURRENT_TIMESTAMP AS ts;');
        $lines = explode("\n", $result);
        $this->assertSame('ts', $lines[0]);
        $this->assertTrue(preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $lines[1]) === 1);
    }

    public function testNiladicInExpression(): void
    {
        // Niladic functions can be used in expressions
        $result = $this->query("SELECT SUBSTR(CURRENT_DATE, 1, 4) AS year;");
        $lines = explode("\n", $result);
        $this->assertSame('year', $lines[0]);
        $this->assertSame(date('Y'), $lines[1]);
    }

    // =========================================================================
    // NULL semantics / three-valued logic
    //
    // Every expectation below was cross-checked against sqlite3 with the same
    // fixture data. A NULL operand makes a predicate UNKNOWN, which is neither
    // TRUE nor FALSE: rows are filtered out by both the predicate and its
    // negation, and the CSV rendering of UNKNOWN is an empty field.
    // =========================================================================

    public function testComparisonWithNullIsUnknown(): void
    {
        // NULL = NULL is UNKNOWN, not TRUE. IS NULL is the way to test for NULL.
        $result = $this->query('SELECT NULL = NULL AS a, NULL != NULL AS b, NULL <> 5 AS c, NULL < 5 AS d;');
        $expected = <<<'CSV'
a,b,c,d
,,,
CSV;
        $this->assertSame($expected, $result);
    }

    public function testEqualityBetweenTwoNullColumnsMatchesNoRows(): void
    {
        // contacts 2 and 4 have both email and notes NULL. NULL = NULL is
        // UNKNOWN, so they must NOT be returned.
        $result = $this->query('SELECT id FROM contacts WHERE email = notes ORDER BY id;');
        $this->assertSame('', $result);
    }

    public function testAndOrThreeValuedLogic(): void
    {
        // TRUE AND UNKNOWN = UNKNOWN, FALSE AND UNKNOWN = FALSE
        // TRUE OR UNKNOWN = TRUE,     FALSE OR UNKNOWN = UNKNOWN
        //
        // The CSV writer renders both FALSE and NULL as an empty field, so each
        // truth value is mapped to a letter to keep them distinguishable.
        $truth = fn(string $e) => "CASE WHEN ($e) IS NULL THEN 'U' WHEN ($e) THEN 'T' ELSE 'F' END";
        $result = $this->query(
            'SELECT ' . $truth('1 AND NULL') . ' AS a, ' . $truth('0 AND NULL') . ' AS b, '
            . $truth('1 OR NULL') . ' AS c, ' . $truth('0 OR NULL') . ' AS d, '
            . $truth('NULL AND NULL') . ' AS e, ' . $truth('NULL OR NULL') . ' AS f;'
        );
        $expected = <<<'CSV'
a,b,c,d,e,f
U,F,T,U,U,U
CSV;
        $this->assertSame($expected, $result);
    }

    public function testNotOfUnknownStaysUnknown(): void
    {
        $result = $this->query('SELECT NOT NULL AS a, NOT (1 AND NULL) AS b, (NULL AND 1) IS NULL AS c;');
        $expected = <<<'CSV'
a,b,c
,,1
CSV;
        $this->assertSame($expected, $result);
    }

    public function testNotLikeExcludesNullRows(): void
    {
        // contacts 5 has name NULL. NULL NOT LIKE 'A%' is UNKNOWN, so row 5
        // belongs to neither LIKE nor NOT LIKE.
        $result = $this->query("SELECT id FROM contacts WHERE name NOT LIKE 'A%' ORDER BY id;");
        $expected = <<<'CSV'
id
2
3
4
CSV;
        $this->assertSame($expected, $result);

        // Same for a column that is NULL on several rows (2 and 4)
        $result = $this->query("SELECT id FROM contacts WHERE notes NOT LIKE '%email%' ORDER BY id;");
        $expected = <<<'CSV'
id
1
5
CSV;
        $this->assertSame($expected, $result);
    }

    public function testLikeWithNullOperandIsUnknown(): void
    {
        $result = $this->query("SELECT NULL LIKE 'a' AS a, 'a' LIKE NULL AS b, NULL LIKE NULL AS c;");
        $expected = <<<'CSV'
a,b,c
,,
CSV;
        $this->assertSame($expected, $result);
    }

    public function testBetweenWithNullBound(): void
    {
        // A NULL bound makes BETWEEN UNKNOWN - it must not raise a TypeError
        $result = $this->query('SELECT id FROM contacts WHERE id BETWEEN NULL AND 3 ORDER BY id;');
        $this->assertSame('', $result);

        // ... and in expression position it renders as NULL, not 0
        $result = $this->query('SELECT id, id BETWEEN 1 AND email AS r FROM contacts ORDER BY id;');
        $expected = <<<'CSV'
id,r
1,1
2,
3,1
4,
5,1
CSV;
        $this->assertSame($expected, $result);
    }

    public function testIsNullAcceptsArbitraryExpressions(): void
    {
        // IS NULL is not restricted to bare columns
        $result = $this->query('SELECT id FROM contacts WHERE COALESCE(email, phone) IS NULL ORDER BY id;');
        $expected = <<<'CSV'
id
4
CSV;
        $this->assertSame($expected, $result);

        $result = $this->query('SELECT id FROM contacts WHERE UPPER(email) IS NULL ORDER BY id;');
        $expected = <<<'CSV'
id
2
4
CSV;
        $this->assertSame($expected, $result);

        $result = $this->query("SELECT id FROM contacts WHERE (email || phone) IS NOT NULL ORDER BY id;");
        $expected = <<<'CSV'
id
1
5
CSV;
        $this->assertSame($expected, $result);
    }

    // =========================================================================
    // Scalar functions: NULL propagation and index semantics
    // =========================================================================

    public function testSubstrIndexSemantics(): void
    {
        // Positions are 1-based; positions outside the string contribute
        // nothing rather than shifting the window. A negative start counts back
        // from the end, a negative length selects the characters preceding it.
        $result = $this->query(
            "SELECT SUBSTR('abcdef',2) AS a, SUBSTR('abcdef',2,3) AS b, SUBSTR('abcdef',0) AS c,"
            . " SUBSTR('abcdef',0,2) AS d, SUBSTR('abcdef',-2) AS e, SUBSTR('abcdef',-2,1) AS f,"
            . " SUBSTR('abcdef',2,-1) AS g, SUBSTR('abcdef',10) AS h, SUBSTR('abcdef',1,100) AS i;"
        );
        $expected = <<<'CSV'
a,b,c,d,e,f,g,h,i
bcdef,bcd,abcdef,a,ef,e,a,,abcdef
CSV;
        $this->assertSame($expected, $result);
    }

    public function testSubstrPropagatesNullArguments(): void
    {
        $result = $this->query("SELECT SUBSTR(NULL,1,2) AS a, SUBSTR('abc',NULL,2) AS b, SUBSTR('abc',1,NULL) AS c;");
        $expected = <<<'CSV'
a,b,c
,,
CSV;
        $this->assertSame($expected, $result);
    }

    public function testTrimWithCharacterSet(): void
    {
        // The second argument is the set of characters to strip - it must not
        // be silently ignored
        $result = $this->query("SELECT TRIM('xxaxx','x') AS a, LTRIM('xxaxx','x') AS b, RTRIM('xxaxx','x') AS c,"
            . " TRIM('  a  ') AS d, TRIM('abc','') AS e, TRIM('abc',NULL) AS f;");
        $expected = <<<'CSV'
a,b,c,d,e,f
a,axx,xxa,a,abc,
CSV;
        $this->assertSame($expected, $result);
    }

    public function testRoundPropagatesNullPrecision(): void
    {
        $result = $this->query('SELECT ROUND(3.456,2) AS a, ROUND(3.456) AS b, ROUND(3.456,NULL) AS c, ROUND(NULL) AS d;');
        $expected = <<<'CSV'
a,b,c,d
3.46,3,,
CSV;
        $this->assertSame($expected, $result);
    }
    // =========================================================================
    // Subqueries and CTEs - differential-tested against sqlite3
    //
    // Each of these froze a *silently wrong answer* (or a crash on valid SQL)
    // found by comparing VirtualDatabase output to sqlite3 on the same data.
    // =========================================================================

    public function testCorrelatedScalarSubqueryOnRightOfComparison(): void
    {
        // Regression: the comparison-pushdown path treated any subquery as a
        // constant and evaluated it once, so this compared every row against
        // the GLOBAL max instead of the max for that row's user.
        $result = $this->query(
            'SELECT o.id FROM orders o'
            . ' WHERE o.total >= (SELECT MAX(o2.total) FROM orders o2 WHERE o2.user_id = o.user_id)'
            . ' ORDER BY o.id;'
        );
        $expected = <<<'CSV'
id
1
3
CSV;
        $this->assertSame($expected, $result);
    }

    public function testCorrelatedScalarSubqueryWithEqualityOnRight(): void
    {
        $result = $this->query(
            'SELECT p.id FROM products p'
            . ' WHERE p.stock = (SELECT MAX(stock) FROM products WHERE category = p.category)'
            . ' ORDER BY p.id;'
        );
        $expected = <<<'CSV'
id
1
4
CSV;
        $this->assertSame($expected, $result);
    }

    public function testCorrelatedInSubqueryIsEvaluatedPerRow(): void
    {
        // Regression: IN-subqueries were materialised once, ignoring the
        // correlation. Also exercises NOT IN three-valued logic with NULLs:
        // only id 1 has an empty probe set, every later row compares against
        // a set containing NULL and is therefore UNKNOWN.
        $result = $this->query(
            'SELECT c.id FROM contacts c'
            . ' WHERE c.email NOT IN (SELECT email FROM contacts WHERE id < c.id)'
            . ' ORDER BY c.id;'
        );
        $expected = <<<'CSV'
id
1
CSV;
        $this->assertSame($expected, $result);
    }

    public function testInSubqueryProjectsQualifiedColumn(): void
    {
        // Regression: "SELECT o.user_id FROM orders o" inside IN() threw
        // "Column 'o.user_id' does not exist in table" - the single-table
        // path never wraps the source in an AliasTable.
        $result = $this->query(
            'SELECT id FROM users WHERE id IN (SELECT o.user_id FROM orders o WHERE o.total > 20) ORDER BY id;'
        );
        $expected = <<<'CSV'
id
2
CSV;
        $this->assertSame($expected, $result);
    }

    public function testInSubqueryWithAggregateProjection(): void
    {
        // Regression: a computed projection cannot be expressed as a column
        // selection, so the set silently carried the source table's first
        // column - and IN() matched nothing at all.
        $result = $this->query('SELECT id FROM users WHERE id IN (SELECT MAX(user_id) FROM orders) ORDER BY id;');
        $expected = <<<'CSV'
id
2
CSV;
        $this->assertSame($expected, $result);
    }

    public function testNotInSubqueryWithArithmeticProjection(): void
    {
        $result = $this->query('SELECT id FROM users WHERE id NOT IN (SELECT id * 2 FROM products) ORDER BY id;');
        $expected = <<<'CSV'
id
1
3
CSV;
        $this->assertSame($expected, $result);
    }

    public function testRecursiveCteWithMultipleColumns(): void
    {
        // Regression: rows are name-keyed, so the anchor "SELECT 1, 0, 1"
        // collapsed its two "1" columns and the arity check rejected a
        // perfectly valid recursive CTE.
        $result = $this->query(
            'WITH RECURSIVE f(n, a, b) AS ('
            . 'SELECT 1, 0, 1 UNION ALL SELECT n+1, b, a+b FROM f WHERE n < 8'
            . ') SELECT n, a FROM f ORDER BY n;'
        );
        $expected = <<<'CSV'
n,a
1,0
2,1
3,1
4,2
5,3
6,5
7,8
8,13
CSV;
        $this->assertSame($expected, $result);
    }

    public function testRecursiveCteWithTwoColumnsAccumulates(): void
    {
        $result = $this->query(
            'WITH RECURSIVE c(n, s) AS (SELECT 1, 1 UNION ALL SELECT n+1, s+n+1 FROM c WHERE n < 5)'
            . ' SELECT * FROM c ORDER BY n;'
        );
        $expected = <<<'CSV'
n,s
1,1
2,3
3,6
4,10
5,15
CSV;
        $this->assertSame($expected, $result);
    }

    public function testCorrelatedScalarSubqueryHonoursJoin(): void
    {
        // Regression: the correlated-subquery fast path filtered a single
        // table by hand and dropped the JOIN entirely, returning 0 for user 1.
        $result = $this->query(
            'SELECT u.id, (SELECT COUNT(*) FROM orders o JOIN products p ON p.id = o.product_id'
            . " WHERE o.user_id = u.id AND p.category = 'tools') AS c"
            . ' FROM users u ORDER BY u.id;'
        );
        $expected = <<<'CSV'
id,c
1,1
2,0
3,0
CSV;
        $this->assertSame($expected, $result);
    }

    public function testCorrelatedScalarSubqueryHonoursOrderByAndLimit(): void
    {
        // Regression: ORDER BY / LIMIT were dropped, so the scalar subquery
        // saw every matching row and threw "returned more than one row".
        $result = $this->query(
            'SELECT u.id, (SELECT o.total FROM orders o WHERE o.user_id = u.id'
            . ' ORDER BY total ASC LIMIT 1) AS t FROM users u ORDER BY u.id;'
        );
        // User 1 has orders 19.98 and 14.99 in that storage order - picking
        // 14.99 proves the ORDER BY really ran before the LIMIT.
        $expected = <<<'CSV'
id,t
1,14.99
2,74.97
3,
CSV;
        $this->assertSame($expected, $result);
    }

    public function testCorrelatedExistsWithJoinInSubquery(): void
    {
        // Regression: the joined alias "p" was mistaken for an outer
        // reference and the query died on "Unknown parameter: :outer_p_category".
        $result = $this->query(
            'SELECT u.id FROM users u WHERE EXISTS ('
            . 'SELECT 1 FROM orders o JOIN products p ON p.id = o.product_id'
            . " WHERE o.user_id = u.id AND p.category = 'tools') ORDER BY u.id;"
        );
        $expected = <<<'CSV'
id
1
CSV;
        $this->assertSame($expected, $result);
    }

    public function testNestedCorrelatedExistsReachingTwoLevelsOut(): void
    {
        $result = $this->query(
            'SELECT u.id FROM users u WHERE EXISTS ('
            . 'SELECT 1 FROM orders o WHERE o.user_id = u.id AND EXISTS ('
            . 'SELECT 1 FROM products p WHERE p.id = o.product_id AND p.stock > u.id))'
            . ' ORDER BY u.id;'
        );
        $expected = <<<'CSV'
id
1
2
CSV;
        $this->assertSame($expected, $result);
    }

    public function testExistsProjectsZeroNotEmptyWhenFalse(): void
    {
        // EXISTS is 1 or 0 in a result set - a PHP false rendered as blank.
        $result = $this->query(
            'SELECT u.id, EXISTS (SELECT 1 FROM orders o WHERE o.user_id = u.id) AS e FROM users u ORDER BY u.id;'
        );
        $expected = <<<'CSV'
id,e
1,1
2,1
3,0
CSV;
        $this->assertSame($expected, $result);
    }

    // =========================================================================
    // Aggregates and window functions
    //
    // Every expectation below was verified against sqlite3 3.45 with the same
    // fixture data. Each one covers a query shape that previously produced a
    // wrong answer or a crash.
    // =========================================================================

    public function testGroupByWithoutAggregateInSelectListStillGroups(): void
    {
        // Regression: GROUP BY was only honoured when the SELECT list contained
        // an aggregate; otherwise it was silently ignored and every source row
        // was returned.
        $result = $this->query('SELECT role FROM users GROUP BY role ORDER BY role;');
        $expected = <<<'CSV'
role
admin
user
CSV;
        $this->assertSame($expected, $result);
    }

    public function testGroupByWithHavingButNoAggregateInSelectList(): void
    {
        $result = $this->query(
            'SELECT category FROM products GROUP BY category HAVING MIN(price) < 10 ORDER BY category;'
        );
        $expected = <<<'CSV'
category
gadgets
tools
CSV;
        $this->assertSame($expected, $result);
    }

    public function testHavingCountStar(): void
    {
        // Regression: COUNT(*) in HAVING died with
        // "Wildcard (*) not allowed in expression context".
        $result = $this->query(
            'SELECT category, COUNT(*) AS c FROM products'
            . ' GROUP BY category HAVING COUNT(*) > 1 ORDER BY category;'
        );
        $expected = <<<'CSV'
category,c
gadgets,2
tools,2
CSV;
        $this->assertSame($expected, $result);
    }

    public function testHavingAggregateOverUnprojectedColumn(): void
    {
        // Regression: "Column not found: stock" - HAVING was evaluated against
        // the projected result row instead of the group.
        $result = $this->query(
            'SELECT category, SUM(stock) AS s FROM products'
            . ' GROUP BY category HAVING SUM(stock) > 200 ORDER BY category;'
        );
        $expected = <<<'CSV'
category,s
tools,275
CSV;
        $this->assertSame($expected, $result);
    }

    public function testHavingOnGroupColumnNotInSelectList(): void
    {
        $result = $this->query(
            "SELECT COUNT(*) AS c FROM products GROUP BY category HAVING category = 'tools';"
        );
        $expected = <<<'CSV'
c
2
CSV;
        $this->assertSame($expected, $result);
    }

    public function testHavingWithoutGroupByFiltersTheImplicitGroup(): void
    {
        // Regression: HAVING was ignored entirely when there was no GROUP BY,
        // so a group that fails the condition was still returned.
        $this->assertSame('c' . "\n" . '3', $this->query(
            'SELECT COUNT(*) AS c FROM users HAVING COUNT(*) > 1;'
        ));
        // An empty result set prints nothing at all, not even a header row.
        $this->assertSame('', $this->query(
            'SELECT COUNT(*) AS c FROM users HAVING COUNT(*) > 5;'
        ));
    }

    public function testHavingCombinedWithNonAggregateCondition(): void
    {
        $result = $this->query(
            'SELECT role, COUNT(*) AS c FROM users GROUP BY role'
            . " HAVING COUNT(*) >= 1 AND role <> 'admin' ORDER BY role;"
        );
        $expected = <<<'CSV'
role,c
user,2
CSV;
        $this->assertSame($expected, $result);
    }

    public function testAggregateInsideArithmeticExpression(): void
    {
        // Regression: "Unknown function: SUM" - aggregates were only recognised
        // as a whole SELECT column, never nested in an expression.
        $this->assertSame('c' . "\n" . '4', $this->query('SELECT COUNT(*) + 1 AS c FROM users;'));
        $this->assertSame('c' . "\n" . '109.92', $this->query('SELECT SUM(price) * 2 AS c FROM products;'));
        $this->assertSame('c' . "\n" . '20', $this->query('SELECT MAX(price) - MIN(price) AS c FROM products;'));
        $this->assertSame('a' . "\n" . '13.74', $this->query('SELECT ROUND(AVG(price), 2) AS a FROM products;'));
    }

    public function testAggregateInsideCaseExpression(): void
    {
        $result = $this->query(
            "SELECT category, CASE WHEN COUNT(*) > 1 THEN 'many' ELSE 'one' END AS k"
            . ' FROM products GROUP BY category ORDER BY category;'
        );
        $expected = <<<'CSV'
category,k
gadgets,many
tools,many
CSV;
        $this->assertSame($expected, $result);
    }

    public function testOrderByAggregateNotInSelectList(): void
    {
        $result = $this->query(
            'SELECT category, COUNT(*) AS c FROM products'
            . ' GROUP BY category ORDER BY COUNT(*) DESC, category;'
        );
        $expected = <<<'CSV'
category,c
gadgets,2
tools,2
CSV;
        $this->assertSame($expected, $result);

        $result = $this->query(
            'SELECT category, COUNT(*) AS n FROM products GROUP BY category ORDER BY MIN(price);'
        );
        $expected = <<<'CSV'
category,n
tools,2
gadgets,2
CSV;
        $this->assertSame($expected, $result);
    }

    public function testGroupByOrdinalRefersToSelectListPosition(): void
    {
        // Regression: GROUP BY 1 was evaluated as the constant 1, collapsing
        // every row into a single group and returning one arbitrary row.
        $result = $this->query('SELECT role, COUNT(*) AS c FROM users GROUP BY 1 ORDER BY 1;');
        $expected = <<<'CSV'
role,c
admin,1
user,2
CSV;
        $this->assertSame($expected, $result);

        $result = $this->query(
            'SELECT role, active, COUNT(*) AS c FROM users GROUP BY 1, 2 ORDER BY 1, 2;'
        );
        $expected = <<<'CSV'
role,active,c
admin,1,1
user,0,1
user,1,1
CSV;
        $this->assertSame($expected, $result);
    }

    public function testAggregateResultColumnsFollowSelectListOrder(): void
    {
        $result = $this->query(
            'SELECT COUNT(*) AS c, category FROM products GROUP BY category ORDER BY category;'
        );
        $expected = <<<'CSV'
c,category
2,gadgets
2,tools
CSV;
        $this->assertSame($expected, $result);
    }

    public function testWildcardWithGroupByExpandsRepresentativeRow(): void
    {
        $result = $this->query('SELECT * FROM products GROUP BY category;');
        $expected = <<<'CSV'
id,name,price,category,stock
1,Widget,9.99,gadgets,100
3,Thingamajig,14.99,tools,75
CSV;
        $this->assertSame($expected, $result);
    }

    public function testGroupByInInSubqueryIsNotDropped(): void
    {
        // Regression: executeSelectAsTable() ignored GROUP BY/HAVING, so the
        // IN-subquery matched every row of the source table.
        $result = $this->query(
            'SELECT id FROM users WHERE role IN'
            . ' (SELECT role FROM users GROUP BY role HAVING COUNT(*) > 1) ORDER BY id;'
        );
        $expected = <<<'CSV'
id
2
3
CSV;
        $this->assertSame($expected, $result);
    }

    public function testGroupByInExistsSubqueryIsNotDropped(): void
    {
        $this->assertSame('', $this->query(
            'SELECT id FROM users WHERE EXISTS'
            . ' (SELECT role FROM users GROUP BY role HAVING COUNT(*) > 99);'
        ));
    }

    public function testCountVariantsWithNulls(): void
    {
        $result = $this->query(
            'SELECT COUNT(*) AS a, COUNT(email) AS b, COUNT(DISTINCT email) AS c FROM contacts;'
        );
        $expected = <<<'CSV'
a,b,c
5,3,3
CSV;
        $this->assertSame($expected, $result);
    }

    public function testAggregatesOverEmptyInput(): void
    {
        $result = $this->query(
            'SELECT COUNT(*) AS f, COUNT(price) AS e, SUM(price) AS s,'
            . ' AVG(price) AS a, MIN(price) AS mn, MAX(price) AS mx'
            . ' FROM products WHERE 1=0;'
        );
        $expected = <<<'CSV'
f,e,s,a,mn,mx
0,0,,,,
CSV;
        $this->assertSame($expected, $result);
    }

    public function testSelectDistinctAppliesToGroupedResults(): void
    {
        // Regression: SELECT DISTINCT was ignored on the grouping path, so two
        // groups that aggregate to the same row were both returned.
        $this->assertSame('c' . "\n" . '2', $this->query(
            'SELECT DISTINCT COUNT(*) AS c FROM products GROUP BY category;'
        ));
        $result = $this->query(
            'SELECT DISTINCT category, COUNT(*) AS c FROM products GROUP BY category ORDER BY category;'
        );
        $expected = <<<'CSV'
category,c
gadgets,2
tools,2
CSV;
        $this->assertSame($expected, $result);
    }

    public function testMultipleWindowFunctionsKeepDistinctValues(): void
    {
        // Regression: every window function in the SELECT list returned the
        // value of the *first* one, so DENSE_RANK reported ROW_NUMBER's result.
        $result = $this->query(
            'SELECT id, ROW_NUMBER() OVER (ORDER BY category) AS rn,'
            . ' DENSE_RANK() OVER (ORDER BY category) AS dr FROM products ORDER BY id;'
        );
        $expected = <<<'CSV'
id,rn,dr
1,1,1
2,2,1
3,3,2
4,4,2
CSV;
        $this->assertSame($expected, $result);
    }

    public function testWindowFunctionWithOuterOrderBy(): void
    {
        // Regression: SelectStatement::$orderBy items are arrays, but the window
        // path read them as objects and died on a TypeError.
        $result = $this->query(
            'SELECT id, ROW_NUMBER() OVER (ORDER BY id) AS rn FROM products ORDER BY id DESC;'
        );
        $expected = <<<'CSV'
id,rn
4,4
3,3
2,2
1,1
CSV;
        $this->assertSame($expected, $result);
    }

    public function testOrderByWindowFunctionAlias(): void
    {
        $result = $this->query(
            'SELECT id, ROW_NUMBER() OVER (ORDER BY id DESC) AS rn FROM products ORDER BY rn;'
        );
        $expected = <<<'CSV'
id,rn
4,1
3,2
2,3
1,4
CSV;
        $this->assertSame($expected, $result);
    }

    public function testWindowFunctionInsideExpression(): void
    {
        $result = $this->query(
            'SELECT id, ROW_NUMBER() OVER (ORDER BY id) * 10 AS x FROM products ORDER BY id;'
        );
        $expected = <<<'CSV'
id,x
1,10
2,20
3,30
4,40
CSV;
        $this->assertSame($expected, $result);
    }

    public function testRankTiesAndPartitions(): void
    {
        $result = $this->query(
            'SELECT id, RANK() OVER (PARTITION BY category ORDER BY price) AS r,'
            . ' DENSE_RANK() OVER (ORDER BY category) AS d FROM products ORDER BY id;'
        );
        $expected = <<<'CSV'
id,r,d
1,1,1
2,2,1
3,2,2
4,1,2
CSV;
        $this->assertSame($expected, $result);
    }

    // =========================================================================
    // JOIN semantics — every expected value below is sqlite3's answer for the
    // same query against the same fixture data.
    // =========================================================================

    /**
     * A WHERE predicate on the null-supplying side of a LEFT JOIN must be
     * applied AFTER the join. LeftJoinTable used to push it into the right
     * source table, which turned matched rows into null-extended rows instead
     * of removing them — three rows instead of one.
     */
    public function testLeftJoinWhereOnRightTableIsNotPushedDown(): void
    {
        $result = $this->query(
            'SELECT u.id AS uid, o.id AS oid FROM users u'
            . ' LEFT JOIN orders o ON u.id = o.user_id WHERE o.total > 20 ORDER BY uid;'
        );
        $expected = <<<'CSV'
uid,oid
2,3
CSV;
        $this->assertSame($expected, $result);
    }

    /** Anti-join: only the user with no orders survives. */
    public function testLeftJoinAntiJoin(): void
    {
        $result = $this->query(
            'SELECT u.id AS uid, o.id AS oid FROM users u'
            . ' LEFT JOIN orders o ON u.id = o.user_id WHERE o.id IS NULL ORDER BY uid;'
        );
        $expected = <<<'CSV'
uid,oid
3,
CSV;
        $this->assertSame($expected, $result);
    }

    /** RIGHT JOIN + any WHERE used to die with a FilteredTable TypeError. */
    public function testRightJoinWithWhere(): void
    {
        $result = $this->query(
            'SELECT u.id AS uid, o.id AS oid FROM orders o'
            . ' RIGHT JOIN users u ON u.id = o.user_id WHERE u.active = 1 ORDER BY uid, oid;'
        );
        $expected = <<<'CSV'
uid,oid
1,1
1,2
2,3
CSV;
        $this->assertSame($expected, $result);
    }

    /** FULL JOIN + any WHERE used to die with a FilteredTable TypeError. */
    public function testFullJoinWithWhere(): void
    {
        $result = $this->query(
            'SELECT u.id AS uid, o.id AS oid FROM users u'
            . ' FULL JOIN orders o ON u.id = o.user_id WHERE u.id = 1 ORDER BY oid;'
        );
        $expected = <<<'CSV'
uid,oid
1,1
1,2
CSV;
        $this->assertSame($expected, $result);
    }

    /**
     * NULL = NULL is UNKNOWN, not true. contacts 2 and 4 both have a NULL
     * email and must not join to each other (or to themselves).
     */
    public function testInnerJoinNullKeysDoNotMatch(): void
    {
        $result = $this->query(
            'SELECT c1.id AS a, c2.id AS b FROM contacts c1'
            . ' JOIN contacts c2 ON c1.email = c2.email ORDER BY a, b;'
        );
        $expected = <<<'CSV'
a,b
1,1
3,3
5,5
CSV;
        $this->assertSame($expected, $result);
    }

    /** Same rule on the outer side: NULL keys fall through to the NULL row. */
    public function testLeftJoinNullKeysDoNotMatch(): void
    {
        $result = $this->query(
            'SELECT c1.id AS a, c2.id AS b FROM contacts c1'
            . ' LEFT JOIN contacts c2 ON c1.phone = c2.phone ORDER BY a, b;'
        );
        $expected = <<<'CSV'
a,b
1,1
2,2
3,
4,
5,5
CSV;
        $this->assertSame($expected, $result);
    }

    /**
     * A non-equality ON condition must not be executed as an equi-join.
     * InnerJoinTable used to drop the operator and return `u.id = o.user_id`.
     */
    public function testInnerJoinNonEqualityCondition(): void
    {
        $result = $this->query(
            'SELECT u.id AS uid, o.id AS oid FROM users u'
            . ' JOIN orders o ON u.id > o.user_id ORDER BY uid, oid;'
        );
        $expected = <<<'CSV'
uid,oid
2,1
2,2
3,1
3,2
3,3
CSV;
        $this->assertSame($expected, $result);
    }

    // =========================================================================
    // Set algebra — trailing ORDER BY / LIMIT / OFFSET and branch execution
    // =========================================================================

    /**
     * A trailing ORDER BY belongs to the whole set result, not to the last
     * branch. The parser used to leave it on the right SELECT, so the output
     * came back in branch-concatenation order.
     */
    public function testUnionOrderByAppliesToWholeResult(): void
    {
        $result = $this->query('SELECT id FROM users UNION SELECT id FROM products ORDER BY id DESC;');
        $expected = <<<'CSV'
id
4
3
2
1
CSV;
        $this->assertSame($expected, $result);
    }

    /** LIMIT after a set operation limits the set result, not the last branch. */
    public function testUnionAllOrderByLimitAppliesToWholeResult(): void
    {
        $result = $this->query('SELECT id FROM users UNION ALL SELECT id FROM products ORDER BY id DESC LIMIT 3;');
        $expected = <<<'CSV'
id
4
3
3
CSV;
        $this->assertSame($expected, $result);
    }

    public function testUnionOrderByLimitOffset(): void
    {
        $result = $this->query('SELECT id FROM users UNION SELECT id FROM products ORDER BY id LIMIT 2 OFFSET 1;');
        $expected = <<<'CSV'
id
2
3
CSV;
        $this->assertSame($expected, $result);
    }

    public function testExceptOrderByDesc(): void
    {
        $result = $this->query('SELECT id FROM products EXCEPT SELECT id FROM users ORDER BY id DESC;');
        $expected = <<<'CSV'
id
4
CSV;
        $this->assertSame($expected, $result);
    }

    public function testIntersectOrderByDesc(): void
    {
        $result = $this->query('SELECT id FROM products INTERSECT SELECT id FROM users ORDER BY id DESC;');
        $expected = <<<'CSV'
id
3
2
1
CSV;
        $this->assertSame($expected, $result);
    }

    /**
     * Set branches went through a projection-only executor that could not
     * evaluate aggregates: this used to return the seven raw fixture rows of
     * users and products instead of two counts.
     */
    public function testUnionBranchAggregate(): void
    {
        $result = $this->query('SELECT COUNT(*) AS c FROM users UNION SELECT COUNT(*) AS c FROM products ORDER BY c;');
        $expected = <<<'CSV'
c
3
4
CSV;
        $this->assertSame($expected, $result);
    }

    public function testUnionBranchGroupBy(): void
    {
        $result = $this->query(
            'SELECT role AS g, COUNT(*) AS c FROM users GROUP BY role'
            . ' UNION SELECT category AS g, COUNT(*) AS c FROM products GROUP BY category ORDER BY g;'
        );
        $expected = <<<'CSV'
g,c
admin,1
gadgets,2
tools,2
user,2
CSV;
        $this->assertSame($expected, $result);
    }

    /** The alias names the result column, and ORDER BY must find it. */
    public function testUnionBranchColumnAlias(): void
    {
        $result = $this->query('SELECT role AS r FROM users UNION SELECT category AS r FROM products ORDER BY r;');
        $expected = <<<'CSV'
r
admin
gadgets
tools
user
CSV;
        $this->assertSame($expected, $result);
    }

    /** `IN (SELECT … UNION SELECT …)` used to die with a TypeError. */
    public function testInSubqueryWithSetOperation(): void
    {
        $result = $this->query(
            'SELECT id FROM users WHERE id IN (SELECT id FROM products UNION SELECT id FROM orders) ORDER BY id;'
        );
        $expected = <<<'CSV'
id
1
2
3
CSV;
        $this->assertSame($expected, $result);

        $result = $this->query(
            'SELECT id FROM products WHERE id NOT IN'
            . ' (SELECT id FROM users UNION SELECT user_id FROM orders) ORDER BY id;'
        );
        $expected = <<<'CSV'
id
4
CSV;
        $this->assertSame($expected, $result);
    }

    /** A qualified column in a joined branch used to fail to resolve. */
    public function testUnionOfJoinAndPlainSelect(): void
    {
        $result = $this->query(
            'SELECT u.id AS x FROM users u JOIN orders o ON u.id = o.user_id'
            . ' UNION SELECT p.id AS x FROM products p ORDER BY x;'
        );
        $expected = <<<'CSV'
x
1
2
3
4
CSV;
        $this->assertSame($expected, $result);
    }
    public function testCorrelatedSubqueryKeepsBetweenAndIsNullPredicates(): void
    {
        // Regression: the correlated fast-path evaluator returned TRUE for any
        // node shape it did not recognise, so BETWEEN / IS NULL / IN simply
        // vanished and the subquery matched far too much.
        $result = $this->query(
            'SELECT u.id FROM users u WHERE u.id IN ('
            . 'SELECT o.user_id FROM orders o WHERE o.user_id = u.id AND o.total BETWEEN 1 AND 2'
            . ') ORDER BY u.id;'
        );
        $this->assertSame('', $result);

        $result = $this->query(
            'SELECT u.id FROM users u WHERE u.id IN ('
            . 'SELECT o.user_id FROM orders o WHERE o.user_id = u.id AND o.total IS NULL'
            . ') ORDER BY u.id;'
        );
        $this->assertSame('', $result);
    }

    public function testCorrelatedSubqueryWithArithmeticOnOuterValue(): void
    {
        // Regression: "u.id * 10" reached an evaluator with no outer context
        // and died with "Column not found: id".
        $result = $this->query(
            'WITH c AS (SELECT user_id, total FROM orders)'
            . ' SELECT u.id FROM users u WHERE u.id IN ('
            . 'SELECT c.user_id FROM c WHERE c.total > u.id * 10) ORDER BY u.id;'
        );
        $expected = <<<'CSV'
id
1
2
CSV;
        $this->assertSame($expected, $result);
    }

    public function testCorrelatedSubqueryWithOrIsStillCorrect(): void
    {
        $result = $this->query(
            'SELECT u.id, (SELECT COUNT(*) FROM orders o WHERE o.user_id = u.id OR o.total > 100) AS c'
            . ' FROM users u ORDER BY u.id;'
        );
        $expected = <<<'CSV'
id,c
1,2
2,1
3,0
CSV;
        $this->assertSame($expected, $result);
    }

    public function testCorrelatedExistsWithUntraversedNodeShape(): void
    {
        // The outer reference hides inside NOT(...), which the outer-reference
        // collector does not walk - the EXISTS used to be treated as
        // uncorrelated and matched every user.
        $result = $this->query(
            'SELECT u.id FROM users u WHERE EXISTS ('
            . 'SELECT 1 FROM orders o WHERE NOT (o.user_id <> u.id)) ORDER BY u.id;'
        );
        $expected = <<<'CSV'
id
1
2
CSV;
        $this->assertSame($expected, $result);
    }

    public function testRecursiveCteWithUnionDeduplicatesAndTerminates(): void
    {
        // Regression: UNION behaved like UNION ALL, so a cyclic recursive term
        // ran to the 10000-iteration cap and returned 10001 rows.
        $result = $this->query(
            'WITH RECURSIVE r(n) AS (SELECT 1 UNION SELECT (n % 3) + 1 FROM r) SELECT n FROM r ORDER BY n;'
        );
        $expected = <<<'CSV'
n
1
2
3
CSV;
        $this->assertSame($expected, $result);
    }

    public function testSubqueryOverDerivedTableInFrom(): void
    {
        // Regression: executeSelectAsTable() called getFullName() on a
        // SubqueryNode - fatal - for IN- and EXISTS-subqueries over a
        // derived table.
        $result = $this->query(
            'SELECT id FROM products WHERE EXISTS ('
            . 'SELECT 1 FROM (SELECT id FROM users) t WHERE t.id > 2) ORDER BY id;'
        );
        $expected = <<<'CSV'
id
1
2
3
4
CSV;
        $this->assertSame($expected, $result);

        $result = $this->query(
            'SELECT id FROM products WHERE EXISTS ('
            . 'SELECT 1 FROM (SELECT id FROM users) t WHERE t.id > 9) ORDER BY id;'
        );
        $this->assertSame('', $result);
    }
    public function testExistsOverUnionSubquery(): void
    {
        // Regression: a UNION body reached executeSelectAsTable(), which only
        // accepts a SelectStatement - fatal TypeError.
        $result = $this->query(
            'SELECT id FROM users WHERE EXISTS (SELECT 1 FROM orders UNION SELECT 1 FROM products) ORDER BY id;'
        );
        $expected = <<<'CSV'
id
1
2
3
CSV;
        $this->assertSame($expected, $result);

        $result = $this->query(
            'SELECT id FROM users WHERE EXISTS ('
            . 'SELECT 1 FROM orders WHERE id > 99 UNION SELECT 1 FROM products WHERE id > 99) ORDER BY id;'
        );
        $this->assertSame('', $result);
    }

    // =========================================================================
    // Three-valued logic regressions (differential-tested against sqlite3)
    // =========================================================================

    public function testNotInSubqueryWithNullOnTheLeft(): void
    {
        // Regression: the WHERE pushdown did $table->except($matches), which
        // keeps rows whose column is NULL. `name NOT IN (...)` is UNKNOWN - not
        // TRUE - for contact 5 (name IS NULL), so it must not be returned.
        // ExpressionEvaluator::evaluateIn() always got this right, so the same
        // predicate used to give different answers per planner path.
        $result = $this->query(
            'SELECT id FROM contacts WHERE name NOT IN '
            . '(SELECT name FROM contacts WHERE id = 1) ORDER BY id;'
        );
        $expected = <<<'CSV'
id
2
3
4
CSV;
        $this->assertSame($expected, $result);

        // Same predicate written as NOT (x IN ...) and via a table alias.
        $result = $this->query(
            'SELECT id FROM contacts WHERE NOT (name IN '
            . '(SELECT name FROM contacts WHERE id = 1)) ORDER BY id;'
        );
        $this->assertSame($expected, $result);

        $result = $this->query(
            'SELECT id FROM contacts c WHERE c.name NOT IN '
            . '(SELECT name FROM contacts WHERE id = 1) ORDER BY id;'
        );
        $this->assertSame($expected, $result);
    }

    public function testNotInSubqueryWithNullInTheResultSet(): void
    {
        // Regression: a NULL anywhere in the subquery result makes every
        // non-matching row UNKNOWN, so NOT IN can never be TRUE. The pushdown
        // returned all 5 rows instead of none.
        $result = $this->query(
            'SELECT COUNT(*) FROM contacts WHERE name NOT IN (SELECT notes FROM contacts);'
        );
        $expected = <<<'CSV'
COUNT(*)
0
CSV;
        $this->assertSame($expected, $result);

        $result = $this->query(
            'SELECT id FROM contacts WHERE email NOT IN (SELECT phone FROM contacts) ORDER BY id;'
        );
        $this->assertSame('', $result);

        $result = $this->query(
            'SELECT id FROM contacts WHERE notes NOT IN (SELECT name FROM contacts) ORDER BY id;'
        );
        $this->assertSame('', $result);

        // Both failure modes at once: NULL on the left AND NULL in the set.
        $result = $this->query(
            'SELECT id FROM contacts WHERE email NOT IN '
            . '(SELECT email FROM contacts WHERE id = 1) ORDER BY id;'
        );
        $expected = <<<'CSV'
id
3
5
CSV;
        $this->assertSame($expected, $result);
    }

    public function testNotInSubqueryWithoutNullsStillWorks(): void
    {
        // The fast path must stay correct for NULL-free data.
        $result = $this->query(
            'SELECT id FROM users WHERE id NOT IN (SELECT user_id FROM orders) ORDER BY id;'
        );
        $expected = <<<'CSV'
id
3
CSV;
        $this->assertSame($expected, $result);

        // Empty subquery: NOT IN () is TRUE for every row, NULL ones included.
        $result = $this->query(
            'SELECT id FROM contacts WHERE name NOT IN '
            . '(SELECT name FROM contacts WHERE id = 99) ORDER BY id;'
        );
        $expected = <<<'CSV'
id
1
2
3
4
5
CSV;
        $this->assertSame($expected, $result);
    }

    public function testComparisonAgainstNullLiteralInOrPredicate(): void
    {
        // Regression: the OR-predicate builder guarded only `col = NULL`, so
        // `col < NULL` leaked a PHP TypeError out of Predicate::lt(). Every
        // comparison against NULL is UNKNOWN.
        $result = $this->query('SELECT id FROM contacts WHERE id < NULL OR id > 4 ORDER BY id;');
        $expected = <<<'CSV'
id
5
CSV;
        $this->assertSame($expected, $result);

        // NOT BETWEEN is rewritten to `col < low OR col > high`, so a NULL
        // bound reaches the same site.
        $result = $this->query('SELECT id FROM contacts WHERE id NOT BETWEEN NULL AND 4 ORDER BY id;');
        $this->assertSame($expected, $result);

        $result = $this->query('SELECT id FROM contacts WHERE id NOT BETWEEN 2 AND NULL ORDER BY id;');
        $expected = <<<'CSV'
id
1
CSV;
        $this->assertSame($expected, $result);

        // BETWEEN with a NULL bound is UNKNOWN for every row.
        $result = $this->query('SELECT id FROM contacts WHERE id BETWEEN NULL AND 4 ORDER BY id;');
        $this->assertSame('', $result);
    }

    public function testNullComparisonInsideAnAndBranchOfAnOr(): void
    {
        // Regression, silent wrong answer: `col = NULL` produced
        // Predicate::never(), but never() only short-circuits Predicate::test()
        // - TableInterface::or() reads the condition list instead. Chaining
        // `AND id > 1` onto it therefore dropped the "matches nothing" flag and
        // the branch degenerated into plain `id > 1`, returning 2,3,4,5.
        $expected = <<<'CSV'
id
5
CSV;
        foreach (['=', '<', '<=', '>', '>='] as $op) {
            $result = $this->query(
                "SELECT id FROM contacts WHERE (id $op NULL AND id > 1) OR id = 5 ORDER BY id;"
            );
            $this->assertSame($expected, $result);

            // ...and with the NULL comparison as the second conjunct, which used
            // to leak a PHP TypeError out of Predicate::lt()/gt().
            $result = $this->query(
                "SELECT id FROM contacts WHERE (id > 1 AND id $op NULL) OR id = 5 ORDER BY id;"
            );
            $this->assertSame($expected, $result);
        }

        // NULL-free OR predicates must still take the fast path unchanged.
        $result = $this->query('SELECT id FROM contacts WHERE (id > 1 AND id < 4) OR id = 5 ORDER BY id;');
        $expected = <<<'CSV'
id
2
3
5
CSV;
        $this->assertSame($expected, $result);
    }

    public function testComparisonAgainstScalarSubqueryReturningNull(): void
    {
        // Regression: a scalar subquery that came back NULL was passed straight
        // to InMemoryTable::lt()/gt(), leaking a PHP TypeError. Comparing
        // against NULL is UNKNOWN, so the result is empty.
        $result = $this->query(
            'SELECT id FROM contacts WHERE id < (SELECT name FROM contacts WHERE id = 5) ORDER BY id;'
        );
        $this->assertSame('', $result);

        $result = $this->query(
            'SELECT id FROM contacts WHERE id > (SELECT notes FROM contacts WHERE id = 2) ORDER BY id;'
        );
        $this->assertSame('', $result);
    }

    public function testLikeAndInAgainstAnotherColumn(): void
    {
        // Regression: the LIKE pattern and the IN value list are hoisted out of
        // the row loop and evaluated with a null row, so a column reference
        // there threw "Cannot evaluate column reference without row context".
        $result = $this->query('SELECT id FROM contacts WHERE notes LIKE notes ORDER BY id;');
        $expected = <<<'CSV'
id
1
3
5
CSV;
        $this->assertSame($expected, $result);

        // NOT LIKE against a NULL column stays UNKNOWN, so nothing matches.
        $result = $this->query('SELECT id FROM contacts WHERE notes NOT LIKE notes ORDER BY id;');
        $this->assertSame('', $result);

        $result = $this->query('SELECT id FROM contacts WHERE id IN (id, 99) ORDER BY id;');
        $expected = <<<'CSV'
id
1
2
3
4
5
CSV;
        $this->assertSame($expected, $result);

        $result = $this->query('SELECT id FROM contacts WHERE name IN (email, phone) ORDER BY id;');
        $this->assertSame('', $result);

        // Constant patterns must still be pushed down and stay correct.
        $result = $this->query("SELECT id FROM contacts WHERE name LIKE '%' || 'lic' || '%' ORDER BY id;");
        $expected = <<<'CSV'
id
1
CSV;
        $this->assertSame($expected, $result);
    }

    public function testLikeOnAnExpressionInsteadOfAColumn(): void
    {
        // Regression: the LIKE pushdown threw "Left side of LIKE must be a
        // column" instead of falling back to row-by-row evaluation.
        $result = $this->query(
            "SELECT id FROM contacts WHERE UPPER(email) LIKE '%TEST%' ORDER BY id;"
        );
        $expected = <<<'CSV'
id
1
3
5
CSV;
        $this->assertSame($expected, $result);

        // NOT LIKE over an expression keeps three-valued logic: contact 5 has
        // a NULL name, so UPPER(name) NOT LIKE 'A%' is UNKNOWN for it.
        $result = $this->query(
            "SELECT id FROM contacts WHERE UPPER(name) NOT LIKE 'A%' ORDER BY id;"
        );
        $expected = <<<'CSV'
id
2
3
4
CSV;
        $this->assertSame($expected, $result);
    }

    public function testNumericFunctionsRejectNonNumericArgumentsCleanly(): void
    {
        // Regression: abs()/round()/floor()/ceil() leaked raw PHP TypeError
        // messages. Non-numeric input must fail at the SQL level instead.
        foreach (['ABS', 'ROUND', 'FLOOR', 'CEIL', 'CEILING'] as $fn) {
            $result = $this->query("SELECT $fn('abc');");
            $this->assertSame("Error: $fn() expects a numeric argument, string given", $result);
        }

        // Numeric strings are still coerced, and NULL still propagates.
        $result = $this->query("SELECT ABS('-5');");
        $expected = <<<'CSV'
ABS(...)
5
CSV;
        $this->assertSame($expected, $result);

        $result = $this->query('SELECT ABS(NULL);');
        $expected = <<<'CSV'
ABS(...)

CSV;
        $this->assertSame(trim($expected), $result);
    }

    public function testInSubqueryWithComputedProjectionKeepsNullSemantics(): void
    {
        // Regression: a computed subquery projection was materialised through
        // mini\Table\Utility\Set, which keys members by string - so UPPER(NULL)
        // became '' and matched rows holding an empty string, and NOT IN never
        // saw the NULL that should have made it UNKNOWN.
        $result = $this->query(
            'SELECT id FROM contacts WHERE name NOT IN '
            . '(SELECT UPPER(name) FROM contacts) ORDER BY id;'
        );
        $this->assertSame('', $result);

        // '' must not match the NULL that UPPER(notes) produces for contacts 2
        // and 4. Contact 4 is Diana, so REPLACE() gives it an empty name.
        $result = $this->query(
            "SELECT t.id FROM (SELECT id, REPLACE(name, 'Diana', '') AS n FROM contacts) t "
            . 'WHERE t.n IN (SELECT UPPER(notes) FROM contacts) ORDER BY t.id;'
        );
        $this->assertSame('', $result);

        // A NULL-free computed projection still matches normally.
        $result = $this->query(
            'SELECT id FROM contacts WHERE id IN (SELECT id * 1 FROM contacts WHERE id < 3) ORDER BY id;'
        );
        $expected = <<<'CSV'
id
1
2
CSV;
        $this->assertSame($expected, $result);
    }

    public function testStringComparisonDoesNotUsePhpNumericStringJuggling(): void
    {
        // Regression, silent wrong answer: comparisons went through PHP's `==`
        // and `<`, which compare two *numeric strings* numerically. '1' = '01',
        // '5' = '5.0' and ' 5' = '5' were all true - while the table layer
        // compares them as characters. So `WHERE code = '1'` (pushed down) found
        // one row while `WHERE UPPER(code) = UPPER('1')` (row by row) found five,
        // and `code <> '1'` silently dropped every other spelling of 1.
        foreach ([["'5'", "'5.0'"], ["'1'", "'01'"], ["'5'", "' 5'"], ["'1e1'", "'10'"]] as [$a, $b]) {
            $result = $this->query("SELECT CASE WHEN $a = $b THEN 'EQ' ELSE 'NE' END AS r;");
            $expected = <<<'CSV'
r
NE
CSV;
            $this->assertSame($expected, $result, "$a = $b must compare as characters");
        }

        // Ordering is bytewise too: '10' < '9' as characters, not 10 < 9.
        $result = $this->query("SELECT CASE WHEN '10' < '9' THEN 'LT' ELSE 'GE' END AS r;");
        $expected = <<<'CSV'
r
LT
CSV;
        $this->assertSame($expected, $result);

        // Identical strings still compare equal, and the operators still work.
        $result = $this->query("SELECT CASE WHEN 'abc' = 'abc' THEN 'EQ' ELSE 'NE' END AS r;");
        $expected = <<<'CSV'
r
EQ
CSV;
        $this->assertSame($expected, $result);

        // The same predicate must give the same answer whether the planner
        // pushes it to the table or evaluates it row by row.
        $pushed = $this->query("SELECT id FROM contacts WHERE name = 'Alice' ORDER BY id;");
        $rowwise = $this->query("SELECT id FROM contacts WHERE UPPER(name) = UPPER('Alice') ORDER BY id;");
        $this->assertSame($pushed, $rowwise);
    }

    public function testScientificNotationNumericLiterals(): void
    {
        // Regression: the lexer's number pattern had no exponent part, so
        // `SELECT 1e3` lexed as the literal 1 followed by the alias `e3` and
        // silently returned 1. In a WHERE clause the same input was a parse
        // error, so the literal meant two different things per position.
        $result = $this->query('SELECT 1e3 AS v;');
        $expected = <<<'CSV'
v
1000
CSV;
        $this->assertSame($expected, $result);

        $result = $this->query('SELECT 1E3 + 1 AS v;');
        $expected = <<<'CSV'
v
1001
CSV;
        $this->assertSame($expected, $result);

        $result = $this->query('SELECT 1e-3 AS v;');
        $expected = <<<'CSV'
v
0.001
CSV;
        $this->assertSame($expected, $result);

        $result = $this->query('SELECT 1.5e2 AS v;');
        $expected = <<<'CSV'
v
150
CSV;
        $this->assertSame($expected, $result);

        $result = $this->query('SELECT id FROM contacts WHERE id < 1e1 ORDER BY id;');
        $expected = <<<'CSV'
id
1
2
3
4
5
CSV;
        $this->assertSame($expected, $result);

        // An identifier that merely starts with `e` is still an alias.
        $result = $this->query('SELECT id e3 FROM contacts WHERE id = 1;');
        $expected = <<<'CSV'
e3
1
CSV;
        $this->assertSame($expected, $result);
    }

    // =========================================================================
    // Aggregates: bare columns take their value from the MIN()/MAX() row
    //
    // SQL:2003 rejects `SELECT MAX(price), name`; VDB follows the
    // SQLite/MySQL extension and returns a representative row, which means it
    // must follow SQLite's rule for *which* row: with exactly one aggregate
    // and that aggregate being MIN() or MAX(), bare columns come from the
    // extreme row (not from an arbitrary one).
    // =========================================================================

    public function testBareColumnComesFromMaxRow(): void
    {
        $result = $this->query('SELECT MAX(price) AS p, name FROM products;');
        $expected = <<<'CSV'
p,name
24.99,Gizmo
CSV;
        $this->assertSame($expected, $result);
    }

    public function testBareColumnComesFromMinRow(): void
    {
        $result = $this->query('SELECT MIN(id) AS m, name FROM users;');
        $expected = <<<'CSV'
m,name
1,Alice
CSV;
        $this->assertSame($expected, $result);

        // The extreme row is picked after WHERE, not before.
        $result = $this->query("SELECT MAX(price) AS p, name FROM products WHERE category = 'tools';");
        $expected = <<<'CSV'
p,name
14.99,Thingamajig
CSV;
        $this->assertSame($expected, $result);
    }

    public function testGroupedBareColumnComesFromExtremeRowOfItsGroup(): void
    {
        // Widget (9.99) is the first gadgets row, but 24.99 belongs to Gizmo.
        $result = $this->query(
            'SELECT category, MAX(price) AS p, name FROM products GROUP BY category ORDER BY category;'
        );
        $expected = <<<'CSV'
category,p,name
gadgets,24.99,Gizmo
tools,14.99,Thingamajig
CSV;
        $this->assertSame($expected, $result);

        $result = $this->query(
            'SELECT category, MIN(price) AS p, name FROM products GROUP BY category ORDER BY category;'
        );
        $expected = <<<'CSV'
category,p,name
gadgets,9.99,Widget
tools,4.99,Doohickey
CSV;
        $this->assertSame($expected, $result);
    }

    public function testWildcardWithMinMaxAndGroupByUsesExtremeRow(): void
    {
        $result = $this->query(
            'SELECT *, MAX(price) AS p FROM products GROUP BY category ORDER BY category;'
        );
        $expected = <<<'CSV'
id,name,price,category,stock,p
2,Gizmo,24.99,gadgets,50,24.99
3,Thingamajig,14.99,tools,75,14.99
CSV;
        $this->assertSame($expected, $result);

        $result = $this->query(
            'SELECT *, MAX(id) AS m FROM users GROUP BY role ORDER BY role;'
        );
        $expected = <<<'CSV'
id,name,email,role,active,m
1,Alice,alice@example.com,admin,1,1
3,Charlie,charlie@example.com,user,0,3
CSV;
        $this->assertSame($expected, $result);
    }

    public function testBareColumnWithNonExtremeAggregateUsesFirstRow(): void
    {
        // Not a lone MIN()/MAX(): the representative row is the first one.
        $result = $this->query('SELECT COUNT(*) AS c, name FROM products;');
        $expected = <<<'CSV'
c,name
4,Widget
CSV;
        $this->assertSame($expected, $result);
    }

    // =========================================================================
    // Window functions combined with DISTINCT / GROUP BY / HAVING
    //
    // Windows are evaluated after GROUP BY/HAVING and before DISTINCT, so the
    // window branch must not bypass any of them.
    // =========================================================================

    public function testDistinctWithWindowFunction(): void
    {
        $result = $this->query(
            'SELECT DISTINCT category, RANK() OVER (ORDER BY category) AS r FROM products ORDER BY category;'
        );
        $expected = <<<'CSV'
category,r
gadgets,1
tools,3
CSV;
        $this->assertSame($expected, $result);
    }

    public function testWindowFunctionWithGroupBy(): void
    {
        $result = $this->query(
            'SELECT category, ROW_NUMBER() OVER (ORDER BY category) AS rn FROM products '
            . 'GROUP BY category ORDER BY category;'
        );
        $expected = <<<'CSV'
category,rn
gadgets,1
tools,2
CSV;
        $this->assertSame($expected, $result);
    }

    public function testWindowFunctionWithGroupByAndHaving(): void
    {
        $result = $this->query(
            'SELECT role, RANK() OVER (ORDER BY role) AS r FROM users '
            . 'GROUP BY role HAVING COUNT(*) = 1 ORDER BY role;'
        );
        $expected = <<<'CSV'
role,r
admin,1
CSV;
        $this->assertSame($expected, $result);
    }

    public function testWindowFunctionOverAggregateOfItsGroup(): void
    {
        // COUNT(*) inside OVER() is computed by the grouping pass and handed
        // to the window pass under a synthetic name that must not be projected.
        $result = $this->query(
            'SELECT role, COUNT(*) AS n, ROW_NUMBER() OVER (ORDER BY COUNT(*) DESC, role) AS rn '
            . 'FROM users GROUP BY role ORDER BY rn;'
        );
        $expected = <<<'CSV'
role,n,rn
user,2,1
admin,1,2
CSV;
        $this->assertSame($expected, $result);

        $result = $this->query(
            'SELECT category, SUM(stock) AS s, RANK() OVER (ORDER BY SUM(stock)) AS r '
            . 'FROM products GROUP BY category ORDER BY category;'
        );
        $expected = <<<'CSV'
category,s,r
gadgets,150,1
tools,275,2
CSV;
        $this->assertSame($expected, $result);
    }

    // =========================================================================
    // Aggregates: LIMIT / OFFSET on the implicit single group
    // =========================================================================

    public function testLimitAndOffsetApplyToUngroupedAggregate(): void
    {
        $this->assertSame('', $this->query('SELECT COUNT(*) AS n FROM products LIMIT 0;'));
        $this->assertSame('', $this->query('SELECT MIN(price) AS a FROM products LIMIT 0;'));
        $this->assertSame('', $this->query('SELECT COUNT(*) AS n FROM products LIMIT 1 OFFSET 1;'));

        $expected = <<<'CSV'
n
4
CSV;
        $this->assertSame($expected, $this->query('SELECT COUNT(*) AS n FROM products LIMIT 1;'));
    }

    // =========================================================================
    // Aggregates: GROUP BY a SELECT alias (SQLite/MySQL/PostgreSQL extension)
    // =========================================================================

    public function testGroupBySelectAlias(): void
    {
        $result = $this->query(
            'SELECT category AS c, COUNT(*) AS n FROM products GROUP BY c ORDER BY c;'
        );
        $expected = <<<'CSV'
c,n
gadgets,2
tools,2
CSV;
        $this->assertSame($expected, $result);

        $result = $this->query(
            'SELECT UPPER(category) AS c, COUNT(*) AS n FROM products GROUP BY c ORDER BY c;'
        );
        $expected = <<<'CSV'
c,n
GADGETS,2
TOOLS,2
CSV;
        $this->assertSame($expected, $result);
    }

    public function testRealColumnWinsOverSelectAliasInGroupBy(): void
    {
        // `category` is a real column, so GROUP BY groups by it - not by the
        // `name AS category` alias.
        $result = $this->query(
            'SELECT name AS category, COUNT(*) AS n FROM products GROUP BY category ORDER BY category;'
        );
        $expected = <<<'CSV'
category,n
Thingamajig,2
Widget,2
CSV;
        $this->assertSame($expected, $result);
    }

    // =========================================================================
    // Aggregates: set quantifier and non-numeric input
    // =========================================================================

    public function testAggregateAcceptsAllSetQuantifier(): void
    {
        $expected = <<<'CSV'
n
3
CSV;
        $this->assertSame($expected, $this->query('SELECT COUNT(ALL email) AS n FROM contacts;'));

        $expected = <<<'CSV'
n
54.96
CSV;
        $this->assertSame($expected, $this->query('SELECT SUM(ALL price) AS n FROM products;'));

        // DISTINCT still works, and so does the argument-less COUNT(*).
        $expected = <<<'CSV'
n
3
CSV;
        $this->assertSame($expected, $this->query('SELECT COUNT(DISTINCT email) AS n FROM contacts;'));
    }

    public function testSumOfNonNumericTextRaisesSqlError(): void
    {
        // Must be a SQL diagnostic, not a leaked PHP TypeError or warning.
        $result = $this->query('SELECT SUM(email) AS s FROM contacts;');
        $this->assertStringContainsString('SUM() requires numeric values', $result);
        $this->assertStringNotContainsString('Unsupported operand types', $result);

        $result = $this->query('SELECT AVG(name) AS s FROM users;');
        $this->assertStringContainsString('AVG() requires numeric values', $result);

        // Numeric text is still summable, and booleans still count.
        $expected = <<<'CSV'
s
3
CSV;
        $this->assertSame($expected, $this->query('SELECT SUM(price > 5) AS s FROM products;'));
    }
    // =========================================================================
    // Correlated-subquery and CTE regressions
    // (differential-tested against sqlite3 with the same fixture data)
    // =========================================================================

    public function testCorrelatedOuterReferenceInSubquerySelectList(): void
    {
        // Regression: executeSubqueryWithContext()'s fast path admitted a query
        // on the strength of its WHERE clause alone and then projected the
        // SELECT list against *inner* rows with no outer context. An outer
        // reference there silently resolved against the inner table - `u.id`
        // was read as `o.id` - or failed to resolve at all.
        $result = $this->query(
            'SELECT u.id, (SELECT u.id FROM orders o WHERE o.id = 1) AS x FROM users u ORDER BY u.id;'
        );
        $expected = <<<'CSV'
id,x
1,1
2,2
3,3
CSV;
        $this->assertSame($expected, $result);

        // Outer reference inside an aggregate argument.
        $result = $this->query(
            'SELECT u.id, (SELECT SUM(o.total * u.id) FROM orders o WHERE o.user_id = u.id) AS x '
            . 'FROM users u ORDER BY u.id;'
        );
        $expected = <<<'CSV'
id,x
1,34.97
2,149.94
3,
CSV;
        $this->assertSame($expected, $result);

        // Outer reference outside the aggregate: used to be "Column not found: id".
        $result = $this->query(
            'SELECT u.id, (SELECT MAX(o.total) + u.id FROM orders o WHERE o.user_id = u.id) AS x '
            . 'FROM users u ORDER BY u.id;'
        );
        $expected = <<<'CSV'
id,x
1,20.98
2,76.97
3,
CSV;
        $this->assertSame($expected, $result);

        // Outer reference inside CASE.
        $result = $this->query(
            "SELECT u.id, (SELECT CASE WHEN u.id > 1 THEN 'big' ELSE 'small' END "
            . 'FROM orders o WHERE o.id = 1) AS x FROM users u ORDER BY u.id;'
        );
        $expected = <<<'CSV'
id,x
1,small
2,big
3,big
CSV;
        $this->assertSame($expected, $result);

        // Outer reference in a function call, and one whose column name does
        // not exist on the inner table at all.
        $result = $this->query(
            'SELECT u.id, (SELECT UPPER(u.name) FROM orders o WHERE o.id = 1) AS x '
            . 'FROM users u ORDER BY u.id;'
        );
        $expected = <<<'CSV'
id,x
1,ALICE
2,BOB
3,CHARLIE
CSV;
        $this->assertSame($expected, $result);

        // Self-referencing shape: same column name on both sides.
        $result = $this->query(
            'SELECT p.id, (SELECT p.price - MIN(x.price) FROM products x) AS d '
            . 'FROM products p ORDER BY p.id;'
        );
        $expected = <<<'CSV'
id,d
1,5
2,20
3,10
4,0
CSV;
        $this->assertSame($expected, $result);
    }

    public function testCteDeclaredColumnListKeepsColumnTypes(): void
    {
        // Regression: renameCteColumns() declared every renamed column as Text,
        // so InMemoryTable coerced ints to strings. JOIN and NOT EXISTS compare
        // strictly, so "1" never matched 1: the join returned zero rows and the
        // negated EXISTS returned every row. The AS-aliased spelling of the same
        // CTE was correct, which made the divergence easy to miss.
        $result = $this->query(
            'WITH c(uid, tot) AS (SELECT user_id, total FROM orders) '
            . 'SELECT u.name, c.tot FROM users u JOIN c ON c.uid = u.id ORDER BY u.name, c.tot;'
        );
        $expected = <<<'CSV'
name,tot
Alice,14.99
Alice,19.98
Bob,74.97
CSV;
        $this->assertSame($expected, $result);

        // Same CTE under a correlated NOT EXISTS.
        $result = $this->query(
            'WITH c(uid) AS (SELECT user_id FROM orders) SELECT u.id FROM users u '
            . 'WHERE NOT EXISTS (SELECT 1 FROM c WHERE c.uid = u.id) ORDER BY u.id;'
        );
        $expected = <<<'CSV'
id
3
CSV;
        $this->assertSame($expected, $result);

        // ...and the non-negated form, which must keep working.
        $result = $this->query(
            'WITH c(uid) AS (SELECT user_id FROM orders) SELECT u.id FROM users u '
            . 'WHERE EXISTS (SELECT 1 FROM c WHERE c.uid = u.id) ORDER BY u.id;'
        );
        $expected = <<<'CSV'
id
1
2
CSV;
        $this->assertSame($expected, $result);

        // Float columns survive the rename too.
        $result = $this->query(
            'WITH c(tot) AS (SELECT total FROM orders) SELECT COUNT(*) FROM c WHERE tot > 19.98;'
        );
        $expected = <<<'CSV'
COUNT(*)
1
CSV;
        $this->assertSame($expected, $result);
    }

    public function testWithClauseInsideSubqueryPositions(): void
    {
        // Regression: SQL:2003 allows a <with clause> on any <query expression>,
        // so every subquery position must accept one. IN (WITH ...) was a parse
        // error; EXISTS (WITH ...), a scalar (WITH ...) and a CTE whose own body
        // is a WITH all died with raw PHP TypeErrors.
        $result = $this->query(
            'SELECT id FROM users WHERE id IN '
            . '(WITH c AS (SELECT user_id FROM orders) SELECT user_id FROM c) ORDER BY id;'
        );
        $expected = <<<'CSV'
id
1
2
CSV;
        $this->assertSame($expected, $result);

        // Correlated EXISTS over a WITH body.
        $result = $this->query(
            'SELECT u.id FROM users u WHERE EXISTS '
            . '(WITH c AS (SELECT user_id FROM orders) SELECT 1 FROM c WHERE c.user_id = u.id) '
            . 'ORDER BY u.id;'
        );
        $this->assertSame($expected, $result);

        // Uncorrelated EXISTS over a WITH body.
        $result = $this->query(
            'SELECT u.id FROM users u WHERE EXISTS '
            . '(WITH c AS (SELECT user_id FROM orders) SELECT 1 FROM c) ORDER BY u.id;'
        );
        $expected = <<<'CSV'
id
1
2
3
CSV;
        $this->assertSame($expected, $result);

        // Scalar subquery whose body is a WITH.
        $result = $this->query(
            'SELECT u.id, (WITH c AS (SELECT * FROM orders) SELECT COUNT(*) FROM c) AS n '
            . 'FROM users u ORDER BY u.id;'
        );
        $expected = <<<'CSV'
id,n
1,3
2,3
3,3
CSV;
        $this->assertSame($expected, $result);

        // A CTE whose own body is a WITH statement.
        $result = $this->query(
            'WITH a AS (WITH b AS (SELECT 1 AS x) SELECT * FROM b) SELECT * FROM a;'
        );
        $expected = <<<'CSV'
x
1
CSV;
        $this->assertSame($expected, $result);
    }

    public function testCorrelatedExistsOverSetOperation(): void
    {
        // Regression: existsNeedsGenericEvaluation() is typed for SelectStatement,
        // so a correlated EXISTS whose body is a UNION/INTERSECT/EXCEPT reached it
        // as a UnionNode and threw a raw TypeError. Only the uncorrelated shape
        // was covered before.
        $expected = <<<'CSV'
id
1
2
CSV;
        foreach (['UNION', 'UNION ALL', 'EXCEPT'] as $op) {
            $result = $this->query(
                'SELECT u.id FROM users u WHERE EXISTS ('
                . "SELECT 1 FROM orders o WHERE o.user_id = u.id $op "
                . 'SELECT 1 FROM products WHERE id = 99) ORDER BY u.id;'
            );
            $this->assertSame($expected, $result, "correlated EXISTS over $op");
        }

        $result = $this->query(
            'SELECT u.id FROM users u WHERE EXISTS ('
            . 'SELECT 1 FROM orders o WHERE o.user_id = u.id INTERSECT '
            . 'SELECT 1 FROM products WHERE id = 1) ORDER BY u.id;'
        );
        $this->assertSame($expected, $result);

        // Negated form.
        $result = $this->query(
            'SELECT u.id FROM users u WHERE NOT EXISTS ('
            . 'SELECT 1 FROM orders o WHERE o.user_id = u.id UNION '
            . 'SELECT 1 FROM products WHERE id = 99) ORDER BY u.id;'
        );
        $expected = <<<'CSV'
id
3
CSV;
        $this->assertSame($expected, $result);
    }

    public function testRecursiveCteLimitAndNonTermination(): void
    {
        // Regression: a LIMIT on the recursive body was lifted onto the UNION by
        // the parser and then ignored, so this ran to the 10000-iteration cap and
        // returned 10001 rows. SQLite stops the fixpoint as soon as the LIMIT is
        // satisfied, which is what makes the query terminate at all.
        $result = $this->query(
            'WITH RECURSIVE c(n) AS (SELECT 1 UNION ALL SELECT n+1 FROM c LIMIT 5) '
            . 'SELECT COUNT(*), MAX(n) FROM c;'
        );
        $expected = <<<'CSV'
COUNT(*),MAX(n)
5,5
CSV;
        $this->assertSame($expected, $result);

        // The limit may cut the anchor itself.
        $result = $this->query(
            'WITH RECURSIVE c(n) AS (SELECT 1 UNION ALL SELECT n+1 FROM c LIMIT 1) SELECT * FROM c;'
        );
        $expected = <<<'CSV'
n
1
CSV;
        $this->assertSame($expected, $result);

        // A recursion with no terminating condition must fail loudly rather than
        // return an arbitrary 10000-row prefix of an unbounded result.
        $result = $this->query(
            'WITH RECURSIVE c(n) AS (SELECT 1 UNION ALL SELECT n+1 FROM c) SELECT COUNT(*) FROM c;'
        );
        $this->assertStringContainsString('did not terminate', $result);

        // A cyclic UNION ALL term is equally non-terminating.
        $result = $this->query(
            'WITH RECURSIVE c(n) AS (SELECT 1 UNION ALL SELECT n FROM c WHERE n < 5) '
            . 'SELECT COUNT(*) FROM c;'
        );
        $this->assertStringContainsString('did not terminate', $result);

        // ORDER BY / OFFSET on the recursive body cannot be honoured; saying so
        // beats dropping the clause and returning a different answer.
        $result = $this->query(
            'WITH RECURSIVE c(n) AS (SELECT 1 UNION ALL SELECT n+1 FROM c WHERE n < 5 ORDER BY n) '
            . 'SELECT * FROM c;'
        );
        $this->assertStringContainsString('Unsupported', $result);

        // Bounded recursion still terminates by fixpoint, with no limit.
        $result = $this->query(
            'WITH RECURSIVE c(n) AS (SELECT 1 UNION ALL SELECT n+1 FROM c WHERE n < 5) '
            . 'SELECT COUNT(*), SUM(n), MAX(n) FROM c;'
        );
        $expected = <<<'CSV'
COUNT(*),SUM(n),MAX(n)
5,15,5
CSV;
        $this->assertSame($expected, $result);
    }
    public function testCorrelationHiddenInsideDerivedTable(): void
    {
        // Regression: collectScopeAndQualifiers() skipped the whole `from`
        // position because it "names a table". When `from` is a derived table
        // the correlation lives inside it, so the subquery was reported as
        // uncorrelated, evaluated once, and every outer row got the first row's
        // answer. substituteOuterReferences() had the same blind spot.
        $result = $this->query(
            'SELECT u.id, (SELECT COUNT(*) FROM '
            . '(SELECT * FROM orders o WHERE o.user_id = u.id) d) AS n FROM users u ORDER BY u.id;'
        );
        $expected = <<<'CSV'
id,n
1,2
2,1
3,0
CSV;
        $this->assertSame($expected, $result);

        // EXISTS and NOT EXISTS over the same shape.
        $result = $this->query(
            'SELECT u.id FROM users u WHERE EXISTS (SELECT 1 FROM '
            . '(SELECT * FROM orders o WHERE o.user_id = u.id) d) ORDER BY u.id;'
        );
        $expected = <<<'CSV'
id
1
2
CSV;
        $this->assertSame($expected, $result);

        $result = $this->query(
            'SELECT u.id FROM users u WHERE NOT EXISTS (SELECT 1 FROM '
            . '(SELECT * FROM orders o WHERE o.user_id = u.id) d) ORDER BY u.id;'
        );
        $expected = <<<'CSV'
id
3
CSV;
        $this->assertSame($expected, $result);

        // Correlation inside a JOINed derived table.
        $result = $this->query(
            'SELECT u.id, (SELECT COUNT(*) FROM orders o JOIN '
            . '(SELECT id FROM products WHERE id = u.id) p ON p.id = o.product_id) AS n '
            . 'FROM users u ORDER BY u.id;'
        );
        $expected = <<<'CSV'
id,n
1,1
2,1
3,1
CSV;
        $this->assertSame($expected, $result);
    }

    // =========================================================================
    // JOIN / set-algebra regressions (differential vs sqlite3)
    // =========================================================================

    /**
     * LIMIT 0 must return no rows on every join and set-operation path.
     *
     * The wrappers used an emit-then-test pattern
     * (`yield ...; if (++$emitted >= $limit) return;`) so exactly one row was
     * always produced before the limit was first consulted. Plain and filtered
     * tables were correct, and ORDER BY hid the bug because SortedTable's
     * bounded-heap path handles k = 0, so only the unsorted wrapper paths were
     * affected.
     */
    public function testLimitZeroOnJoinsAndSetOperations(): void
    {
        $queries = [
            'SELECT u.id AS a FROM users u JOIN orders o ON u.id = o.user_id LIMIT 0',
            'SELECT u.id AS a FROM users u LEFT JOIN orders o ON u.id = o.user_id LIMIT 0',
            'SELECT u.id AS a FROM users u RIGHT JOIN orders o ON u.id = o.user_id LIMIT 0',
            'SELECT u.id AS a FROM users u FULL JOIN orders o ON u.id = o.user_id LIMIT 0',
            'SELECT u.id AS a FROM users u CROSS JOIN products p LIMIT 0',
            'SELECT u.id AS a FROM users u, products p LIMIT 0',
            // non-equi ON: nested-loop join path
            'SELECT u.id AS a FROM users u JOIN orders o ON u.id > o.user_id LIMIT 0',
            'SELECT u.id AS a FROM users u JOIN orders o ON u.id = o.user_id JOIN products p ON o.product_id = p.id LIMIT 0',
            'SELECT DISTINCT u.id AS a FROM users u JOIN orders o ON u.id = o.user_id LIMIT 0',
            'SELECT u.id AS a FROM users u JOIN orders o ON u.id = o.user_id WHERE u.id = 1 LIMIT 0',
            'SELECT u.id AS a FROM users u JOIN orders o ON u.id = o.user_id LIMIT 0 OFFSET 1',
            'SELECT id AS x FROM users UNION SELECT id AS x FROM products LIMIT 0',
            'SELECT id AS x FROM users UNION ALL SELECT id AS x FROM products LIMIT 0',
            'SELECT id AS x FROM users INTERSECT SELECT id AS x FROM products LIMIT 0',
            'SELECT id AS x FROM users INTERSECT ALL SELECT id AS x FROM products LIMIT 0',
            'SELECT id AS x FROM products EXCEPT SELECT id AS x FROM users LIMIT 0',
            'SELECT id AS x FROM products EXCEPT ALL SELECT id AS x FROM users LIMIT 0',
        ];

        foreach ($queries as $sql) {
            $this->assertSame('', $this->query($sql), "LIMIT 0 must emit nothing: $sql");
        }

        // LIMIT 1 must still emit exactly one row (no off-by-one from the fix).
        $this->assertSame(
            "a\n1",
            $this->query('SELECT u.id AS a FROM users u JOIN orders o ON u.id = o.user_id ORDER BY a LIMIT 1')
        );
        $this->assertSame(
            "x\n1",
            $this->query('SELECT id AS x FROM users UNION ALL SELECT id AS x FROM products ORDER BY x LIMIT 1')
        );
    }

    /**
     * ORDER BY over a set operation may only name the result columns, which
     * SQL takes from the first branch. Naming the second branch's alias used
     * to be silently dropped - and worse, partially pushed into one ConcatTable
     * branch, so the output came back scrambled rather than merely unsorted.
     * An unresolvable ORDER BY column anywhere is now an error.
     */
    public function testOrderByUnknownColumnIsRejected(): void
    {
        $result = $this->query('SELECT id AS x FROM users UNION ALL SELECT id AS y FROM products ORDER BY y DESC');
        $this->assertStringContainsString('ORDER BY references unknown column: y', $result);

        $result = $this->query('SELECT role AS a FROM users UNION SELECT category AS b FROM products ORDER BY b');
        $this->assertStringContainsString('ORDER BY references unknown column: b', $result);

        $result = $this->query('SELECT id AS x FROM users ORDER BY zzz');
        $this->assertStringContainsString('ORDER BY references unknown column: zzz', $result);

        $result = $this->query('SELECT u.id AS a FROM users u JOIN orders o ON u.id = o.user_id ORDER BY nosuch');
        $this->assertStringContainsString('ORDER BY references unknown column: nosuch', $result);

        // The first branch's result column still sorts the whole set result.
        $expected = <<<'CSV'
x
4
3
3
2
2
1
1
CSV;
        $this->assertSame(
            $expected,
            $this->query('SELECT id AS x FROM users UNION ALL SELECT id AS y FROM products ORDER BY x DESC')
        );
    }

    /**
     * An unqualified column that exists in more than one joined table is
     * ambiguous. It used to resolve to whichever table came first in FROM, so
     * the same query returned a row or no rows depending on join order.
     */
    public function testAmbiguousColumnAcrossJoinIsRejected(): void
    {
        $result = $this->query("SELECT u.id AS a FROM users u JOIN products p ON u.id = p.id WHERE name = 'Alice'");
        $this->assertStringContainsString('Ambiguous column name: name', $result);

        $result = $this->query("SELECT u.id AS a FROM products p JOIN users u ON u.id = p.id WHERE name = 'Alice'");
        $this->assertStringContainsString('Ambiguous column name: name', $result);

        $result = $this->query("SELECT u.id AS a FROM users u JOIN products p ON u.id = p.id WHERE name = 'Widget'");
        $this->assertStringContainsString('Ambiguous column name: name', $result);

        $result = $this->query('SELECT u.id AS a FROM users u JOIN products p ON u.id = p.id ORDER BY id');
        $this->assertStringContainsString('Ambiguous column name: id', $result);

        // Unambiguous unqualified columns still resolve.
        $expected = <<<'CSV'
a
1
2
CSV;
        $this->assertSame(
            $expected,
            $this->query('SELECT u.id AS a FROM users u JOIN orders o ON u.id = o.user_id WHERE quantity > 1 ORDER BY a')
        );
    }

    /**
     * INTERSECT ALL / EXCEPT ALL are multiset operations: a row value present
     * m times on the left and n times on the right appears min(m, n) times
     * (INTERSECT ALL) or max(m - n, 0) times (EXCEPT ALL). They used to reuse
     * the semi-/anti-join wrappers behind the DISTINCT forms, which answered
     * "all m" or "none" instead.
     */
    public function testIntersectAllAndExceptAllUseMultisetSemantics(): void
    {
        $doubled = '(SELECT id AS x FROM users UNION ALL SELECT id AS x FROM users) t';

        // min(2, 1) = 1 occurrence of each value
        $expected = <<<'CSV'
x
1
2
3
CSV;
        $this->assertSame(
            $expected,
            $this->query("SELECT x FROM $doubled INTERSECT ALL SELECT id AS x FROM users ORDER BY x")
        );

        // max(2 - 1, 0) = 1 occurrence of each value
        $this->assertSame(
            $expected,
            $this->query("SELECT x FROM $doubled EXCEPT ALL SELECT id AS x FROM users ORDER BY x")
        );

        // min(1, 2) = 1 occurrence of each value
        $this->assertSame(
            $expected,
            $this->query("SELECT id AS x FROM users INTERSECT ALL SELECT x FROM $doubled ORDER BY x")
        );

        // NULL groups with NULL in set operations, and only one of the two
        // NULL phones is cancelled by the single NULL on the right.
        $expected = <<<'CSV'
x

555-0001
555-0002
555-0005
CSV;
        $this->assertSame(
            $expected,
            $this->query('SELECT phone AS x FROM contacts EXCEPT ALL SELECT phone AS x FROM contacts WHERE id = 3 ORDER BY x')
        );
    }

    /**
     * Join keys went straight into a PHP array as hash-table keys, and PHP
     * truncates float array keys to int. 4.99 and 4.995 both became key 4 and
     * joined, and very large floats emitted "not representable as an int"
     * warnings while colliding on garbage keys.
     */
    public function testJoinOnFloatColumnDoesNotTruncateKeys(): void
    {
        // No price equals half of any price, so this join has no matches.
        $result = $this->query(
            'SELECT p.id AS a, d.id AS b FROM products p '
            . 'JOIN (SELECT id, price / 2.0 AS v FROM products) d ON p.price = d.v ORDER BY a, b'
        );
        $this->assertSame('', $result, 'float join keys must not truncate to int');

        // Integral floats must still join against integers (SQL: 1 = 1.0).
        // The sort-merge path advanced its cursors with `<`/`>` but matched
        // with `===`, so int 1 and float 1.0 were ordered equal yet never
        // paired, and this join silently returned nothing.
        $expected = <<<'CSV'
a,b
1,1
2,2
3,3
CSV;
        $this->assertSame(
            $expected,
            $this->query(
                'SELECT u.id AS a, d.id AS b FROM users u '
                . 'JOIN (SELECT id, id * 1.0 AS v FROM users) d ON u.id = d.v ORDER BY a, b'
            )
        );

        // Large fibonacci values overflow to float; the join must be silent
        // and exact (0, 1 and 144 are the values shared with the squares).
        $result = $this->query('SELECT COUNT(*) AS c FROM sequence s JOIN fibonacci f ON s.value = f.value');
        $this->assertSame("c\n3", $result, 'no int-cast warnings from float join keys');
    }
};

exit($test->run());
