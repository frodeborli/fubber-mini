<?php

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Test\SqlLogicTest;
use mini\Database\VirtualDatabase;
use mini\Database\PDODatabase;
use mini\Table\InMemoryTable;
use mini\Table\ColumnDef;
use mini\Table\ColumnType;
use mini\Table\IndexType;

$test = new class extends \mini\Test {
    private function createSqliteDb(): PDODatabase
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return new PDODatabase($pdo);
    }

    private function createVdb(): VirtualDatabase
    {
        return new VirtualDatabase();
    }

    public function testParseStatementOk(): void
    {
        $runner = new SqlLogicTest();
        $content = <<<'TEST'
statement ok
CREATE TABLE t1(a INTEGER, b TEXT)
TEST;

        $records = $runner->parse($content);
        $this->assertCount(1, $records);
        $this->assertSame('statement', $records[0]->type);
        $this->assertSame('CREATE TABLE t1(a INTEGER, b TEXT)', $records[0]->sql);
        $this->assertFalse($records[0]->expectError);
    }

    public function testParseStatementError(): void
    {
        $runner = new SqlLogicTest();
        $content = <<<'TEST'
statement error
INSERT INTO nonexistent VALUES(1)
TEST;

        $records = $runner->parse($content);
        $this->assertCount(1, $records);
        $this->assertTrue($records[0]->expectError);
    }

    public function testParseQuery(): void
    {
        $runner = new SqlLogicTest();
        // SQLLogicTest format: each value on its own line
        $content = <<<'TEST'
query ITR rowsort
SELECT id, name, score FROM users
----
1
Alice
95.500
2
Bob
87.300
TEST;

        $records = $runner->parse($content);
        $this->assertCount(1, $records);
        $this->assertSame('query', $records[0]->type);
        $this->assertSame('ITR', $records[0]->types);
        $this->assertSame('rowsort', $records[0]->sortMode);
        $this->assertCount(6, $records[0]->expected); // 2 rows × 3 columns = 6 values
    }

    public function testParseHashResult(): void
    {
        $runner = new SqlLogicTest();
        $content = <<<'TEST'
query I rowsort
SELECT a FROM t1
----
30 values hashing to 3c13dee48d9356ae19af2515e05e6b54
TEST;

        $records = $runner->parse($content);
        $this->assertCount(1, $records);
        $this->assertSame('30 values hashing to 3c13dee48d9356ae19af2515e05e6b54', $records[0]->expected[0]);
    }

    public function testParseSkipIf(): void
    {
        $runner = new SqlLogicTest();
        $content = <<<'TEST'
skipif vdb
statement ok
CREATE INDEX idx ON t1(a)
TEST;

        $records = $runner->parse($content);
        $this->assertCount(1, $records);
        $this->assertSame('vdb', $records[0]->skipIf);
    }

    public function testParseOnlyIf(): void
    {
        $runner = new SqlLogicTest();
        $content = <<<'TEST'
onlyif sqlite
query I
SELECT sqlite_version()
----
3.40.0
TEST;

        $records = $runner->parse($content);
        $this->assertCount(1, $records);
        $this->assertSame('sqlite', $records[0]->onlyIf);
    }

    public function testRunStatementAgainstSqlite(): void
    {
        $runner = new SqlLogicTest();
        $runner->addBackend('sqlite', $this->createSqliteDb());

        $content = <<<'TEST'
statement ok
CREATE TABLE t1(a INTEGER, b TEXT)

statement ok
INSERT INTO t1 VALUES(1, 'hello')
TEST;

        $result = $runner->run($content);
        $stats = $result->getStats();

        $this->assertSame(2, $stats['sqlite']['pass']);
        $this->assertSame(0, $stats['sqlite']['fail']);
    }

    public function testRunQueryAgainstSqlite(): void
    {
        $runner = new SqlLogicTest();
        $sqlite = $this->createSqliteDb();
        $runner->addBackend('sqlite', $sqlite);

        $content = <<<'TEST'
statement ok
CREATE TABLE t1(a INTEGER, b TEXT)

statement ok
INSERT INTO t1 VALUES(1, 'hello')

statement ok
INSERT INTO t1 VALUES(2, 'world')

query IT rowsort
SELECT a, b FROM t1
----
1
hello
2
world
TEST;

        $result = $runner->run($content);
        $stats = $result->getStats();

        $this->assertSame(4, $stats['sqlite']['pass']);
        $this->assertSame(0, $stats['sqlite']['fail']);
    }

    public function testRunAgainstBothBackends(): void
    {
        // Note: VDB doesn't support CREATE TABLE/INSERT, so we use skipif
        $runner = new SqlLogicTest();
        $runner->addBackend('sqlite', $this->createSqliteDb());
        $runner->addBackend('vdb', $this->createVdb());

        $content = <<<'TEST'
skipif vdb
statement ok
CREATE TABLE t1(a INTEGER, b TEXT)

skipif vdb
statement ok
INSERT INTO t1 VALUES(1, 'hello')

skipif vdb
query IT rowsort
SELECT a, b FROM t1
----
1
hello
TEST;

        $result = $runner->run($content);
        $stats = $result->getStats();

        // SQLite runs all 3, VDB skips all 3
        $this->assertSame(3, $stats['sqlite']['pass']);
        $this->assertSame(0, $stats['sqlite']['fail']);
        $this->assertSame(0, $stats['vdb']['pass']);
        $this->assertSame(3, $stats['vdb']['skip']);
    }

    public function testSkipIfWorks(): void
    {
        $runner = new SqlLogicTest();
        // Use separate connections for each backend
        $runner->addBackend('sqlite', $this->createSqliteDb());
        $runner->addBackend('mysql', $this->createSqliteDb());

        $content = <<<'TEST'
statement ok
CREATE TABLE t1(a INTEGER)

skipif mysql
statement ok
CREATE INDEX idx ON t1(a)
TEST;

        $result = $runner->run($content);
        $stats = $result->getStats();

        // sqlite should run both, mysql should skip second (but run first)
        $this->assertSame(2, $stats['sqlite']['pass']);
        $this->assertSame(1, $stats['mysql']['pass']);
        $this->assertSame(1, $stats['mysql']['skip']);
    }

    public function testQueryMismatchFails(): void
    {
        $runner = new SqlLogicTest();
        $sqlite = $this->createSqliteDb();
        $runner->addBackend('sqlite', $sqlite);

        $content = <<<'TEST'
statement ok
CREATE TABLE t1(a INTEGER)

statement ok
INSERT INTO t1 VALUES(1)

query I
SELECT a FROM t1
----
999
TEST;

        $result = $runner->run($content);
        $stats = $result->getStats();

        $this->assertSame(2, $stats['sqlite']['pass']);
        $this->assertSame(1, $stats['sqlite']['fail']);
        $this->assertTrue($result->hasFailures());
    }

    public function testExpectedErrorWorks(): void
    {
        $runner = new SqlLogicTest();
        $runner->addBackend('sqlite', $this->createSqliteDb());

        $content = <<<'TEST'
statement error
SELECT * FROM nonexistent_table
TEST;

        $result = $runner->run($content);
        $stats = $result->getStats();

        $this->assertSame(1, $stats['sqlite']['pass']);
        $this->assertSame(0, $stats['sqlite']['fail']);
    }

    public function testNullFormatting(): void
    {
        $runner = new SqlLogicTest();
        $runner->addBackend('sqlite', $this->createSqliteDb());

        $content = <<<'TEST'
statement ok
CREATE TABLE t1(a INTEGER)

statement ok
INSERT INTO t1 VALUES(NULL)

query I
SELECT a FROM t1
----
NULL
TEST;

        $result = $runner->run($content);
        $stats = $result->getStats();

        $this->assertSame(3, $stats['sqlite']['pass']);
        $this->assertSame(0, $stats['sqlite']['fail']);
    }

    public function testFloatFormatting(): void
    {
        $runner = new SqlLogicTest();
        $runner->addBackend('sqlite', $this->createSqliteDb());

        $content = <<<'TEST'
query R
SELECT 3.14159
----
3.142
TEST;

        $result = $runner->run($content);
        $stats = $result->getStats();

        $this->assertSame(1, $stats['sqlite']['pass']);
    }

    public function testRowsort(): void
    {
        $runner = new SqlLogicTest();
        $runner->addBackend('sqlite', $this->createSqliteDb());

        $content = <<<'TEST'
statement ok
CREATE TABLE t1(a INTEGER)

statement ok
INSERT INTO t1 VALUES(3)

statement ok
INSERT INTO t1 VALUES(1)

statement ok
INSERT INTO t1 VALUES(2)

query I rowsort
SELECT a FROM t1
----
1
2
3
TEST;

        $result = $runner->run($content);
        $stats = $result->getStats();

        // All should pass - rowsort means order doesn't matter
        $this->assertSame(0, $stats['sqlite']['fail']);
    }
};

exit($test->run());
