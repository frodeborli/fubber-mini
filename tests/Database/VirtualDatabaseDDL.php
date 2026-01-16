<?php
/**
 * Tests for VirtualDatabase DDL execution (CREATE TABLE, DROP TABLE)
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Test;
use mini\Database\VirtualDatabase;

$test = new class extends Test {
    // =========================================================================
    // CREATE TABLE
    // =========================================================================

    public function testCreateTableBasic(): void
    {
        $vdb = new VirtualDatabase();
        $vdb->exec('CREATE TABLE users (id INTEGER, name TEXT)');

        $this->assertTrue($vdb->tableExists('users'));
    }

    public function testCreateTableWithPrimaryKey(): void
    {
        $vdb = new VirtualDatabase();
        $vdb->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');

        // Insert and query to verify it works
        $vdb->exec("INSERT INTO users (id, name) VALUES (1, 'Alice')");
        $row = $vdb->queryOne('SELECT * FROM users WHERE id = 1');

        $this->assertSame(1, $row->id);
        $this->assertSame('Alice', $row->name);
    }

    public function testCreateTableIfNotExists(): void
    {
        $vdb = new VirtualDatabase();
        $vdb->exec('CREATE TABLE users (id INTEGER)');

        // Should not throw
        $vdb->exec('CREATE TABLE IF NOT EXISTS users (id INTEGER)');

        $this->assertTrue($vdb->tableExists('users'));
    }

    public function testCreateTableDuplicateThrows(): void
    {
        $vdb = new VirtualDatabase();
        $vdb->exec('CREATE TABLE users (id INTEGER)');

        $this->expectException(\RuntimeException::class);
        $vdb->exec('CREATE TABLE users (id INTEGER)');
    }

    public function testCreateTableInsertAndQuery(): void
    {
        $vdb = new VirtualDatabase();
        $vdb->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT, price REAL)');

        $vdb->exec("INSERT INTO products (id, name, price) VALUES (1, 'Widget', 19.99)");
        $vdb->exec("INSERT INTO products (id, name, price) VALUES (2, 'Gadget', 29.99)");

        $rows = iterator_to_array($vdb->query('SELECT * FROM products ORDER BY id'));

        $this->assertCount(2, $rows);
        $this->assertSame('Widget', $rows[0]->name);
        $this->assertSame('Gadget', $rows[1]->name);
    }

    // =========================================================================
    // DROP TABLE
    // =========================================================================

    public function testDropTableBasic(): void
    {
        $vdb = new VirtualDatabase();
        $vdb->exec('CREATE TABLE users (id INTEGER)');
        $this->assertTrue($vdb->tableExists('users'));

        $vdb->exec('DROP TABLE users');
        $this->assertFalse($vdb->tableExists('users'));
    }

    public function testDropTableIfExists(): void
    {
        $vdb = new VirtualDatabase();

        // Should not throw
        $vdb->exec('DROP TABLE IF EXISTS nonexistent');

        $this->assertFalse($vdb->tableExists('nonexistent'));
    }

    public function testDropTableNotFoundThrows(): void
    {
        $vdb = new VirtualDatabase();

        $this->expectException(\RuntimeException::class);
        $vdb->exec('DROP TABLE nonexistent');
    }

    // =========================================================================
    // CREATE INDEX (no-op but should not error)
    // =========================================================================

    public function testCreateIndexNoOp(): void
    {
        $vdb = new VirtualDatabase();
        $vdb->exec('CREATE TABLE users (id INTEGER, email TEXT)');

        // Should not throw - just a no-op
        $result = $vdb->exec('CREATE INDEX idx_email ON users (email)');
        $this->assertSame(0, $result);
    }

    // =========================================================================
    // DROP INDEX (no-op but should not error)
    // =========================================================================

    public function testDropIndexNoOp(): void
    {
        $vdb = new VirtualDatabase();
        $vdb->exec('CREATE TABLE users (id INTEGER, email TEXT)');

        // Should not throw - just a no-op
        $result = $vdb->exec('DROP INDEX idx_email');
        $this->assertSame(0, $result);
    }

    // =========================================================================
    // SQL data type mapping
    // =========================================================================

    public function testVariousDataTypes(): void
    {
        $vdb = new VirtualDatabase();
        $vdb->exec('CREATE TABLE data (
            a INTEGER,
            b REAL,
            c TEXT,
            e DATE,
            f DATETIME
        )');

        $vdb->exec("INSERT INTO data (a, b, c, e, f) VALUES (42, 3.14, 'hello', '2025-01-15', '2025-01-15 10:30:00')");

        $row = $vdb->queryOne('SELECT * FROM data');
        $this->assertSame(42, $row->a);
        $this->assertTrue(abs($row->b - 3.14) < 0.001);
        $this->assertSame('hello', $row->c);
    }

    public function testSqliteStyleNoDataType(): void
    {
        $vdb = new VirtualDatabase();
        $vdb->exec('CREATE TABLE t1 (a, b, c)');

        $vdb->exec("INSERT INTO t1 (a, b, c) VALUES (1, 'two', 3.0)");

        $row = $vdb->queryOne('SELECT * FROM t1');
        // Without data type, InMemoryTable uses TEXT which stores as string
        $this->assertSame('1', $row->a);
        $this->assertSame('two', $row->b);
    }
};

exit($test->run());
