<?php
/**
 * Test GeneratorTable wrapping InMemoryTable
 *
 * This tests the "userspace" implementation where filter methods use
 * FilteredTable wrappers instead of optimized index-based lookups.
 *
 * By wrapping InMemoryTable in GeneratorTable, we ensure all filter
 * operations go through AbstractTable's default implementations.
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/_TableImplementationTest.php';

use mini\testing\TableImplementationTest;
use mini\Table\InMemoryTable;
use mini\Table\GeneratorTable;
use mini\Table\ColumnDef;
use mini\Table\Types\ColumnType;
use mini\Table\Types\IndexType;
use mini\Table\Contracts\TableInterface;

$test = new class extends TableImplementationTest {

    protected function createTable(): TableInterface
    {
        // First create InMemoryTable with test data
        $source = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('name', ColumnType::Text, IndexType::Index),
            new ColumnDef('age', ColumnType::Int),
            new ColumnDef('dept', ColumnType::Text),
        );

        foreach ($this->getTestData() as $row) {
            $source->insert((array) $row);
        }

        // Wrap in GeneratorTable - this forces all filter operations
        // to go through AbstractTable's default (wrapper-based) implementations
        return new GeneratorTable(
            function () use ($source) {
                foreach ($source as $id => $row) {
                    yield $id => $row;
                }
            },
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('name', ColumnType::Text),
            new ColumnDef('age', ColumnType::Int),
            new ColumnDef('dept', ColumnType::Text),
        );
    }
};

exit($test->run());
