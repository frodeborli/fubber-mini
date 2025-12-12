<?php
/**
 * Test UnionTable with GeneratorTable (left) + GeneratorTable (right)
 *
 * Splits test data across two generators. Tests that:
 * - Rows from both sides are included
 * - Duplicate IDs are deduplicated (left side wins)
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/_TableImplementationTest.php';

use mini\testing\TableImplementationTest;
use mini\Table\GeneratorTable;
use mini\Table\TableInterface;

$test = new class extends TableImplementationTest {

    protected function createTable(): TableInterface
    {
        $data = $this->getTestData();

        // Split data: left gets ids 1,2,3; right gets ids 3,4,5
        // id 3 is in both - left side should win
        $leftData = array_filter($data, fn($row) => $row->id <= 3, ARRAY_FILTER_USE_BOTH);
        $rightData = array_filter($data, fn($row) => $row->id >= 3, ARRAY_FILTER_USE_BOTH);

        $left = new GeneratorTable(fn() => yield from $leftData);
        $right = new GeneratorTable(fn() => yield from $rightData);

        return $left->union($right);
    }
};

exit($test->run());
