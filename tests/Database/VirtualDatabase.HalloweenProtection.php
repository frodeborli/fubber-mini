<?php
/**
 * Regression tests: a mutation must not read its own writes
 *
 * `INSERT INTO t SELECT ... FROM t` reads the table it writes. Because
 * executeSelect() is lazy, inserting while iterating fed the new rows back
 * into the scan and the statement never terminated - it grew the table
 * until memory ran out. This is the classic "Halloween problem"; every SQL
 * engine solves it by buffering the source before applying any change.
 *
 * The statement is legitimate SQL, so the fix is to make it terminate with
 * the correct result (SQLite, PostgreSQL and MySQL all return the doubled
 * table), not to reject it. VirtualDatabase::setMaxMaterializedRows() caps
 * the buffer so a genuinely enormous source fails loudly instead of
 * exhausting memory.
 *
 * UPDATE and DELETE with self-referencing subqueries were verified against
 * the sqlite3 oracle and were already correct; they are covered here to
 * keep them that way.
 *
 * Safety note: this file caps its own memory. Reintroducing the bug makes
 * these statements allocate without bound, and an unlimited CLI
 * memory_limit (the default here) would hang the whole suite rather than
 * fail this file. With the cap it dies quickly and loudly instead.
 */

ini_set('memory_limit', '256M');

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Test;
use mini\Database\VirtualDatabase;
use mini\Table\InMemoryTable;
use mini\Table\ColumnDef;
use mini\Table\Types\ColumnType;

$test = new class extends Test {

    private function createVdb(int $rows = 3): VirtualDatabase
    {
        $table = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int),
            new ColumnDef('n', ColumnType::Int),
        );
        for ($i = 1; $i <= $rows; $i++) {
            $table->insert(['id' => $i, 'n' => $i]);
        }

        $vdb = new VirtualDatabase();
        $vdb->registerTable('t', $table);
        return $vdb;
    }

    public function testInsertSelectFromSameTableTerminates(): void
    {
        $vdb = $this->createVdb();

        $inserted = $vdb->exec('INSERT INTO t (id, n) SELECT id + 100, n FROM t');

        // Exactly the rows present when the statement began - not one more
        $this->assertSame(3, $inserted);
        $this->assertCount(6, iterator_to_array($vdb->query('SELECT id FROM t')));
    }

    public function testInsertSelectFromSameTableWithWhereTerminates(): void
    {
        $vdb = $this->createVdb();

        $inserted = $vdb->exec('INSERT INTO t (id, n) SELECT id + 100, n FROM t WHERE n > 1');

        $this->assertSame(2, $inserted);
        $this->assertCount(5, iterator_to_array($vdb->query('SELECT id FROM t')));
    }

    public function testInsertSelectFromOtherTableStillWorks(): void
    {
        $vdb = $this->createVdb();
        $source = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int),
            new ColumnDef('n', ColumnType::Int),
        );
        $source->insert(['id' => 50, 'n' => 50]);
        $vdb->registerTable('src', $source);

        $inserted = $vdb->exec('INSERT INTO t (id, n) SELECT id, n FROM src');

        $this->assertSame(1, $inserted);
        $this->assertCount(4, iterator_to_array($vdb->query('SELECT id FROM t')));
    }

    public function testMaxMaterializedRowsCapThrowsActionableError(): void
    {
        $vdb = $this->createVdb();
        $vdb->setMaxMaterializedRows(2);

        try {
            $vdb->exec('INSERT INTO t (id, n) SELECT id + 100, n FROM t');
            $this->fail('Expected the buffered-row cap to be enforced');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('buffered rows', $e->getMessage());
            $this->assertStringContainsString('setMaxMaterializedRows', $e->getMessage());
        }

        // The statement failed before writing anything
        $this->assertCount(3, iterator_to_array($vdb->query('SELECT id FROM t')));
    }

    public function testMaxMaterializedRowsCanBeDisabled(): void
    {
        $vdb = $this->createVdb();
        $vdb->setMaxMaterializedRows(null);

        $this->assertSame(3, $vdb->exec('INSERT INTO t (id, n) SELECT id + 100, n FROM t'));
    }

    public function testMaxMaterializedRowsRejectsNonsenseValues(): void
    {
        $vdb = $this->createVdb();
        $this->assertThrows(
            fn() => $vdb->setMaxMaterializedRows(0),
            \InvalidArgumentException::class
        );
    }

    public function testUpdateRowContextExpressionIsBufferedNotAppliedDuringScan(): void
    {
        $vdb = $this->createVdb();

        // Each row's new value must be computed from the pre-update table.
        // Applying mid-scan would let row 1's new n change what row 2 reads.
        $vdb->exec('UPDATE t SET n = n * 10');

        $values = array_map(fn($r) => $r->n, iterator_to_array($vdb->query('SELECT n FROM t ORDER BY id')));
        $this->assertSame([10, 20, 30], $values);
    }

    public function testUpdateCapIsEnforced(): void
    {
        $vdb = $this->createVdb();
        $vdb->setMaxMaterializedRows(2);

        $this->assertThrows(
            fn() => $vdb->exec('UPDATE t SET n = n + 1'),
            \RuntimeException::class
        );

        // Nothing was written - the cap tripped during the read phase
        $values = array_map(fn($r) => $r->n, iterator_to_array($vdb->query('SELECT n FROM t ORDER BY id')));
        $this->assertSame([1, 2, 3], $values);
    }

    public function testReplaceSelectAppliesDeleteBeforeItsInsert(): void
    {
        $table = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, \mini\Table\Types\IndexType::Primary),
            new ColumnDef('n', ColumnType::Int),
        );
        $table->insert(['id' => 1, 'n' => 1]);
        $table->insert(['id' => 2, 'n' => 2]);
        $vdb = new VirtualDatabase();
        $vdb->registerTable('t', $table);

        // Replacing every row with itself must leave the row count unchanged:
        // each logged delete has to be applied before its matching insert.
        $vdb->exec('REPLACE INTO t (id, n) SELECT id, n + 10 FROM t');

        $rows = iterator_to_array($vdb->query('SELECT id, n FROM t ORDER BY id'));
        $this->assertCount(2, $rows);
        $this->assertSame(11, $rows[0]->n);
        $this->assertSame(12, $rows[1]->n);
    }

    public function testUpdateWithSelfReferencingSubquery(): void
    {
        $vdb = $this->createVdb();

        // Matches sqlite3: the subquery sees the table as it was
        $vdb->exec('UPDATE t SET n = n + (SELECT COUNT(*) FROM t)');

        $values = array_map(fn($r) => $r->n, iterator_to_array($vdb->query('SELECT n FROM t ORDER BY id')));
        $this->assertSame([4, 5, 6], $values);
    }

    public function testDeleteWithSelfReferencingSubquery(): void
    {
        $vdb = $this->createVdb();

        $vdb->exec('DELETE FROM t WHERE id IN (SELECT id FROM t WHERE n > 1)');

        $ids = array_map(fn($r) => $r->id, iterator_to_array($vdb->query('SELECT id FROM t')));
        $this->assertSame([1], $ids);
    }
};

exit($test->run());
