<?php
/**
 * Test UnionTable with EmptyTable (left) + GeneratorTable (right)
 *
 * When left side is empty, all results come from right side.
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/_TableImplementationTest.php';

use mini\testing\TableImplementationTest;
use mini\Table\EmptyTable;
use mini\Table\GeneratorTable;
use mini\Table\ColumnDef;
use mini\Table\ColumnType;
use mini\Table\IndexType;
use mini\Table\TableInterface;

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

        $empty = new EmptyTable(...$columns);
        $generator = new GeneratorTable(fn() => yield from $data, ...$columns);

        return $empty->union($generator);
    }
};

exit($test->run());
