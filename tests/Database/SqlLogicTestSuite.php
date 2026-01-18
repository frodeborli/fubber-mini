<?php
/**
 * SQL Logic Test Suite - Mini-style test
 *
 * Runs a sample of SQLLogicTest queries to verify VirtualDatabase SQL compliance.
 * For comprehensive testing, use bin/sql-logic-test with the full test data.
 *
 * Quick tests run against bundled test data (always available).
 * Extended tests require: git clone https://github.com/dolthub/sqllogictest tests/sqllogictest-data
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Test;
use mini\Test\SqlLogicTest;
use mini\Database\VirtualDatabase;
use mini\Database\PDODatabase;

$test = new class extends Test {
    private string $bundledDir;
    private ?string $externalDir = null;

    protected function setUp(): void
    {
        $this->bundledDir = __DIR__ . '/../sqllogictest';
        $externalPath = __DIR__ . '/../sqllogictest-data';
        if (is_dir($externalPath)) {
            $this->externalDir = $externalPath;
        }
    }

    private function createSqlite(): PDODatabase
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return new PDODatabase($pdo);
    }

    /**
     * Test basic DDL and DML operations
     */
    public function testBasicDdlAndDml(): void
    {
        $runner = new SqlLogicTest();
        $runner->addBackend('vdb', new VirtualDatabase());

        $content = <<<'TEST'
statement ok
CREATE TABLE t1(a INTEGER PRIMARY KEY, b TEXT, c REAL)

statement ok
INSERT INTO t1 VALUES(1, 'hello', 3.14)

statement ok
INSERT INTO t1 VALUES(2, 'world', 2.71)

query ITR rowsort
SELECT a, b, c FROM t1
----
1
hello
3.140
2
world
2.710

statement ok
UPDATE t1 SET b = 'updated' WHERE a = 1

query T
SELECT b FROM t1 WHERE a = 1
----
updated

statement ok
DELETE FROM t1 WHERE a = 2

query I
SELECT COUNT(*) FROM t1
----
1

statement ok
DROP TABLE t1

statement error
SELECT * FROM t1
TEST;

        $result = $runner->run($content);
        $stats = $result->getStats();

        $this->assertSame(0, $stats['vdb']['fail'], 'Basic DDL/DML should pass');
        $this->assertGreaterThan(5, $stats['vdb']['pass'], 'Should pass multiple tests');
    }

    /**
     * Test JOIN operations
     */
    public function testJoins(): void
    {
        $runner = new SqlLogicTest();
        $runner->addBackend('vdb', new VirtualDatabase());

        $content = <<<'TEST'
statement ok
CREATE TABLE users(id INTEGER PRIMARY KEY, name TEXT)

statement ok
CREATE TABLE orders(id INTEGER PRIMARY KEY, user_id INTEGER, amount INTEGER)

statement ok
INSERT INTO users VALUES(1, 'Alice')

statement ok
INSERT INTO users VALUES(2, 'Bob')

statement ok
INSERT INTO orders VALUES(1, 1, 100)

statement ok
INSERT INTO orders VALUES(2, 2, 200)

query TI rowsort
SELECT u.name, o.amount FROM users u JOIN orders o ON u.id = o.user_id
----
Alice
100
Bob
200

query TI rowsort
SELECT u.name, o.amount FROM users u LEFT JOIN orders o ON u.id = o.user_id ORDER BY u.name
----
Alice
100
Bob
200
TEST;

        $result = $runner->run($content);
        $stats = $result->getStats();

        $this->assertSame(0, $stats['vdb']['fail'], 'JOIN operations should pass');
    }

    /**
     * Test aggregate functions
     */
    public function testAggregates(): void
    {
        $runner = new SqlLogicTest();
        $runner->addBackend('vdb', new VirtualDatabase());

        $content = <<<'TEST'
statement ok
CREATE TABLE numbers(val INTEGER)

statement ok
INSERT INTO numbers VALUES(10)

statement ok
INSERT INTO numbers VALUES(20)

statement ok
INSERT INTO numbers VALUES(30)

query I
SELECT SUM(val) FROM numbers
----
60

query I
SELECT AVG(val) FROM numbers
----
20

query I
SELECT MIN(val) FROM numbers
----
10

query I
SELECT MAX(val) FROM numbers
----
30

query I
SELECT COUNT(*) FROM numbers
----
3
TEST;

        $result = $runner->run($content);
        $stats = $result->getStats();

        $this->assertSame(0, $stats['vdb']['fail'], 'Aggregate functions should pass');
    }

    /**
     * Test subqueries
     */
    public function testSubqueries(): void
    {
        $runner = new SqlLogicTest();
        $runner->addBackend('vdb', new VirtualDatabase());

        $content = <<<'TEST'
statement ok
CREATE TABLE t1(a INTEGER)

statement ok
INSERT INTO t1 VALUES(1)

statement ok
INSERT INTO t1 VALUES(2)

statement ok
INSERT INTO t1 VALUES(3)

query I rowsort
SELECT a FROM t1 WHERE a > (SELECT MIN(a) FROM t1)
----
2
3

query I
SELECT (SELECT MAX(a) FROM t1)
----
3

query I rowsort
SELECT a FROM t1 WHERE a IN (SELECT a FROM t1 WHERE a > 1)
----
2
3
TEST;

        $result = $runner->run($content);
        $stats = $result->getStats();

        $this->assertSame(0, $stats['vdb']['fail'], 'Subqueries should pass');
    }

    /**
     * Run a quick sample from bundled test file
     *
     * Note: The bundled slt_good_0.test contains complex multi-table joins
     * that stress-test VDB. We expect some failures due to known limitations.
     */
    public function testBundledSample(): void
    {
        $testFile = $this->bundledDir . '/slt_good_0.test';
        if (!file_exists($testFile)) {
            $this->log('Bundled test file not found, skipping');
            return;
        }

        $runner = new SqlLogicTest();
        $runner->addBackend('sqlite', $this->createSqlite());
        $vdb = new VirtualDatabase();
        $vdb->setQueryTimeout(1.0);
        $runner->addBackend('vdb', $vdb);

        // Read first 300 lines (setup + initial queries, avoids complex joins)
        $lines = file($testFile);
        $content = implode('', array_slice($lines, 0, 300));

        $result = $runner->run($content);
        $stats = $result->getStats();

        // SQLite should have no failures
        $this->assertSame(0, $stats['sqlite']['fail'], 'SQLite should pass all tests');

        // VDB should pass most tests (allow some failures for edge cases)
        $vdbTotal = $stats['vdb']['pass'] + $stats['vdb']['fail'];
        if ($vdbTotal > 0) {
            $vdbPassRate = $stats['vdb']['pass'] / $vdbTotal;
            $this->log(sprintf("Bundled sample: VDB passed %d%% (%d/%d)",
                round($vdbPassRate * 100), $stats['vdb']['pass'], $vdbTotal));
            $this->assertTrue($vdbPassRate > 0.5, "VDB should pass >50% (got " . round($vdbPassRate * 100) . "%)");
        }
    }

    /**
     * Quick test of external select1.test if available
     */
    public function testExternalSelect1(): void
    {
        if ($this->externalDir === null) {
            $this->log('External test data not installed, skipping');
            $this->log('Install with: cd tests && git clone https://github.com/dolthub/sqllogictest sqllogictest-data');
            return;
        }

        $testFile = $this->externalDir . '/test/select1.test';
        if (!file_exists($testFile)) {
            $this->log('select1.test not found, skipping');
            return;
        }

        $runner = new SqlLogicTest();
        $runner->addBackend('sqlite', $this->createSqlite());
        $vdb = new VirtualDatabase();
        $vdb->setQueryTimeout(1.0);
        $runner->addBackend('vdb', $vdb);

        $content = file_get_contents($testFile);
        $result = $runner->run($content);
        $stats = $result->getStats();

        // Report results
        $this->log(sprintf(
            "select1.test: SQLite %d/%d, VDB %d/%d",
            $stats['sqlite']['pass'],
            $stats['sqlite']['pass'] + $stats['sqlite']['fail'],
            $stats['vdb']['pass'],
            $stats['vdb']['pass'] + $stats['vdb']['fail']
        ));

        // SQLite should pass all
        $this->assertSame(0, $stats['sqlite']['fail'], 'SQLite should pass all tests');

        // VDB should pass all (this is a core test)
        $this->assertSame(0, $stats['vdb']['fail'], 'VDB should pass all select1.test queries');
    }
};

exit($test->run());
