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
use mini\Table\TableInterface;

$test = new class extends TableImplementationTest {

    protected function createTable(): TableInterface
    {
        $data = $this->getTestData();

        $empty = new EmptyTable(
            new ColumnDef('id'),
            new ColumnDef('name'),
            new ColumnDef('age'),
            new ColumnDef('dept'),
        );

        $generator = new GeneratorTable(fn() => yield from $data);

        return $empty->union($generator);
    }
};

exit($test->run());
