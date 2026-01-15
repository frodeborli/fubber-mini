<?php
/**
 * Test PartialQuery::withCTE() implementation
 *
 * Tests: CTE addition, CTE conflict detection
 * Note: CTE shadowing (redefining same name) is NOT supported
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

    public function testWithCTEThrowsOnSameNameCTE(): void
    {
        $q = $this->db->query('SELECT * FROM users WHERE age >= 18');
        $q = $q->withCTE('users', $this->db->query('SELECT * FROM users WHERE age <= 67'));

        $this->assertThrows(function() use ($q) {
            $q->withCTE('users', $this->db->query('SELECT * FROM users WHERE gender = "male"'));
        }, \LogicException::class, 'CTE shadowing is not supported');
    }

    public function testWithCTEThrowsWhenSourceQueryHasSameNameCTE(): void
    {
        $q = $this->db->query('SELECT * FROM users WHERE age >= 18');

        // Source query has a CTE named 'users'
        $sourceWithCte = $this->db->query('SELECT * FROM users WHERE active = 1')
            ->withCTE('users', $this->db->query('SELECT * FROM raw_users'));

        $this->assertThrows(function() use ($q, $sourceWithCte) {
            $q->withCTE('users', $sourceWithCte);
        }, \LogicException::class, 'CTE shadowing is not supported');
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
        $this->assertStringContainsString('admin_id IN (SELECT id FROM users WHERE age >= 18)', $sql);

        // The subquery references 'users' which now resolves to the CTE
        // (SQL semantics - CTE shadows the table name)
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
        $this->assertStringContainsString('user_id IN (SELECT id FROM users WHERE active = 1)', $sql);
        $this->assertStringContainsString('product_id IN (SELECT id FROM products WHERE in_stock = 1)', $sql);

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
        $this->assertStringContainsString('admin_id IN (SELECT id FROM users)', $sqlWithIn);
        $this->assertStringContainsString('admin_id IN (SELECT id FROM users)', $sqlWithCte);
    }

    public function testWithCTEMergesCTEsFromSourceQuery(): void
    {
        // Source query has its own CTE with a different name
        $source = $this->db->query('SELECT * FROM filtered_users')
            ->withCTE('filtered_users', $this->db->query('SELECT * FROM users WHERE active = 1'));

        $q = $this->db->query('SELECT * FROM results')
            ->withCTE('results', $source);

        $sql = (string) $q;

        // Both CTEs should be present
        $this->assertStringContainsString('filtered_users AS', $sql);
        $this->assertStringContainsString('results AS', $sql);
    }
};

exit($test->run());
