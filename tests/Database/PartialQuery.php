<?php
/**
 * Test PartialQuery implementation
 *
 * Tests: query building, WHERE methods, ORDER/LIMIT/OFFSET, hydration, ResultSetInterface
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use mini\Test;
use mini\Database\PartialQuery;
use mini\Database\ResultSetInterface;

class TestEntity {
    public int $id;
    public string $name;
}

$test = new class extends Test {

    protected function setUp(): void
    {
        // Bootstrap to get db() available
        \mini\bootstrap();
    }

    public function testImplementsResultSetInterface(): void
    {
        $pq = \mini\db()->query('SELECT * FROM users');
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

        $this->assertContains('"deleted_at" IS NULL', $sql);
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
        $pq = \mini\db()->query('SELECT * FROM users')->select('id, name');
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

        $entities = \mini\db()->query('SELECT * FROM test_entities')
            ->withEntityClass(TestEntity::class)
            ->toArray();

        $this->assertCount(1, $entities);
        $this->assertInstanceOf(TestEntity::class, $entities[0]);
        $this->assertSame(1, $entities[0]->id);
        $this->assertSame('Test', $entities[0]->name);

        // Cleanup
        \mini\db()->exec('DROP TABLE test_entities');
    }

    public function testToArray(): void
    {
        \mini\db()->exec('CREATE TABLE IF NOT EXISTS test_toarray (id INTEGER PRIMARY KEY)');
        \mini\db()->exec('DELETE FROM test_toarray');
        \mini\db()->exec('INSERT INTO test_toarray (id) VALUES (1), (2), (3)');

        $rows = \mini\db()->query('SELECT * FROM test_toarray')->toArray();

        $this->assertCount(3, $rows);
        $this->assertSame(1, (int) $rows[0]->id);
        $this->assertSame(2, (int) $rows[1]->id);
        $this->assertSame(3, (int) $rows[2]->id);

        \mini\db()->exec('DROP TABLE test_toarray');
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

        $ids = \mini\db()->query('SELECT * FROM test_column')->order('id')->column();

        $this->assertCount(3, $ids);
        $this->assertEquals([1, 2, 3], $ids); // Use assertEquals for loose comparison

        \mini\db()->exec('DROP TABLE test_column');
    }

    public function testField(): void
    {
        \mini\db()->exec('CREATE TABLE IF NOT EXISTS test_field (id INTEGER PRIMARY KEY, name TEXT)');
        \mini\db()->exec('DELETE FROM test_field');
        \mini\db()->exec("INSERT INTO test_field (id, name) VALUES (1, 'Test')");

        $id = \mini\db()->query('SELECT * FROM test_field')->field();

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

        $json = json_encode(\mini\db()->query('SELECT * FROM test_json'));

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
};

exit($test->run());
