<?php
/**
 * Test PDODatabase implementation
 *
 * Tests: query, queryOne, queryField, queryColumn, exec, insert, transaction
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Test;
use mini\Database\PDODatabase;
use mini\Database\ResultSetInterface;
use mini\Database\PartialQuery;

$test = new class extends Test {

    protected function setUp(): void
    {
        \mini\bootstrap();

        // Create test table
        \mini\db()->exec('DROP TABLE IF EXISTS test_db');
        \mini\db()->exec('CREATE TABLE test_db (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT,
            status TEXT DEFAULT "active"
        )');
    }

    private function cleanTable(): void
    {
        \mini\db()->exec('DELETE FROM test_db');
    }

    public function testQueryReturnsResultSetInterface(): void
    {
        $result = \mini\db()->query('SELECT 1 as num');
        $this->assertInstanceOf(ResultSetInterface::class, $result);
    }

    public function testQueryReturnsPartialQuery(): void
    {
        $result = \mini\db()->query('SELECT * FROM test_db');
        $this->assertInstanceOf(PartialQuery::class, $result);
    }

    public function testQueryIteratesRows(): void
    {
        $this->cleanTable();
        \mini\db()->exec("INSERT INTO test_db (name) VALUES ('Alice'), ('Bob')");

        $rows = [];
        foreach (\mini\db()->query('SELECT * FROM test_db ORDER BY id') as $row) {
            $rows[] = $row;
        }

        $this->assertCount(2, $rows);
        $this->assertSame('Alice', $rows[0]->name);
        $this->assertSame('Bob', $rows[1]->name);
    }

    public function testQueryToArray(): void
    {
        $this->cleanTable();
        \mini\db()->exec("INSERT INTO test_db (name) VALUES ('Test')");

        $rows = \mini\db()->query('SELECT * FROM test_db')->toArray();

        $this->assertCount(1, $rows);
        $this->assertSame('Test', $rows[0]->name);
    }

    public function testQueryOne(): void
    {
        $this->cleanTable();
        \mini\db()->exec("INSERT INTO test_db (name) VALUES ('First'), ('Second')");

        $row = \mini\db()->queryOne('SELECT * FROM test_db ORDER BY id');

        $this->assertNotNull($row);
        $this->assertSame('First', $row->name);
    }

    public function testQueryOneReturnsNullWhenEmpty(): void
    {
        $this->cleanTable();
        $row = \mini\db()->queryOne('SELECT * FROM test_db WHERE id = 999');
        $this->assertNull($row);
    }

    public function testQueryField(): void
    {
        $this->cleanTable();
        \mini\db()->exec("INSERT INTO test_db (name) VALUES ('Test')");

        $count = \mini\db()->queryField('SELECT COUNT(*) FROM test_db');
        $this->assertEquals(1, $count);

        $name = \mini\db()->queryField('SELECT name FROM test_db LIMIT 1');
        $this->assertSame('Test', $name);
    }

    public function testQueryColumn(): void
    {
        $this->cleanTable();
        \mini\db()->exec("INSERT INTO test_db (name) VALUES ('A'), ('B'), ('C')");

        $names = \mini\db()->queryColumn('SELECT name FROM test_db ORDER BY id');

        $this->assertSame(['A', 'B', 'C'], $names);
    }

    public function testExecReturnsAffectedRows(): void
    {
        $this->cleanTable();
        \mini\db()->exec("INSERT INTO test_db (name) VALUES ('One'), ('Two')");

        $affected = \mini\db()->exec("UPDATE test_db SET status = 'updated' WHERE id > 0");

        $this->assertSame(2, $affected);
    }

    public function testInsertReturnsLastInsertId(): void
    {
        $this->cleanTable();
        $id = \mini\db()->insert('test_db', ['name' => 'Inserted']);

        $this->assertNotNull($id);
        $this->assertTrue($id !== '' && $id !== '0');

        // Verify the row exists
        $row = \mini\db()->queryOne("SELECT * FROM test_db WHERE id = ?", [$id]);
        $this->assertSame('Inserted', $row->name);
    }

    public function testInsertThrowsOnEmptyData(): void
    {
        $this->assertThrows(
            fn() => \mini\db()->insert('test_db', []),
            \InvalidArgumentException::class
        );
    }

    public function testTransactionCommits(): void
    {
        $this->cleanTable();
        $result = \mini\db()->transaction(function($db) {
            $db->exec("INSERT INTO test_db (name) VALUES ('Transaction')");
            return 'done';
        });

        $this->assertSame('done', $result);

        // Verify committed
        $row = \mini\db()->queryOne("SELECT * FROM test_db WHERE name = 'Transaction'");
        $this->assertNotNull($row);
    }

    public function testTransactionRollsBackOnException(): void
    {
        $this->cleanTable();
        try {
            \mini\db()->transaction(function($db) {
                $db->exec("INSERT INTO test_db (name) VALUES ('WillRollback')");
                throw new \RuntimeException('Intentional failure');
            });
        } catch (\RuntimeException $e) {
            // Expected
        }

        // Verify rolled back
        $row = \mini\db()->queryOne("SELECT * FROM test_db WHERE name = 'WillRollback'");
        $this->assertNull($row);
    }

    public function testNestedTransactionsThrow(): void
    {
        $this->cleanTable();

        $thrown = false;
        try {
            \mini\db()->transaction(function($db) {
                $db->exec("INSERT INTO test_db (name) VALUES ('Outer')");

                // This should throw - nested transactions not supported
                $db->transaction(function($db2) {
                    $db2->exec("INSERT INTO test_db (name) VALUES ('Inner')");
                });
            });
        } catch (\RuntimeException $e) {
            $thrown = true;
            $this->assertTrue(str_contains($e->getMessage(), 'Already in a transaction'));
        }

        $this->assertTrue($thrown, 'Expected RuntimeException for nested transaction');

        // Outer insert should have been rolled back
        $this->assertNull(\mini\db()->queryOne("SELECT * FROM test_db WHERE name = 'Outer'"));
    }

    public function testDeleteWithPartialQuery(): void
    {
        $this->cleanTable();
        \mini\db()->exec("INSERT INTO test_db (name, status) VALUES ('A', 'active'), ('B', 'inactive')");

        $deleted = \mini\db()->delete(
            \mini\db()->query('SELECT * FROM test_db')->eq('status', 'inactive')
        );

        $this->assertSame(1, $deleted);

        // Verify only inactive deleted
        $remaining = \mini\db()->queryColumn('SELECT name FROM test_db');
        $this->assertSame(['A'], $remaining);
    }

    public function testUpdateWithPartialQuery(): void
    {
        $this->cleanTable();
        \mini\db()->exec("INSERT INTO test_db (name, status) VALUES ('A', 'active')");
        $id = \mini\db()->lastInsertId();

        $updated = \mini\db()->update(
            \mini\db()->query('SELECT * FROM test_db')->eq('id', $id),
            ['status' => 'updated']
        );

        $this->assertSame(1, $updated);

        $row = \mini\db()->queryOne("SELECT * FROM test_db WHERE id = ?", [$id]);
        $this->assertSame('updated', $row->status);
    }

    public function testUpdateWithRawSql(): void
    {
        $this->cleanTable();
        \mini\db()->exec("INSERT INTO test_db (name) VALUES ('Original')");
        $id = \mini\db()->lastInsertId();

        $updated = \mini\db()->update(
            \mini\db()->query('SELECT * FROM test_db')->eq('id', $id),
            "name = 'Modified'"
        );

        $this->assertSame(1, $updated);

        $row = \mini\db()->queryOne("SELECT * FROM test_db WHERE id = ?", [$id]);
        $this->assertSame('Modified', $row->name);
    }

    public function testTableExistsWithSqlite(): void
    {
        // SQLite-specific test using sqlite_master
        $exists = (bool) \mini\db()->queryField(
            "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='test_db'"
        );
        $this->assertTrue($exists);

        $notExists = (bool) \mini\db()->queryField(
            "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='nonexistent_xyz'"
        );
        $this->assertFalse($notExists);
    }

    public function testQuote(): void
    {
        $quoted = \mini\db()->quote("it's a test");
        $this->assertContains("'", $quoted);
        $this->assertContains("it", $quoted);
    }

    public function testQuoteIdentifier(): void
    {
        $quoted = \mini\db()->quoteIdentifier('table_name');
        // SQLite uses double quotes
        $this->assertSame('"table_name"', $quoted);
    }

    public function testQueryWithHydration(): void
    {
        $this->cleanTable();
        \mini\db()->exec("INSERT INTO test_db (name) VALUES ('Entity')");

        // Hydrator receives spread column values (like PDO::FETCH_FUNC)
        $rows = \mini\db()->query('SELECT id, name FROM test_db')
            ->withHydrator(fn($id, $name) => (object) ['id' => $id, 'name' => $name])
            ->toArray();

        $this->assertCount(1, $rows);
        $this->assertSame('Entity', $rows[0]->name);
    }

    public function testLastInsertId(): void
    {
        $this->cleanTable();
        \mini\db()->exec("INSERT INTO test_db (name) VALUES ('Test')");
        $id = \mini\db()->lastInsertId();

        $this->assertNotNull($id);
    }
};

exit($test->run());
