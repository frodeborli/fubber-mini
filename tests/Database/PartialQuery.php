<?php
/**
 * Test PartialQuery implementation
 *
 * Tests: query building, WHERE methods, ORDER/LIMIT/OFFSET, hydration, ResultSetInterface
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Test;
use mini\Database\PartialQuery;
use mini\Database\Query;
use mini\Database\ResultSetInterface;

class TestEntity {
    public int $id;
    public string $name;
}

$test = new class extends Test {

    private static ?\ReflectionProperty $pqRef = null;

    /**
     * Extract the internal PartialQuery from a Query via reflection
     */
    private function pq(Query $query): PartialQuery
    {
        if (self::$pqRef === null) {
            self::$pqRef = new \ReflectionProperty(Query::class, 'pq');
        }
        return self::$pqRef->getValue($query);
    }

    protected function setUp(): void
    {
        // Bootstrap to get db() available
        \mini\bootstrap();
    }

    public function testImplementsResultSetInterface(): void
    {
        $pq = $this->pq(\mini\db()->query('SELECT * FROM users'));
        $this->assertInstanceOf(ResultSetInterface::class, $pq);
    }

    public function testSimpleTableQuery(): void
    {
        $pq = \mini\db()->query('SELECT * FROM users');
        $sql = (string) $pq;

        $this->assertContains('SELECT * FROM users', $sql);
        // No default LIMIT in string form - only applied on materialization
        $this->assertFalse(str_contains($sql, 'LIMIT'), 'Should not have LIMIT in string form');
    }

    public function testExplicitLimitInString(): void
    {
        $pq = \mini\db()->query('SELECT * FROM users')->limit(50);
        $sql = (string) $pq;

        $this->assertContains('LIMIT 50', $sql);
    }

    public function testEqAddsWhereClause(): void
    {
        $pq = \mini\db()->query('SELECT * FROM users')->eq('id', 1);
        $sql = (string) $pq;

        $this->assertContains('WHERE', $sql);
        $this->assertContains('id', $sql);
        $this->assertContains('= ', $sql);
    }

    public function testEqWithNullUsesIsNull(): void
    {
        $pq = \mini\db()->query('SELECT * FROM users')->eq('deleted_at', null);
        $sql = (string) $pq;

        $this->assertContains('deleted_at IS NULL', $sql);
    }

    public function testLtAddsLessThan(): void
    {
        $pq = \mini\db()->query('SELECT * FROM users')->lt('age', 18);
        $sql = (string) $pq;

        $this->assertContains('age', $sql);
        $this->assertContains('<', $sql);
    }

    public function testLteAddsLessThanOrEqual(): void
    {
        $pq = \mini\db()->query('SELECT * FROM users')->lte('age', 18);
        $sql = (string) $pq;

        $this->assertContains('age', $sql);
        $this->assertContains('<=', $sql);
    }

    public function testGtAddsGreaterThan(): void
    {
        $pq = \mini\db()->query('SELECT * FROM users')->gt('age', 18);
        $sql = (string) $pq;

        $this->assertContains('age', $sql);
        $this->assertContains('>', $sql);
    }

    public function testGteAddsGreaterThanOrEqual(): void
    {
        $pq = \mini\db()->query('SELECT * FROM users')->gte('age', 18);
        $sql = (string) $pq;

        $this->assertContains('age', $sql);
        $this->assertContains('>=', $sql);
    }

    public function testInWithArray(): void
    {
        $pq = \mini\db()->query('SELECT * FROM users')->in('id', [1, 2, 3]);
        $sql = (string) $pq;

        $this->assertContains('id', $sql);
        $this->assertContains('IN', $sql);
    }

    public function testInWithEmptyArrayReturnsFalseCondition(): void
    {
        $pq = \mini\db()->query('SELECT * FROM users')->in('id', []);
        $sql = (string) $pq;

        $this->assertContains('1 = 0', $sql);
    }

    public function testWhereAddsRawClause(): void
    {
        $pq = \mini\db()->query('SELECT * FROM users')->where('active = ? AND role = ?', [1, 'admin']);
        $sql = (string) $pq;

        $this->assertContains('active =', $sql);
        $this->assertContains('role =', $sql);
        $this->assertContains('admin', $sql);
    }

    public function testMultipleWhereClausesAreAnded(): void
    {
        $pq = \mini\db()->query('SELECT * FROM users')
            ->eq('active', 1)
            ->eq('role', 'admin');
        $sql = (string) $pq;

        $this->assertContains('active', $sql);
        $this->assertContains('AND', $sql);
        $this->assertContains('role', $sql);
    }

    public function testOrderBySetsOrderClause(): void
    {
        $pq = \mini\db()->query('SELECT * FROM users')->order('created_at DESC');
        $sql = (string) $pq;

        $this->assertContains('ORDER BY created_at DESC', $sql);
    }

    public function testLimitSetsLimit(): void
    {
        $pq = \mini\db()->query('SELECT * FROM users')->limit(10);
        $sql = (string) $pq;

        $this->assertContains('LIMIT 10', $sql);
    }

    public function testOffsetSetsOffset(): void
    {
        $pq = \mini\db()->query('SELECT * FROM users')->offset(20)->limit(10);
        $sql = (string) $pq;

        $this->assertContains('LIMIT 10 OFFSET 20', $sql);
    }

    public function testSelectOverridesColumns(): void
    {
        $pq = $this->pq(\mini\db()->query('SELECT * FROM users'))->select('id, name');
        $sql = (string) $pq;

        // When composing, base query is wrapped as subquery
        $this->assertContains('SELECT id, name FROM', $sql);
        $this->assertContains('SELECT * FROM users', $sql);
    }

    public function testImmutability(): void
    {
        $pq1 = \mini\db()->query('SELECT * FROM users');
        $pq2 = $pq1->eq('id', 1);
        $pq3 = $pq1->eq('id', 2);

        // All should be different instances
        $this->assertFalse($pq1 === $pq2);
        $this->assertFalse($pq2 === $pq3);

        // Original should not have WHERE
        $sql1 = (string) $pq1;
        $this->assertFalse(str_contains($sql1, 'WHERE'));

        // pq2 should have id = '1' (quoted)
        $sql2 = (string) $pq2;
        $this->assertContains("'1'", $sql2);

        // pq3 should have id = '2' (quoted)
        $sql3 = (string) $pq3;
        $this->assertContains("'2'", $sql3);
    }

    public function testWithEntityClass(): void
    {
        // Create a test table with data
        \mini\db()->exec('CREATE TABLE IF NOT EXISTS test_entities (id INTEGER PRIMARY KEY, name TEXT)');
        \mini\db()->exec('DELETE FROM test_entities');
        \mini\db()->exec("INSERT INTO test_entities (id, name) VALUES (1, 'Test')");

        $query = $this->pq(\mini\db()->query('SELECT * FROM test_entities'))
            ->withEntityClass(TestEntity::class);

        $this->assertSame(1, $query->count());
        foreach ($query as $entity) {
            $this->assertInstanceOf(TestEntity::class, $entity);
            $this->assertSame(1, $entity->id);
            $this->assertSame('Test', $entity->name);
        }

        // Cleanup
        \mini\db()->exec('DROP TABLE test_entities');
    }

    public function testOne(): void
    {
        \mini\db()->exec('CREATE TABLE IF NOT EXISTS test_one (id INTEGER PRIMARY KEY, name TEXT)');
        \mini\db()->exec('DELETE FROM test_one');
        \mini\db()->exec("INSERT INTO test_one (id, name) VALUES (1, 'First'), (2, 'Second')");

        $row = \mini\db()->query('SELECT * FROM test_one')->order('id')->one();

        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row->id);
        $this->assertSame('First', $row->name);

        \mini\db()->exec('DROP TABLE test_one');
    }

    public function testOneReturnsNullWhenNoResults(): void
    {
        \mini\db()->exec('CREATE TABLE IF NOT EXISTS test_one_empty (id INTEGER PRIMARY KEY)');
        \mini\db()->exec('DELETE FROM test_one_empty');

        $row = \mini\db()->query('SELECT * FROM test_one_empty')->one();

        $this->assertNull($row);

        \mini\db()->exec('DROP TABLE test_one_empty');
    }

    public function testColumn(): void
    {
        \mini\db()->exec('CREATE TABLE IF NOT EXISTS test_column (id INTEGER PRIMARY KEY, name TEXT)');
        \mini\db()->exec('DELETE FROM test_column');
        \mini\db()->exec("INSERT INTO test_column (id, name) VALUES (1, 'A'), (2, 'B'), (3, 'C')");

        $ids = $this->pq(\mini\db()->query('SELECT * FROM test_column')->order('id'))->column();

        $this->assertCount(3, $ids);
        $this->assertEquals([1, 2, 3], $ids); // Use assertEquals for loose comparison

        \mini\db()->exec('DROP TABLE test_column');
    }

    public function testField(): void
    {
        \mini\db()->exec('CREATE TABLE IF NOT EXISTS test_field (id INTEGER PRIMARY KEY, name TEXT)');
        \mini\db()->exec('DELETE FROM test_field');
        \mini\db()->exec("INSERT INTO test_field (id, name) VALUES (1, 'Test')");

        $id = $this->pq(\mini\db()->query('SELECT * FROM test_field'))->field();

        $this->assertEquals(1, $id); // Use assertEquals for loose comparison

        \mini\db()->exec('DROP TABLE test_field');
    }

    public function testCount(): void
    {
        \mini\db()->exec('CREATE TABLE IF NOT EXISTS test_count (id INTEGER PRIMARY KEY)');
        \mini\db()->exec('DELETE FROM test_count');
        \mini\db()->exec('INSERT INTO test_count (id) VALUES (1), (2), (3), (4), (5)');

        $count = \mini\db()->query('SELECT * FROM test_count')->count();

        $this->assertSame(5, $count);

        // Count with WHERE
        $count = \mini\db()->query('SELECT * FROM test_count')->gt('id', 2)->count();
        $this->assertSame(3, $count);

        \mini\db()->exec('DROP TABLE test_count');
    }

    public function testJsonSerialize(): void
    {
        \mini\db()->exec('CREATE TABLE IF NOT EXISTS test_json (id INTEGER PRIMARY KEY, name TEXT)');
        \mini\db()->exec('DELETE FROM test_json');
        \mini\db()->exec("INSERT INTO test_json (id, name) VALUES (1, 'Test')");

        $json = json_encode($this->pq(\mini\db()->query('SELECT * FROM test_json')));

        $this->assertContains('"id":', $json);
        $this->assertContains('"name":"Test"', $json);

        \mini\db()->exec('DROP TABLE test_json');
    }

    public function testComplexSqlWithTable(): void
    {
        \mini\db()->exec('CREATE TABLE IF NOT EXISTS test_complex (id INTEGER PRIMARY KEY, status TEXT)');
        \mini\db()->exec('DELETE FROM test_complex');
        \mini\db()->exec("INSERT INTO test_complex (id, status) VALUES (1, 'active'), (2, 'inactive')");

        // Complex SQL wrapped as subquery
        $pq = \mini\db()->query(
            'SELECT * FROM test_complex WHERE status = ?',
            ['active']
        )->eq('id', 1);

        $row = $pq->one();

        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row->id);

        \mini\db()->exec('DROP TABLE test_complex');
    }

    // -------------------------------------------------------------------------
    // matches() and getPredicate() tests
    // -------------------------------------------------------------------------

    public function testMatchesWithNoConditions(): void
    {
        $pq = $this->pq(\mini\db()->query('SELECT * FROM users'));
        $row = (object)['id' => 1, 'name' => 'Test'];

        // No conditions = matches everything
        $this->assertTrue($pq->matches($row));
    }

    public function testMatchesWithEq(): void
    {
        $pq = $this->pq(\mini\db()->query('SELECT * FROM users')->eq('id', 1));

        $this->assertTrue($pq->matches((object)['id' => 1, 'name' => 'Test']));
        $this->assertFalse($pq->matches((object)['id' => 2, 'name' => 'Other']));
    }

    public function testMatchesWithEqNull(): void
    {
        $pq = $this->pq(\mini\db()->query('SELECT * FROM users')->eq('deleted_at', null));

        $this->assertTrue($pq->matches((object)['id' => 1, 'deleted_at' => null]));
        $this->assertFalse($pq->matches((object)['id' => 2, 'deleted_at' => '2024-01-01']));
    }

    public function testMatchesWithLt(): void
    {
        $pq = $this->pq(\mini\db()->query('SELECT * FROM users')->lt('age', 18));

        $this->assertTrue($pq->matches((object)['id' => 1, 'age' => 15]));
        $this->assertFalse($pq->matches((object)['id' => 2, 'age' => 18]));
        $this->assertFalse($pq->matches((object)['id' => 3, 'age' => 25]));
    }

    public function testMatchesWithLte(): void
    {
        $pq = $this->pq(\mini\db()->query('SELECT * FROM users')->lte('age', 18));

        $this->assertTrue($pq->matches((object)['id' => 1, 'age' => 15]));
        $this->assertTrue($pq->matches((object)['id' => 2, 'age' => 18]));
        $this->assertFalse($pq->matches((object)['id' => 3, 'age' => 25]));
    }

    public function testMatchesWithGt(): void
    {
        $pq = $this->pq(\mini\db()->query('SELECT * FROM users')->gt('age', 18));

        $this->assertFalse($pq->matches((object)['id' => 1, 'age' => 15]));
        $this->assertFalse($pq->matches((object)['id' => 2, 'age' => 18]));
        $this->assertTrue($pq->matches((object)['id' => 3, 'age' => 25]));
    }

    public function testMatchesWithGte(): void
    {
        $pq = $this->pq(\mini\db()->query('SELECT * FROM users')->gte('age', 18));

        $this->assertFalse($pq->matches((object)['id' => 1, 'age' => 15]));
        $this->assertTrue($pq->matches((object)['id' => 2, 'age' => 18]));
        $this->assertTrue($pq->matches((object)['id' => 3, 'age' => 25]));
    }

    public function testMatchesWithLike(): void
    {
        $pq = $this->pq(\mini\db()->query('SELECT * FROM users')->like('name', 'A%'));

        $this->assertTrue($pq->matches((object)['id' => 1, 'name' => 'Alice']));
        $this->assertTrue($pq->matches((object)['id' => 2, 'name' => 'Andrew']));
        $this->assertFalse($pq->matches((object)['id' => 3, 'name' => 'Bob']));
    }

    public function testMatchesWithMultipleConditions(): void
    {
        $pq = $this->pq(\mini\db()->query('SELECT * FROM users')
            ->eq('org_id', 123)
            ->eq('active', 1)
            ->gt('age', 18));

        $this->assertTrue($pq->matches((object)['id' => 1, 'org_id' => 123, 'active' => 1, 'age' => 25]));
        $this->assertFalse($pq->matches((object)['id' => 2, 'org_id' => 123, 'active' => 0, 'age' => 25]));
        $this->assertFalse($pq->matches((object)['id' => 3, 'org_id' => 456, 'active' => 1, 'age' => 25]));
        $this->assertFalse($pq->matches((object)['id' => 4, 'org_id' => 123, 'active' => 1, 'age' => 15]));
    }

    public function testQueryImmutability(): void
    {
        $pq1 = $this->pq(\mini\db()->query('SELECT * FROM users'));
        $pq2 = $this->pq(\mini\db()->query('SELECT * FROM users')->eq('id', 1));
        $pq3 = $this->pq(\mini\db()->query('SELECT * FROM users')->eq('id', 2));

        $row1 = (object)['id' => 1];
        $row2 = (object)['id' => 2];

        // pq1 should match everything (no conditions)
        $this->assertTrue($pq1->matches($row1));
        $this->assertTrue($pq1->matches($row2));

        // pq2 should only match id=1
        $this->assertTrue($pq2->matches($row1));
        $this->assertFalse($pq2->matches($row2));

        // pq3 should only match id=2
        $this->assertFalse($pq3->matches($row1));
        $this->assertTrue($pq3->matches($row2));
    }

    public function testMatchesWithMissingColumnThrows(): void
    {
        $pq = $this->pq(\mini\db()->query('SELECT * FROM users')->eq('status', 'active'));

        // AST-based evaluation is strict - missing columns throw
        $this->assertThrows(
            fn() => $pq->matches((object)['id' => 1, 'name' => 'Test']),
            \RuntimeException::class
        );
    }

    // -------------------------------------------------------------------------
    // Base SQL WHERE matching tests
    // -------------------------------------------------------------------------

    public function testMatchesWithBaseSqlWhere(): void
    {
        // Query with WHERE in base SQL
        $pq = $this->pq(\mini\db()->query('SELECT * FROM users WHERE status = ?', ['active']));

        // Row matches base SQL WHERE
        $this->assertTrue($pq->matches((object)['id' => 1, 'status' => 'active']));

        // Row doesn't match base SQL WHERE
        $this->assertFalse($pq->matches((object)['id' => 2, 'status' => 'inactive']));
    }

    public function testMatchesWithBaseSqlWhereAndPredicateCondition(): void
    {
        // Query with WHERE in base SQL AND chained condition
        $pq = $this->pq(\mini\db()->query('SELECT * FROM users WHERE gender = ?', ['male'])
            ->gte('age', 18));

        // Matches both base SQL and predicate
        $this->assertTrue($pq->matches((object)['gender' => 'male', 'age' => 25]));

        // Fails base SQL (wrong gender)
        $this->assertFalse($pq->matches((object)['gender' => 'female', 'age' => 25]));

        // Fails predicate (too young)
        $this->assertFalse($pq->matches((object)['gender' => 'male', 'age' => 15]));
    }

    public function testMatchesWithComplexBaseSqlWhere(): void
    {
        // Query with complex WHERE in base SQL
        $pq = $this->pq(\mini\db()->query('SELECT * FROM users WHERE org_id = ? AND active = 1', [100]));

        // Matches both conditions
        $this->assertTrue($pq->matches((object)['org_id' => 100, 'active' => 1]));

        // Fails org_id
        $this->assertFalse($pq->matches((object)['org_id' => 200, 'active' => 1]));

        // Fails active
        $this->assertFalse($pq->matches((object)['org_id' => 100, 'active' => 0]));
    }

    public function testMatchesWithBaseSqlWhereOrCondition(): void
    {
        // Query with OR in base SQL WHERE
        $pq = $this->pq(\mini\db()->query('SELECT * FROM users WHERE role = ? OR role = ?', ['admin', 'moderator']));

        $this->assertTrue($pq->matches((object)['role' => 'admin']));
        $this->assertTrue($pq->matches((object)['role' => 'moderator']));
        $this->assertFalse($pq->matches((object)['role' => 'user']));
    }

    public function testMatchesWithBaseSqlWhereLike(): void
    {
        // Query with LIKE in base SQL WHERE
        $pq = $this->pq(\mini\db()->query("SELECT * FROM users WHERE name LIKE 'A%'"));

        $this->assertTrue($pq->matches((object)['name' => 'Alice']));
        $this->assertTrue($pq->matches((object)['name' => 'Andrew']));
        $this->assertFalse($pq->matches((object)['name' => 'Bob']));
    }

    // === Window Semantics Tests ===

    public function testLimitCanOnlyNarrow(): void
    {
        $pq = \mini\db()->query('SELECT * FROM users')->limit(10);
        $sql1 = (string) $pq;
        $this->assertContains('LIMIT 10', $sql1);

        // Try to expand - should stay at 10
        $pq2 = $pq->limit(20);
        $sql2 = (string) $pq2;
        $this->assertContains('LIMIT 10', $sql2);

        // Shrink - should become 5
        $pq3 = $pq->limit(5);
        $sql3 = (string) $pq3;
        $this->assertContains('LIMIT 5', $sql3);
    }

    public function testOffsetIsAdditive(): void
    {
        $pq = \mini\db()->query('SELECT * FROM users')->offset(10);
        $sql1 = (string) $pq;
        $this->assertContains('OFFSET 10', $sql1);

        // Add more offset
        $pq2 = $pq->offset(5);
        $sql2 = (string) $pq2;
        $this->assertContains('OFFSET 15', $sql2);
    }

    public function testOffsetReducesLimitToStayInWindow(): void
    {
        $pq = \mini\db()->query('SELECT * FROM users')->limit(10)->offset(3);
        $sql = (string) $pq;

        // Should be LIMIT 7 OFFSET 3 (staying within original 10-row window)
        $this->assertContains('LIMIT 7', $sql);
        $this->assertContains('OFFSET 3', $sql);
    }

    public function testFilterWithPaginationUsesBarrier(): void
    {
        // When filtering after pagination, barrier wraps the paginated query
        $pq = \mini\db()->query('SELECT * FROM users')->limit(10)->eq('active', 1);
        $sql = (string) $pq;

        // Should have subquery structure due to barrier
        $this->assertContains('SELECT', $sql);
        $this->assertContains('FROM (', $sql);
        $this->assertContains('LIMIT 10', $sql);
    }

    // === Column Narrowing Tests ===

    public function testColumnsEnforcesNarrowing(): void
    {
        $pq = \mini\db()->query('SELECT * FROM users')->columns('a', 'b');

        // Narrowing is OK
        $pq2 = $pq->columns('a');
        $sql = (string) $pq2;
        $this->assertContains('SELECT', $sql);

        // Trying to add back 'c' should fail
        $threw = false;
        try {
            $pq->columns('a', 'c');
        } catch (\InvalidArgumentException $e) {
            $threw = true;
            $this->assertContains("'c' is not available", $e->getMessage());
        }
        $this->assertTrue($threw, 'Should throw when trying to access unavailable column');
    }

    public function testSelectWithUnaryOperationValidatesColumns(): void
    {
        // This test catches the UnaryOperation->operand vs ->expression bug
        $pq = $this->pq(\mini\db()->query('SELECT * FROM users')->columns('a', 'b'));

        // Unary expression referencing available column should work
        $pq2 = $pq->select('-a as neg_a');
        $sql = (string) $pq2;
        $this->assertContains('neg_a', $sql);

        // Unary expression referencing unavailable column should fail
        $threw = false;
        try {
            $pq->select('-c as neg_c');
        } catch (\InvalidArgumentException $e) {
            $threw = true;
            $this->assertContains("'c' is not available", $e->getMessage());
        }
        $this->assertTrue($threw, 'Should throw for unary op on unavailable column');
    }

    public function testSelectWithComputedColumnUpdatesAvailable(): void
    {
        $pq = $this->pq(\mini\db()->query('SELECT * FROM users')
            ->columns('a', 'b'))
            ->select('a + b as sum');

        // After select, only 'sum' should be available
        $threw = false;
        try {
            $pq->select('a'); // 'a' no longer available after projection
        } catch (\InvalidArgumentException $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'Original columns should not be available after select');

        // But 'sum' should work
        $pq2 = $pq->columns('sum');
        $sql = (string) $pq2;
        $this->assertContains('sum', $sql);
    }

    // === Predicate IN with SetInterface Test ===

    public function testOrPredicateWithInSetInterface(): void
    {
        // This test catches the SetInterface->columns() bug
        // Create a simple in-memory set
        $set = new \mini\Table\Utility\Set('id', [1, 2, 3]);

        $pq = \mini\db()->query('SELECT * FROM users');
        $p = new \mini\Table\Predicate();
        $pq2 = $pq->or($p->in('id', $set), $p->eq('id', 99));

        $sql = (string) $pq2;
        $this->assertContains('IN', $sql);
        $this->assertContains('1', $sql);
        $this->assertContains('2', $sql);
        $this->assertContains('3', $sql);
    }

    // === Additional Window Semantics Tests ===

    public function testLimitNarrowingChain(): void
    {
        $pq = \mini\db()->query('SELECT * FROM users')
            ->limit(100)
            ->limit(50)
            ->limit(75); // Should stay at 50, not expand to 75

        $sql = (string) $pq;
        $this->assertContains('LIMIT 50', $sql);
    }

    public function testLimitFromUnlimitedCanBeSet(): void
    {
        $pq = \mini\db()->query('SELECT * FROM users');

        // No limit initially - can set any limit
        $limited = $pq->limit(100);
        $sql = (string) $limited;
        $this->assertContains('LIMIT 100', $sql);
    }

    public function testOffsetBeyondLimitGivesZeroLimit(): void
    {
        // Start with limit 10, offset beyond it
        $pq = \mini\db()->query('SELECT * FROM users')
            ->limit(10)
            ->offset(15);

        $sql = (string) $pq;
        $this->assertContains('LIMIT 0', $sql);
        $this->assertContains('OFFSET 15', $sql);
    }

    public function testOffsetWithoutLimitJustAdds(): void
    {
        $pq = \mini\db()->query('SELECT * FROM users')
            ->offset(5)
            ->offset(10);

        $sql = (string) $pq;
        $this->assertContains('OFFSET 15', $sql);
        $this->assertFalse(str_contains($sql, 'LIMIT'), 'Should not have LIMIT');
    }

    // === getSql() Fast/Slow Path ===

    public function testGetSqlFastPathUnmodifiedQuery(): void
    {
        $originalSql = 'SELECT * FROM users WHERE id = ?';
        $pq = $this->pq(\mini\db()->query($originalSql, [42]));

        // Fast path: AST not parsed, returns original SQL
        [$sql, $params] = $pq->getSql();
        $this->assertEquals($originalSql, $sql);
        $this->assertEquals([42], $params);
    }

    public function testGetSqlSlowPathModifiedQuery(): void
    {
        $pq = $this->pq(\mini\db()->query('SELECT * FROM users')
            ->eq('id', 42));

        // Slow path: AST was modified, SQL is rendered
        [$sql, $params] = $pq->getSql();
        $this->assertContains('WHERE', $sql);
        $this->assertContains('id', $sql);
    }

    public function testGetSqlAfterLimitStillReturnsValidSql(): void
    {
        $pq = $this->pq(\mini\db()->query('SELECT * FROM users')
            ->limit(10));

        [$sql, $params] = $pq->getSql();
        $this->assertContains('LIMIT', $sql);
    }

    // === Additional Barrier Tests ===

    public function testInOnPaginatedQueryUsesBarrier(): void
    {
        $pq = \mini\db()->query('SELECT * FROM users')
            ->limit(5)
            ->in('id', [1, 2, 3]);

        $sql = (string) $pq;
        $this->assertContains('FROM (', $sql);
        $this->assertContains('IN', $sql);
    }

    public function testOrOnPaginatedQueryUsesBarrier(): void
    {
        $p = new \mini\Table\Predicate();
        $pq = \mini\db()->query('SELECT * FROM users')
            ->limit(5)
            ->or($p->eq('id', 1), $p->eq('id', 2));

        $sql = (string) $pq;
        $this->assertContains('FROM (', $sql);
    }

    public function testFilterWithoutPaginationNoBarrier(): void
    {
        // When no pagination, filter applies directly (no subquery wrapper)
        $pq = \mini\db()->query('SELECT * FROM users')
            ->eq('status', 'active');

        $sql = (string) $pq;
        // Should NOT have a subquery wrapper
        $this->assertFalse(str_contains($sql, 'FROM ('), 'Should not wrap in subquery without pagination');
        $this->assertContains('WHERE', $sql);
    }

    public function testFilterOnPaginatedQueryPreservesConditions(): void
    {
        // Filtering a query with existing WHERE and pagination should wrap everything
        $pq = \mini\db()->query('SELECT * FROM users WHERE id > 10')
            ->limit(5)
            ->eq('status', 'active');

        $sql = (string) $pq;
        $this->assertContains('FROM (', $sql);
        $this->assertContains('id > 10', $sql);
        $this->assertContains('status', $sql);
    }

    public function testFilterOnPaginatedQueryResetsWindowSemantics(): void
    {
        // After automatic barrier (via filter), limit/offset rules reset for outer query
        $pq = \mini\db()->query('SELECT * FROM users')
            ->limit(10)
            ->offset(5)
            ->eq('status', 'active') // Triggers automatic barrier
            ->limit(100); // This should be 100, not clamped to 10

        $sql = (string) $pq;
        $this->assertContains('FROM (', $sql);
        $this->assertContains('LIMIT 100', $sql); // Outer limit
    }

    // === Additional Column Narrowing Tests ===

    public function testSelectValidatesColumnReferences(): void
    {
        $pq = $this->pq(\mini\db()->query('SELECT * FROM users')
            ->columns('a', 'b'));

        // Can use available columns in expressions
        $valid = $pq->select('a, b, a + b as sum');
        $sql = (string) $valid;
        $this->assertContains('sum', $sql);

        // Cannot reference unavailable columns
        $threw = false;
        try {
            $pq->select('c'); // 'c' not available
        } catch (\InvalidArgumentException $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'Should throw for unavailable column in select');
    }

    public function testUnrestrictedQueryAllowsAnyColumns(): void
    {
        // Before columns() is called, any column is allowed
        $pq = \mini\db()->query('SELECT * FROM users');

        // This should not throw - we don't know what columns exist
        $projected = $pq->columns('anything', 'goes');
        $sql = (string) $projected;
        $this->assertContains('anything', $sql);
        $this->assertContains('goes', $sql);
    }

    public function testColumnsNarrowingPreservesImmutability(): void
    {
        $pq1 = \mini\db()->query('SELECT * FROM users')
            ->columns('id', 'name', 'email');

        // Narrow from pq1
        $pq2 = $pq1->columns('id', 'name');

        // Original pq1 should still have all three available
        $pq3 = $pq1->columns('id', 'email');
        $sql3 = (string) $pq3;
        $this->assertContains('id', $sql3);
        $this->assertContains('email', $sql3);
    }

    // === fromTable() tests ===

    public function testFromTableCreatesQueryablePartialQuery(): void
    {
        $table = new \mini\Table\GeneratorTable(
            fn() => yield from [
                1 => (object)['id' => 1, 'name' => 'Alice', 'status' => 'active'],
                2 => (object)['id' => 2, 'name' => 'Bob', 'status' => 'inactive'],
                3 => (object)['id' => 3, 'name' => 'Carol', 'status' => 'active'],
            ],
            new \mini\Table\ColumnDef('id', \mini\Table\Types\ColumnType::Int, \mini\Table\Types\IndexType::Primary),
            new \mini\Table\ColumnDef('name', \mini\Table\Types\ColumnType::Text),
            new \mini\Table\ColumnDef('status', \mini\Table\Types\ColumnType::Text),
        );

        $pq = PartialQuery::fromTable($table);

        // Should be iterable
        $rows = iterator_to_array($pq);
        $this->assertCount(3, $rows);
    }

    public function testFromTableSupportsFiltering(): void
    {
        $table = new \mini\Table\GeneratorTable(
            fn() => yield from [
                1 => (object)['id' => 1, 'name' => 'Alice', 'status' => 'active'],
                2 => (object)['id' => 2, 'name' => 'Bob', 'status' => 'inactive'],
                3 => (object)['id' => 3, 'name' => 'Carol', 'status' => 'active'],
            ],
            new \mini\Table\ColumnDef('id', \mini\Table\Types\ColumnType::Int, \mini\Table\Types\IndexType::Primary),
            new \mini\Table\ColumnDef('name', \mini\Table\Types\ColumnType::Text),
            new \mini\Table\ColumnDef('status', \mini\Table\Types\ColumnType::Text),
        );

        $pq = PartialQuery::fromTable($table)
            ->eq('status', 'active');

        $rows = iterator_to_array($pq);
        $this->assertCount(2, $rows);
    }

    public function testFromTableSupportsSqlWhere(): void
    {
        $table = new \mini\Table\GeneratorTable(
            fn() => yield from [
                1 => (object)['id' => 1, 'name' => 'Alice', 'age' => 30],
                2 => (object)['id' => 2, 'name' => 'Bob', 'age' => 25],
                3 => (object)['id' => 3, 'name' => 'Carol', 'age' => 35],
            ],
            new \mini\Table\ColumnDef('id', \mini\Table\Types\ColumnType::Int, \mini\Table\Types\IndexType::Primary),
            new \mini\Table\ColumnDef('name', \mini\Table\Types\ColumnType::Text),
            new \mini\Table\ColumnDef('age', \mini\Table\Types\ColumnType::Int),
        );

        $pq = PartialQuery::fromTable($table)
            ->where('age >= ?', [30]);

        $rows = iterator_to_array($pq);
        $this->assertCount(2, $rows);
    }

    public function testFromTableSupportsOrderAndLimit(): void
    {
        $table = new \mini\Table\GeneratorTable(
            fn() => yield from [
                1 => (object)['id' => 1, 'name' => 'Alice'],
                2 => (object)['id' => 2, 'name' => 'Bob'],
                3 => (object)['id' => 3, 'name' => 'Carol'],
            ],
            new \mini\Table\ColumnDef('id', \mini\Table\Types\ColumnType::Int, \mini\Table\Types\IndexType::Primary),
            new \mini\Table\ColumnDef('name', \mini\Table\Types\ColumnType::Text),
        );

        $pq = PartialQuery::fromTable($table)
            ->order('name DESC')
            ->limit(2);

        $rows = iterator_to_array($pq);
        $this->assertCount(2, $rows);
        $names = array_map(fn($r) => $r->name, $rows);
        $this->assertSame(['Carol', 'Bob'], $names);
    }

    // =========================================================================
    // Mutation tests (MutableTableInterface)
    // =========================================================================

    private function createMutationTestTable(): void
    {
        \mini\db()->exec('DROP TABLE IF EXISTS pq_mutation_test');
        \mini\db()->exec('CREATE TABLE pq_mutation_test (
            id INTEGER PRIMARY KEY,
            name TEXT,
            role TEXT DEFAULT "user"
        )');
    }

    private function dropMutationTestTable(): void
    {
        \mini\db()->exec('DROP TABLE IF EXISTS pq_mutation_test');
    }

    public function testImplementsMutableTableInterface(): void
    {
        $this->createMutationTestTable();
        $pq = $this->pq(\mini\db()->query('SELECT * FROM pq_mutation_test'));
        $this->assertInstanceOf(\mini\Table\Contracts\MutableTableInterface::class, $pq);
        $this->dropMutationTestTable();
    }

    public function testInsertBasic(): void
    {
        $this->createMutationTestTable();

        $pq = $this->pq(\mini\db()->query('SELECT * FROM pq_mutation_test'));
        $id = $pq->insert(['name' => 'Test User']);

        $this->assertNotEmpty($id);
        $this->assertCount(1, iterator_to_array($pq));
        $this->dropMutationTestTable();
    }

    public function testInsertEnforcesQueryConstraints(): void
    {
        $this->createMutationTestTable();
        \mini\db()->exec("INSERT INTO pq_mutation_test (name, role) VALUES ('Admin', 'admin')");

        // Query scoped to role='user'
        $pq = $this->pq(\mini\db()->query('SELECT * FROM pq_mutation_test WHERE role = ?', ['user']));

        // Insert matching row - should work
        $pq->insert(['name' => 'User', 'role' => 'user']);
        $this->assertCount(1, iterator_to_array($pq));

        // Insert non-matching row - should throw
        $threw = false;
        try {
            $pq->insert(['name' => 'Admin2', 'role' => 'admin']);
        } catch (\RuntimeException $e) {
            $threw = true;
            $this->assertContains('violates query constraints', $e->getMessage());
        }
        $this->assertTrue($threw, 'Expected exception for constraint violation');
        $this->dropMutationTestTable();
    }

    public function testUpdateBasic(): void
    {
        $this->createMutationTestTable();
        \mini\db()->exec("INSERT INTO pq_mutation_test (name) VALUES ('Original')");

        $pq = $this->pq(\mini\db()->query('SELECT * FROM pq_mutation_test'));
        $affected = $pq->update($pq->eq('name', 'Original'), ['name' => 'Updated']);

        $this->assertSame(1, $affected);
        $row = $pq->one();
        $this->assertSame('Updated', $row->name);
        $this->dropMutationTestTable();
    }

    public function testUpdateRespectsBaseScope(): void
    {
        $this->createMutationTestTable();
        \mini\db()->exec("INSERT INTO pq_mutation_test (name, role) VALUES ('Admin', 'admin')");
        \mini\db()->exec("INSERT INTO pq_mutation_test (name, role) VALUES ('User', 'user')");

        // Scoped to role='user' only
        $pq = $this->pq(\mini\db()->query('SELECT * FROM pq_mutation_test WHERE role = ?', ['user']));

        // Try to update all - should only affect the user, not admin
        $affected = $pq->update($pq, ['name' => 'Modified']);
        $this->assertSame(1, $affected);

        // Verify admin unchanged
        $admin = \mini\db()->query('SELECT * FROM pq_mutation_test WHERE role = ?', ['admin'])->one();
        $this->assertSame('Admin', $admin->name);
        $this->dropMutationTestTable();
    }

    public function testDeleteBasic(): void
    {
        $this->createMutationTestTable();
        \mini\db()->exec("INSERT INTO pq_mutation_test (name) VALUES ('ToDelete')");

        $pq = $this->pq(\mini\db()->query('SELECT * FROM pq_mutation_test'));
        $affected = $pq->delete($pq->eq('name', 'ToDelete'));

        $this->assertSame(1, $affected);
        $this->assertCount(0, iterator_to_array($pq));
        $this->dropMutationTestTable();
    }

    public function testDeleteRespectsBaseScope(): void
    {
        $this->createMutationTestTable();
        \mini\db()->exec("INSERT INTO pq_mutation_test (name, role) VALUES ('Admin', 'admin')");
        \mini\db()->exec("INSERT INTO pq_mutation_test (name, role) VALUES ('User', 'user')");

        // Scoped to role='user' only
        $pq = $this->pq(\mini\db()->query('SELECT * FROM pq_mutation_test WHERE role = ?', ['user']));

        // Delete all in scope - should only delete the user
        $affected = $pq->delete($pq);
        $this->assertSame(1, $affected);

        // Verify admin still exists
        $all = \mini\db()->query('SELECT * FROM pq_mutation_test');
        $this->assertCount(1, iterator_to_array($all));
        $this->dropMutationTestTable();
    }

    public function testInsertOnMultiTableQueryThrows(): void
    {
        $this->createMutationTestTable();
        \mini\db()->exec('DROP TABLE IF EXISTS pq_mutation_test2');
        \mini\db()->exec('CREATE TABLE pq_mutation_test2 (id INTEGER PRIMARY KEY, ref_id INTEGER)');

        $pq = $this->pq(\mini\db()->query('SELECT * FROM pq_mutation_test t1 JOIN pq_mutation_test2 t2 ON t1.id = t2.ref_id'));

        $threw = false;
        try {
            $pq->insert(['name' => 'Test']);
        } catch (\RuntimeException $e) {
            $threw = true;
            $this->assertContains('JOINs', $e->getMessage());
        }
        $this->assertTrue($threw, 'Expected exception for multi-table query');
        $this->dropMutationTestTable();
        \mini\db()->exec('DROP TABLE IF EXISTS pq_mutation_test2');
    }
};

exit($test->run());
