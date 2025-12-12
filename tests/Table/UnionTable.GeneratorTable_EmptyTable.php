<?php
/**
 * Test UnionTable with GeneratorTable (left) + EmptyTable (right)
 *
 * When right side is empty, all results come from left side.
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

        $generator = new GeneratorTable(fn() => yield from $data);

        $empty = new EmptyTable(
            new ColumnDef('id'),
            new ColumnDef('name'),
            new ColumnDef('age'),
            new ColumnDef('dept'),
        );

        return $generator->union($empty);
    }
};

exit($test->run());
