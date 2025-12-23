<?php
/**
 * VirtualDatabase Verified Queries - CLI Integration Tests
 *
 * These tests run actual CLI queries against `bin/mini vdb` and compare
 * the output to known-good results. This freezes working query behavior.
 *
 * Run with: bin/mini test tests/Database/VirtualDatabase.VerifiedQueries.php
 *
 * KNOWN UNSUPPORTED FEATURES (as of 2025-12-22):
 * - Reserved words as aliases (DESC, ASC, etc.) - use different alias names
 * - CTEs / WITH clause
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use mini\Test;

$test = new class extends Test {

    private function query(string $sql, string $format = 'csv'): string
    {
        $cmd = sprintf(
            'bin/mini vdb --format=%s %s 2>&1',
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
};

exit($test->run());
