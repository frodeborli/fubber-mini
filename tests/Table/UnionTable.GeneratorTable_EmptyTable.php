<?php
/**
 * Test UnionTable with GeneratorTable (left) + EmptyTable (right)
 *
 * When right side is empty, all results come from left side.
 */

require __DIR__ . '/../../ensure-autoloader.php';
require_once __DIR__ . '/_TableImplementationTest.php';

use mini\testing\TableImplementationTest;
use mini\Table\Utility\EmptyTable;
use mini\Table\GeneratorTable;
use mini\Table\ColumnDef;
use mini\Table\Types\ColumnType;
use mini\Table\Types\IndexType;
use mini\Table\Contracts\TableInterface;

$test = new class extends TableImplementationTest {

    protected function createTable(): TableInterface
    {
        $data = $this->getTestData();
        $columns = [
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('name', ColumnType::Text),
            new ColumnDef('age', ColumnType::Int),
            new ColumnDef('dept', ColumnType::Text),
        ];

        $generator = new GeneratorTable(fn() => yield from $data, ...$columns);
        $empty = new EmptyTable(...$columns);

        return $generator->union($empty);
    }
};

exit($test->run());
