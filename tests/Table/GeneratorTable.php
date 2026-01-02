<?php
/**
 * Test GeneratorTable implementation
 */

require __DIR__ . '/../../ensure-autoloader.php';
require_once __DIR__ . '/_TableImplementationTest.php';

use mini\testing\TableImplementationTest;
use mini\Table\GeneratorTable;
use mini\Table\Contracts\TableInterface;
use mini\Table\ColumnDef;
use mini\Table\Types\ColumnType;
use mini\Table\Types\IndexType;

$test = new class extends TableImplementationTest {

    protected function createTable(): TableInterface
    {
        return new GeneratorTable(
            fn() => yield from $this->getTestData(),
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('name', ColumnType::Text),
            new ColumnDef('age', ColumnType::Int),
            new ColumnDef('dept', ColumnType::Text),
        );
    }

    // =========================================================================
    // GeneratorTable-specific tests
    // =========================================================================

    public function testRequiresAtLeastOneColumn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new GeneratorTable(fn() => yield from []);
    }

    public function testClosureCallingBehavior(): void
    {
        $callCount = 0;
        $table = new GeneratorTable(
            function() use (&$callCount) {
                $callCount++;
                yield 1 => (object)['id' => 1];
            },
            new ColumnDef('id', ColumnType::Int),
        );

        // Constructor does not call closure
        $this->assertSame(0, $callCount);

        // First full iteration calls closure (then caches result for small tables)
        iterator_to_array($table);
        $this->assertSame(1, $callCount);

        // Subsequent iterations use cached result (no closure call)
        iterator_to_array($table);
        $this->assertSame(1, $callCount);
    }
};

exit($test->run());
