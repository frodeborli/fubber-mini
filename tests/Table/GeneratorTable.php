<?php
/**
 * Test GeneratorTable implementation
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/_TableImplementationTest.php';

use mini\testing\TableImplementationTest;
use mini\Table\GeneratorTable;
use mini\Table\TableInterface;

$test = new class extends TableImplementationTest {

    protected function createTable(): TableInterface
    {
        return new GeneratorTable(fn() => yield from $this->getTestData());
    }

    // =========================================================================
    // GeneratorTable-specific tests
    // =========================================================================

    public function testInfersColumnsFromFirstRow(): void
    {
        $table = new GeneratorTable(fn() => yield from [
            1 => (object)['foo' => 1, 'bar' => 'x'],
        ]);

        $cols = array_keys($table->getColumns());
        $this->assertSame(['foo', 'bar'], $cols);
    }

    public function testEmptyGeneratorYieldsNoColumns(): void
    {
        $table = new GeneratorTable(fn() => yield from []);

        $this->assertSame([], array_keys($table->getColumns()));
        $this->assertSame(0, $table->count());
    }

    public function testClosureIsCalledFreshEachIteration(): void
    {
        $callCount = 0;
        $table = new GeneratorTable(function() use (&$callCount) {
            $callCount++;
            yield 1 => (object)['id' => 1];
        });

        // First call happens in constructor to infer columns
        $this->assertSame(1, $callCount);

        // Each iteration should call the closure again
        iterator_to_array($table);
        $this->assertSame(2, $callCount);

        iterator_to_array($table);
        $this->assertSame(3, $callCount);
    }
};

exit($test->run());
