<?php
/**
 * Test running SQLLogicTest against VirtualDatabase with DDL support
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Test;
use mini\Test\SqlLogicTest;
use mini\Database\VirtualDatabase;

$test = new class extends Test {
    public function testVdbWithDdlSupport(): void
    {
        $runner = new SqlLogicTest();
        $runner->addBackend('vdb', new VirtualDatabase());

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

        $this->assertSame(4, $stats['vdb']['pass']);
        $this->assertSame(0, $stats['vdb']['fail']);
    }

    public function testVdbMultipleTables(): void
    {
        $runner = new SqlLogicTest();
        $runner->addBackend('vdb', new VirtualDatabase());

        $content = <<<'TEST'
statement ok
CREATE TABLE users(id INTEGER PRIMARY KEY, name TEXT)

statement ok
CREATE TABLE orders(id INTEGER PRIMARY KEY, user_id INTEGER, total REAL)

statement ok
INSERT INTO users VALUES(1, 'Alice')

statement ok
INSERT INTO users VALUES(2, 'Bob')

statement ok
INSERT INTO orders VALUES(1, 1, 100.50)

statement ok
INSERT INTO orders VALUES(2, 2, 200.75)

query IT rowsort
SELECT id, name FROM users
----
1
Alice
2
Bob

query IIR rowsort
SELECT id, user_id, total FROM orders
----
1
1
100.500
2
2
200.750
TEST;

        $result = $runner->run($content);
        $stats = $result->getStats();

        $this->assertSame(8, $stats['vdb']['pass']);
        $this->assertSame(0, $stats['vdb']['fail']);
    }

    public function testVdbDropTable(): void
    {
        $runner = new SqlLogicTest();
        $runner->addBackend('vdb', new VirtualDatabase());

        $content = <<<'TEST'
statement ok
CREATE TABLE t1(a INTEGER)

statement ok
INSERT INTO t1 VALUES(1)

statement ok
DROP TABLE t1

statement error
SELECT * FROM t1
TEST;

        $result = $runner->run($content);
        $stats = $result->getStats();

        $this->assertSame(4, $stats['vdb']['pass']);
        $this->assertSame(0, $stats['vdb']['fail']);
    }

    public function testVdbJoin(): void
    {
        $runner = new SqlLogicTest();
        $runner->addBackend('vdb', new VirtualDatabase());

        $content = <<<'TEST'
statement ok
CREATE TABLE users(id INTEGER PRIMARY KEY, name TEXT)

statement ok
CREATE TABLE orders(id INTEGER, user_id INTEGER, amount INTEGER)

statement ok
INSERT INTO users VALUES(1, 'Alice')

statement ok
INSERT INTO users VALUES(2, 'Bob')

statement ok
INSERT INTO orders VALUES(1, 1, 100)

statement ok
INSERT INTO orders VALUES(2, 1, 150)

statement ok
INSERT INTO orders VALUES(3, 2, 200)

query TI rowsort
SELECT u.name, o.amount FROM users u JOIN orders o ON u.id = o.user_id
----
Alice
100
Alice
150
Bob
200
TEST;

        $result = $runner->run($content);
        $stats = $result->getStats();

        $this->assertSame(0, $stats['vdb']['fail']);
    }
};

exit($test->run());
