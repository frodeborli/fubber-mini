<?php
/**
 * Regression tests: a CTE must not destroy a registered table it shadows
 *
 * SQL:2003 lets a CTE shadow a table of the same name for the duration of
 * the statement. VirtualDatabase implements that by writing the CTE into
 * its table registry, and previously cleaned up with an unconditional
 * unset() - so `WITH users AS (...) SELECT ... FROM users` returned the
 * right answer and then left the real `users` table permanently
 * unregistered. Every later query against it failed with
 * "Table not found", for the life of the VirtualDatabase instance.
 *
 * The recursive path had its own copy of the same bug: executeRecursiveCte()
 * registers a working table per iteration and unset() it at the end, before
 * executeWithStatement()'s cleanup ever runs.
 *
 * These tests assert the invariant on both paths: after a statement whose
 * CTE shadows a registered table, the registry must be exactly as it was.
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Test;
use mini\Database\VirtualDatabase;
use mini\Table\InMemoryTable;
use mini\Table\ColumnDef;
use mini\Table\Types\ColumnType;
use mini\Table\Types\IndexType;

$test = new class extends Test {

    private function createVdb(): VirtualDatabase
    {
        $table = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('name', ColumnType::Text),
        );
        $table->insert(['id' => 1, 'name' => 'Alice']);
        $table->insert(['id' => 2, 'name' => 'Bob']);
        $table->insert(['id' => 3, 'name' => 'Charlie']);

        $vdb = new VirtualDatabase();
        $vdb->registerTable('users', $table);
        return $vdb;
    }

    public function testCteShadowsRegisteredTableWithinStatement(): void
    {
        $vdb = $this->createVdb();

        // Inside the statement the CTE wins
        $rows = iterator_to_array($vdb->query("WITH users AS (SELECT 'shadowed' AS name) SELECT name FROM users"));
        $this->assertCount(1, $rows);
        $this->assertSame('shadowed', $rows[0]->name);
    }

    public function testRegisteredTableSurvivesShadowingCte(): void
    {
        $vdb = $this->createVdb();

        iterator_to_array($vdb->query("WITH users AS (SELECT 'shadowed' AS name) SELECT name FROM users"));

        // ...and the real table is still registered afterwards
        $rows = iterator_to_array($vdb->query('SELECT id, name FROM users'));
        $this->assertCount(3, $rows);
        $this->assertSame('Alice', $rows[0]->name);
    }

    public function testRegisteredTableSurvivesShadowingRecursiveCte(): void
    {
        $vdb = $this->createVdb();

        $rows = iterator_to_array($vdb->query(
            'WITH RECURSIVE users(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM users WHERE n < 3) SELECT n FROM users'
        ));
        $this->assertCount(3, $rows);

        $rows = iterator_to_array($vdb->query('SELECT id, name FROM users'));
        $this->assertCount(3, $rows);
    }

    public function testTableStillListedAfterShadowingCte(): void
    {
        $vdb = $this->createVdb();

        iterator_to_array($vdb->query("WITH users AS (SELECT 'shadowed' AS name) SELECT name FROM users"));

        $this->assertTrue(in_array('users', $vdb->getTableNames(), true), 'users must remain registered');
    }

    public function testNonShadowingCteIsStillCleanedUp(): void
    {
        $vdb = $this->createVdb();

        iterator_to_array($vdb->query("WITH temp_cte AS (SELECT 1 AS n) SELECT n FROM temp_cte"));

        // A CTE that shadows nothing must not linger in the registry
        $this->assertFalse(in_array('temp_cte', $vdb->getTableNames(), true));
        $this->assertThrows(
            fn() => iterator_to_array($vdb->query('SELECT n FROM temp_cte')),
            \RuntimeException::class
        );
    }
};

exit($test->run());
