<?php
/**
 * Test PartialQuery::withCTE() implementation
 *
 * Tests: CTE addition, CTE chaining/stacking, internal CTE hiding
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Test;
use mini\Database\PDODatabase;

$test = new class extends Test {

    private PDODatabase $db;

    protected function setUp(): void
    {
        \mini\bootstrap();
        $pdo = new PDO('sqlite::memory:');
        $this->db = new PDODatabase($pdo);
    }

    public function testWithCTEAddsSimpleCTE(): void
    {
        $q = $this->db->query('SELECT * FROM users WHERE age >= 18');
        $q = $q->withCTE('users', $this->db->query('SELECT * FROM users WHERE active = 1'));

        $sql = (string) $q;
        $this->assertStringContainsString('WITH users AS', $sql);
        $this->assertStringContainsString('SELECT * FROM users WHERE active = 1', $sql);
        $this->assertStringContainsString('SELECT * FROM users WHERE age >= 18', $sql);
    }

    public function testWithCTEChainsTwoSameNameCTEs(): void
    {
        $q = $this->db->query('SELECT * FROM users WHERE age >= 18');
        $q = $q->withCTE('users', $this->db->query('SELECT * FROM users WHERE age <= 67'));
        $q = $q->withCTE('users', $this->db->query('SELECT * FROM users WHERE gender = "male"'));

        $sql = (string) $q;

        // Should have renamed first CTE to _cte_*
        $this->assertTrue(preg_match('/_cte_\d+/', $sql) === 1, 'Should have renamed CTE');

        // New CTE should reference the renamed one
        $this->assertTrue(
            preg_match('/users AS \(SELECT \* FROM _cte_\d+ WHERE gender/', $sql) === 1,
            'New CTE should reference renamed CTE'
        );

        // Original baseSql should still reference 'users'
        $this->assertStringContainsString('SELECT * FROM users WHERE age >= 18', $sql);
    }

    public function testWithCTEChainsThreeSameNameCTEs(): void
    {
        $q = $this->db->query('SELECT * FROM users WHERE age >= 18');
        $q = $q->withCTE('users', $this->db->query('SELECT * FROM users WHERE age <= 67'));
        $q = $q->withCTE('users', $this->db->query('SELECT * FROM users WHERE gender = "male"'));
        $q = $q->withCTE('users', $this->db->query('SELECT * FROM users WHERE is_logged_in = 1'));

        $sql = (string) $q;

        // Should have two renamed CTEs
        preg_match_all('/_cte_\d+/', $sql, $matches);
        $uniqueCtes = array_unique($matches[0]);
        $this->assertSame(2, count($uniqueCtes), 'Should have exactly 2 renamed CTEs');

        // Final 'users' CTE should reference the second renamed CTE
        $this->assertTrue(
            preg_match('/users AS \(SELECT \* FROM _cte_\d+ WHERE is_logged_in/', $sql) === 1,
            'Final CTE should reference renamed CTE'
        );
    }

    public function testWithCTEDifferentNamesDoNotConflict(): void
    {
        $q = $this->db->query('SELECT u.*, p.* FROM users u JOIN posts p ON u.id = p.user_id');
        $q = $q->withCTE('users', $this->db->query('SELECT * FROM users WHERE active = 1'));
        $q = $q->withCTE('posts', $this->db->query('SELECT * FROM posts WHERE published = 1'));

        $sql = (string) $q;

        // Both CTEs should exist with their original names
        $this->assertStringContainsString('users AS (SELECT * FROM users WHERE active = 1)', $sql);
        $this->assertStringContainsString('posts AS (SELECT * FROM posts WHERE published = 1)', $sql);

        // No renamed CTEs needed
        $this->assertFalse(preg_match('/_cte_\d+/', $sql) === 1, 'Should not have renamed CTEs');
    }

    public function testWithCTEPreservesParameters(): void
    {
        $q = $this->db->query('SELECT * FROM users WHERE age >= ?', [18]);
        $q = $q->withCTE('users', $this->db->query('SELECT * FROM users WHERE status = ?', ['active']));

        $sql = (string) $q;

        // Parameters should be interpolated in __toString()
        $this->assertStringContainsString("'active'", $sql);
        $this->assertStringContainsString('18', $sql);
    }

    public function testWithCTEImmutability(): void
    {
        $q1 = $this->db->query('SELECT * FROM users');
        $q2 = $q1->withCTE('users', $this->db->query('SELECT * FROM users WHERE active = 1'));

        $sql1 = (string) $q1;
        $sql2 = (string) $q2;

        // Original should not have CTE
        $this->assertStringNotContainsString('WITH', $sql1);

        // New query should have CTE
        $this->assertStringContainsString('WITH', $sql2);
    }

    public function testWithCTEThrowsOnDifferentDatabase(): void
    {
        $pdo2 = new PDO('sqlite::memory:');
        $db2 = new PDODatabase($pdo2);

        $q1 = $this->db->query('SELECT * FROM users');
        $q2 = $db2->query('SELECT * FROM users WHERE active = 1');

        $this->assertThrows(function() use ($q1, $q2) {
            $q1->withCTE('users', $q2);
        }, \InvalidArgumentException::class);
    }

    public function testWithCTEFilterChainProducesCorrectResult(): void
    {
        // This test verifies the logical structure of the CTE chain
        $q = $this->db->query('SELECT * FROM users WHERE age >= 18');
        $q = $q->withCTE('users', $this->db->query('SELECT * FROM users WHERE age <= 67'));
        $q = $q->withCTE('users', $this->db->query('SELECT * FROM users WHERE gender = "male"'));

        $sql = (string) $q;

        // The structure should be:
        // WITH _cte_N AS (SELECT * FROM users WHERE age <= 67),    -- innermost: real table
        //      users AS (SELECT * FROM _cte_N WHERE gender = "male")  -- wraps _cte_N
        // SELECT * FROM users WHERE age >= 18                       -- uses outer 'users'

        // First CTE should reference real 'users' table (age filter)
        $this->assertTrue(
            preg_match('/_cte_\d+ AS \(SELECT \* FROM users WHERE age <= 67\)/', $sql) === 1,
            'First CTE should filter by age from real table'
        );

        // Second CTE 'users' should reference the renamed CTE (gender filter)
        $this->assertTrue(
            preg_match('/users AS \(SELECT \* FROM _cte_\d+ WHERE gender/', $sql) === 1,
            'Second CTE should filter by gender from renamed CTE'
        );

        // Main query references 'users' (the outer CTE)
        $this->assertStringEndsWith('SELECT * FROM users WHERE age >= 18', $sql);
    }

    public function testWithCTEAndInSubquery(): void
    {
        // CTE shadows 'users' table - subquery in in() should use the CTE
        $q = $this->db->query('SELECT * FROM groups')
            ->in('admin_id', $this->db->query('SELECT id FROM users WHERE age >= 18'))
            ->withCTE('users', $this->db->query('SELECT * FROM users WHERE age <= 67'));

        $sql = (string) $q;

        // Should have CTE definition
        $this->assertStringContainsString('WITH users AS (SELECT * FROM users WHERE age <= 67)', $sql);

        // Main query should select from groups with IN subquery
        $this->assertStringContainsString('SELECT * FROM groups', $sql);
        $this->assertStringContainsString('"admin_id" IN (SELECT id FROM users WHERE age >= 18)', $sql);

        // The subquery references 'users' which now resolves to the CTE
        // (SQL semantics - CTE shadows the table name)
    }

    public function testWithCTEChainAndInSubquery(): void
    {
        // Chain two CTEs, then use in() - subquery should see outermost CTE
        $q = $this->db->query('SELECT * FROM groups')
            ->in('admin_id', $this->db->query('SELECT id FROM users WHERE role = "admin"'))
            ->withCTE('users', $this->db->query('SELECT * FROM users WHERE age <= 67'))
            ->withCTE('users', $this->db->query('SELECT * FROM users WHERE active = 1'));

        $sql = (string) $q;

        // Should have renamed CTE and final 'users' CTE
        $this->assertTrue(preg_match('/_cte_\d+/', $sql) === 1, 'Should have renamed CTE');

        // Inner CTE filters by age from real table
        $this->assertTrue(
            preg_match('/_cte_\d+ AS \(SELECT \* FROM users WHERE age <= 67\)/', $sql) === 1,
            'Inner CTE should filter by age'
        );

        // Outer 'users' CTE filters by active from inner CTE
        $this->assertTrue(
            preg_match('/users AS \(SELECT \* FROM _cte_\d+ WHERE active = 1\)/', $sql) === 1,
            'Outer CTE should filter by active from inner CTE'
        );

        // IN subquery references 'users' (the outermost CTE)
        $this->assertStringContainsString('"admin_id" IN (SELECT id FROM users WHERE role =', $sql);
    }

    public function testWithCTEMultipleTablesAndSubqueries(): void
    {
        // Multiple CTEs for different tables, with subqueries referencing each
        $q = $this->db->query('SELECT * FROM orders')
            ->in('user_id', $this->db->query('SELECT id FROM users WHERE active = 1'))
            ->in('product_id', $this->db->query('SELECT id FROM products WHERE in_stock = 1'))
            ->withCTE('users', $this->db->query('SELECT * FROM users WHERE verified = 1'))
            ->withCTE('products', $this->db->query('SELECT * FROM products WHERE published = 1'));

        $sql = (string) $q;

        // Both CTEs should exist
        $this->assertStringContainsString('users AS (SELECT * FROM users WHERE verified = 1)', $sql);
        $this->assertStringContainsString('products AS (SELECT * FROM products WHERE published = 1)', $sql);

        // Both IN subqueries should be present
        $this->assertStringContainsString('"user_id" IN (SELECT id FROM users WHERE active = 1)', $sql);
        $this->assertStringContainsString('"product_id" IN (SELECT id FROM products WHERE in_stock = 1)', $sql);

        // No renamed CTEs (different names don't conflict)
        $this->assertFalse(preg_match('/_cte_\d+/', $sql) === 1, 'Should not have renamed CTEs');
    }

    public function testWithCTESubqueryInCTEDefinition(): void
    {
        // CTE definition itself contains a subquery
        $cteQuery = $this->db->query('SELECT * FROM users WHERE dept_id IN (SELECT id FROM departments WHERE active = 1)');
        $q = $this->db->query('SELECT * FROM users WHERE age >= 18')
            ->withCTE('users', $cteQuery);

        $sql = (string) $q;

        // CTE should contain the nested subquery
        $this->assertStringContainsString(
            'users AS (SELECT * FROM users WHERE dept_id IN (SELECT id FROM departments WHERE active = 1))',
            $sql
        );
    }

    public function testWithCTEPreservesSubqueryParameters(): void
    {
        $q = $this->db->query('SELECT * FROM groups WHERE status = ?', ['active'])
            ->in('admin_id', $this->db->query('SELECT id FROM users WHERE role = ?', ['admin']))
            ->withCTE('users', $this->db->query('SELECT * FROM users WHERE level >= ?', [5]));

        $sql = (string) $q;

        // All parameters should be interpolated
        $this->assertStringContainsString("'active'", $sql);
        $this->assertStringContainsString("'admin'", $sql);
        $this->assertStringContainsString('5', $sql);
    }

    public function testWithCTEOrderOfOperations(): void
    {
        // Verify that adding CTE after in() works correctly
        // (CTE is prepended, doesn't change subquery SQL)
        $baseQuery = $this->db->query('SELECT * FROM groups');
        $withIn = $baseQuery->in('admin_id', $this->db->query('SELECT id FROM users'));
        $withCte = $withIn->withCTE('users', $this->db->query('SELECT * FROM users WHERE active = 1'));

        $sqlWithIn = (string) $withIn;
        $sqlWithCte = (string) $withCte;

        // Without CTE - no WITH clause
        $this->assertStringNotContainsString('WITH', $sqlWithIn);

        // With CTE - has WITH clause
        $this->assertStringContainsString('WITH users AS', $sqlWithCte);

        // Both have the IN subquery
        $this->assertStringContainsString('"admin_id" IN (SELECT id FROM users)', $sqlWithIn);
        $this->assertStringContainsString('"admin_id" IN (SELECT id FROM users)', $sqlWithCte);
    }
};

exit($test->run());
