<?php
/**
 * Test InMemoryTable (SQLite-backed oracle) implementation
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/_TableImplementationTest.php';

use mini\testing\TableImplementationTest;
use mini\Table\InMemoryTable;
use mini\Table\ColumnDef;
use mini\Table\ColumnType;
use mini\Table\IndexType;
use mini\Table\TableInterface;

$test = new class extends TableImplementationTest {

    protected function createTable(): TableInterface
    {
        $table = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('name', ColumnType::Text, IndexType::Index),
            new ColumnDef('age', ColumnType::Int),
            new ColumnDef('dept', ColumnType::Text),
        );

        // Insert test data
        foreach ($this->getTestData() as $row) {
            $table->insert((array) $row);
        }

        return $table;
    }

    // =========================================================================
    // InMemoryTable-specific tests
    // =========================================================================

    public function testInsertReturnsRowId(): void
    {
        $table = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('name', ColumnType::Text),
        );

        $id1 = $table->insert(['id' => 100, 'name' => 'Test']);
        $this->assertSame(100, $id1);

        // Auto-increment if id not provided (SQLite behavior)
        $table2 = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('name', ColumnType::Text),
        );
        $id2 = $table2->insert(['name' => 'Test']);
        $this->assertSame(1, $id2);
    }

    public function testUpdateAffectsFilteredRows(): void
    {
        $table = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('status', ColumnType::Text),
        );

        $table->insert(['id' => 1, 'status' => 'active']);
        $table->insert(['id' => 2, 'status' => 'active']);
        $table->insert(['id' => 3, 'status' => 'inactive']);

        // Update only active rows
        $affected = $table->eq('status', 'active')->update(['status' => 'archived']);
        $this->assertSame(2, $affected);

        // Verify
        $statuses = [];
        foreach ($table->order('id') as $row) {
            $statuses[] = $row->status;
        }
        $this->assertSame(['archived', 'archived', 'inactive'], $statuses);
    }

    public function testDeleteRemovesFilteredRows(): void
    {
        $table = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('status', ColumnType::Text),
        );

        $table->insert(['id' => 1, 'status' => 'active']);
        $table->insert(['id' => 2, 'status' => 'active']);
        $table->insert(['id' => 3, 'status' => 'inactive']);

        // Delete inactive rows
        $deleted = $table->eq('status', 'inactive')->delete();
        $this->assertSame(1, $deleted);

        // Verify
        $this->assertSame(2, $table->count());
    }

    public function testMultipleTablesAreIndependent(): void
    {
        $table1 = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
        );
        $table2 = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
        );

        $table1->insert(['id' => 1]);
        $table2->insert(['id' => 100]);

        $this->assertSame(1, $table1->count());
        $this->assertSame(1, $table2->count());

        $ids1 = [];
        foreach ($table1 as $row) {
            $ids1[] = $row->id;
        }
        $this->assertSame([1], $ids1);
    }

    public function testClonedTableSharesData(): void
    {
        $table = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('name', ColumnType::Text),
        );

        $table->insert(['id' => 1, 'name' => 'Alice']);
        $table->insert(['id' => 2, 'name' => 'Bob']);

        // Filter creates a clone
        $filtered = $table->eq('name', 'Alice');

        // Insert into original
        $table->insert(['id' => 3, 'name' => 'Carol']);

        // Clone sees the new data (shares DB)
        $this->assertSame(3, $table->count());

        // But filtered still only matches Alice
        $this->assertSame(1, $filtered->count());
    }
};

exit($test->run());
