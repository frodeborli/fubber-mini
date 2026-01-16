<?php
/**
 * Tests for DDL parsing (CREATE TABLE, CREATE INDEX, DROP TABLE, DROP INDEX)
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Test;
use mini\Parsing\SQL\SqlParser;
use mini\Parsing\SQL\AST\CreateTableStatement;
use mini\Parsing\SQL\AST\CreateIndexStatement;
use mini\Parsing\SQL\AST\DropTableStatement;
use mini\Parsing\SQL\AST\DropIndexStatement;

$test = new class extends Test {
    private function parse(string $sql): mixed
    {
        return (new SqlParser())->parse($sql);
    }

    // =========================================================================
    // CREATE TABLE
    // =========================================================================

    public function testCreateTableBasic(): void
    {
        $ast = $this->parse('CREATE TABLE users (id INTEGER, name TEXT)');

        $this->assertInstanceOf(CreateTableStatement::class, $ast);
        $this->assertSame('users', $ast->table->getName());
        $this->assertCount(2, $ast->columns);
        $this->assertSame('id', $ast->columns[0]->name);
        $this->assertSame('INTEGER', $ast->columns[0]->dataType);
        $this->assertSame('name', $ast->columns[1]->name);
        $this->assertSame('TEXT', $ast->columns[1]->dataType);
    }

    public function testCreateTableIfNotExists(): void
    {
        $ast = $this->parse('CREATE TABLE IF NOT EXISTS users (id INTEGER)');

        $this->assertTrue($ast->ifNotExists);
        $this->assertSame('users', $ast->table->getName());
    }

    public function testCreateTablePrimaryKey(): void
    {
        $ast = $this->parse('CREATE TABLE users (id INTEGER PRIMARY KEY)');

        $this->assertTrue($ast->columns[0]->primaryKey);
    }

    public function testCreateTablePrimaryKeyAutoincrement(): void
    {
        $ast = $this->parse('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT)');

        $this->assertTrue($ast->columns[0]->primaryKey);
        $this->assertTrue($ast->columns[0]->autoIncrement);
    }

    public function testCreateTableNotNull(): void
    {
        $ast = $this->parse('CREATE TABLE users (name TEXT NOT NULL)');

        $this->assertTrue($ast->columns[0]->notNull);
    }

    public function testCreateTableUnique(): void
    {
        $ast = $this->parse('CREATE TABLE users (email TEXT UNIQUE)');

        $this->assertTrue($ast->columns[0]->unique);
    }

    public function testCreateTableDefaultNumber(): void
    {
        $ast = $this->parse('CREATE TABLE users (age INTEGER DEFAULT 0)');

        $this->assertNotNull($ast->columns[0]->default);
        $this->assertSame('0', $ast->columns[0]->default->value);
    }

    public function testCreateTableDefaultString(): void
    {
        $ast = $this->parse("CREATE TABLE users (status TEXT DEFAULT 'active')");

        $this->assertNotNull($ast->columns[0]->default);
        $this->assertSame('active', $ast->columns[0]->default->value);
    }

    public function testCreateTableDefaultNull(): void
    {
        $ast = $this->parse('CREATE TABLE users (notes TEXT DEFAULT NULL)');

        $this->assertNotNull($ast->columns[0]->default);
        $this->assertNull($ast->columns[0]->default->value);
    }

    public function testCreateTableVarcharLength(): void
    {
        $ast = $this->parse('CREATE TABLE users (email VARCHAR(255))');

        $this->assertSame('VARCHAR', $ast->columns[0]->dataType);
        $this->assertSame(255, $ast->columns[0]->length);
    }

    public function testCreateTableDecimalPrecision(): void
    {
        $ast = $this->parse('CREATE TABLE products (price DECIMAL(10, 2))');

        $this->assertSame('DECIMAL', $ast->columns[0]->dataType);
        $this->assertSame(10, $ast->columns[0]->precision);
        $this->assertSame(2, $ast->columns[0]->scale);
    }

    public function testCreateTableMultipleConstraints(): void
    {
        $ast = $this->parse('CREATE TABLE users (id INTEGER PRIMARY KEY NOT NULL UNIQUE)');

        $col = $ast->columns[0];
        $this->assertTrue($col->primaryKey);
        $this->assertTrue($col->notNull);
        $this->assertTrue($col->unique);
    }

    public function testCreateTableTableLevelPrimaryKey(): void
    {
        $ast = $this->parse('CREATE TABLE users (id INTEGER, name TEXT, PRIMARY KEY (id))');

        $this->assertCount(2, $ast->columns);
        $this->assertCount(1, $ast->constraints);
        $this->assertSame('PRIMARY KEY', $ast->constraints[0]->constraintType);
        $this->assertSame(['id'], $ast->constraints[0]->columns);
    }

    public function testCreateTableCompositePrimaryKey(): void
    {
        $ast = $this->parse('CREATE TABLE order_items (order_id INTEGER, product_id INTEGER, PRIMARY KEY (order_id, product_id))');

        $this->assertSame(['order_id', 'product_id'], $ast->constraints[0]->columns);
    }

    public function testCreateTableForeignKey(): void
    {
        $ast = $this->parse('CREATE TABLE posts (id INTEGER, user_id INTEGER, FOREIGN KEY (user_id) REFERENCES users(id))');

        $this->assertCount(1, $ast->constraints);
        $fk = $ast->constraints[0];
        $this->assertSame('FOREIGN KEY', $fk->constraintType);
        $this->assertSame(['user_id'], $fk->columns);
        $this->assertSame('users', $fk->references);
        $this->assertSame(['id'], $fk->referencesColumns);
    }

    public function testCreateTableForeignKeyOnDelete(): void
    {
        $ast = $this->parse('CREATE TABLE posts (user_id INTEGER, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)');

        $this->assertSame('CASCADE', $ast->constraints[0]->onDelete);
    }

    public function testCreateTableForeignKeyOnUpdate(): void
    {
        $ast = $this->parse('CREATE TABLE posts (user_id INTEGER, FOREIGN KEY (user_id) REFERENCES users(id) ON UPDATE SET NULL)');

        $this->assertSame('SET NULL', $ast->constraints[0]->onUpdate);
    }

    public function testCreateTableUniqueConstraint(): void
    {
        $ast = $this->parse('CREATE TABLE users (first_name TEXT, last_name TEXT, UNIQUE (first_name, last_name))');

        $this->assertSame('UNIQUE', $ast->constraints[0]->constraintType);
        $this->assertSame(['first_name', 'last_name'], $ast->constraints[0]->columns);
    }

    public function testCreateTableNamedConstraint(): void
    {
        $ast = $this->parse('CREATE TABLE users (id INTEGER, CONSTRAINT pk_users PRIMARY KEY (id))');

        $this->assertSame('pk_users', $ast->constraints[0]->name);
        $this->assertSame('PRIMARY KEY', $ast->constraints[0]->constraintType);
    }

    public function testCreateTableReferences(): void
    {
        $ast = $this->parse('CREATE TABLE posts (user_id INTEGER REFERENCES users(id))');

        $this->assertSame('users', $ast->columns[0]->references);
        $this->assertSame('id', $ast->columns[0]->referencesColumn);
    }

    public function testCreateTableSqliteStyle(): void
    {
        // SQLite allows omitting data types
        $ast = $this->parse('CREATE TABLE t1 (a, b, c)');

        $this->assertCount(3, $ast->columns);
        $this->assertSame('a', $ast->columns[0]->name);
        $this->assertNull($ast->columns[0]->dataType);
    }

    public function testCreateTableFloat(): void
    {
        $ast = $this->parse('CREATE TABLE t1 (score FLOAT)');

        $this->assertSame('FLOAT', $ast->columns[0]->dataType);
    }

    public function testCreateTableReal(): void
    {
        $ast = $this->parse('CREATE TABLE t1 (score REAL)');

        $this->assertSame('REAL', $ast->columns[0]->dataType);
    }

    // =========================================================================
    // CREATE INDEX
    // =========================================================================

    public function testCreateIndexBasic(): void
    {
        $ast = $this->parse('CREATE INDEX idx_users_email ON users (email)');

        $this->assertInstanceOf(CreateIndexStatement::class, $ast);
        $this->assertSame('idx_users_email', $ast->name);
        $this->assertSame('users', $ast->table->getName());
        $this->assertFalse($ast->unique);
        $this->assertCount(1, $ast->columns);
        $this->assertSame('email', $ast->columns[0]->name);
    }

    public function testCreateUniqueIndex(): void
    {
        $ast = $this->parse('CREATE UNIQUE INDEX idx_users_email ON users (email)');

        $this->assertTrue($ast->unique);
    }

    public function testCreateIndexIfNotExists(): void
    {
        $ast = $this->parse('CREATE INDEX IF NOT EXISTS idx_users_email ON users (email)');

        $this->assertTrue($ast->ifNotExists);
    }

    public function testCreateIndexMultipleColumns(): void
    {
        $ast = $this->parse('CREATE INDEX idx_users_name ON users (first_name, last_name)');

        $this->assertCount(2, $ast->columns);
        $this->assertSame('first_name', $ast->columns[0]->name);
        $this->assertSame('last_name', $ast->columns[1]->name);
    }

    public function testCreateIndexWithOrder(): void
    {
        $ast = $this->parse('CREATE INDEX idx_users_created ON users (created_at DESC)');

        $this->assertSame('DESC', $ast->columns[0]->order);
    }

    public function testCreateIndexMixedOrder(): void
    {
        $ast = $this->parse('CREATE INDEX idx ON t (a ASC, b DESC, c)');

        $this->assertSame('ASC', $ast->columns[0]->order);
        $this->assertSame('DESC', $ast->columns[1]->order);
        $this->assertNull($ast->columns[2]->order);
    }

    // =========================================================================
    // DROP TABLE
    // =========================================================================

    public function testDropTableBasic(): void
    {
        $ast = $this->parse('DROP TABLE users');

        $this->assertInstanceOf(DropTableStatement::class, $ast);
        $this->assertSame('users', $ast->table->getName());
        $this->assertFalse($ast->ifExists);
    }

    public function testDropTableIfExists(): void
    {
        $ast = $this->parse('DROP TABLE IF EXISTS users');

        $this->assertTrue($ast->ifExists);
    }

    // =========================================================================
    // DROP INDEX
    // =========================================================================

    public function testDropIndexBasic(): void
    {
        $ast = $this->parse('DROP INDEX idx_users_email');

        $this->assertInstanceOf(DropIndexStatement::class, $ast);
        $this->assertSame('idx_users_email', $ast->name);
        $this->assertFalse($ast->ifExists);
    }

    public function testDropIndexIfExists(): void
    {
        $ast = $this->parse('DROP INDEX IF EXISTS idx_users_email');

        $this->assertTrue($ast->ifExists);
    }

    public function testDropIndexOnTable(): void
    {
        // MySQL style
        $ast = $this->parse('DROP INDEX idx_users_email ON users');

        $this->assertSame('idx_users_email', $ast->name);
        $this->assertSame('users', $ast->table->getName());
    }
};

exit($test->run());
