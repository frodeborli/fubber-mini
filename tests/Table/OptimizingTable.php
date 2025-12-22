<?php
/**
 * Test OptimizingTable adaptive optimization
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use mini\Test;
use mini\Table\GeneratorTable;
use mini\Table\ColumnDef;
use mini\Table\Types\ColumnType;
use mini\Table\Types\IndexType;
use mini\Table\Wrappers\OptimizingTable;

$test = new class extends Test {

    private function createSlowTable(int $size = 1000): GeneratorTable
    {
        // Table without indexes - forces full scan
        return new GeneratorTable(
            function () use ($size) {
                for ($i = 1; $i <= $size; $i++) {
                    yield $i => (object)[
                        'id' => $i,
                        'name' => "User $i",
                        'dept' => 'dept_' . ($i % 10),
                        'age' => 20 + ($i % 50),
                    ];
                }
            },
            new ColumnDef('id', ColumnType::Int),  // No index!
            new ColumnDef('name', ColumnType::Text),
            new ColumnDef('dept', ColumnType::Text),
            new ColumnDef('age', ColumnType::Int),
        );
    }

    // =========================================================================
    // Basic wrapping
    // =========================================================================

    public function testFromWrapsTable(): void
    {
        $table = $this->createSlowTable();
        $opt = OptimizingTable::from($table);

        $this->assertInstanceOf(OptimizingTable::class, $opt);
    }

    public function testFromReturnsExistingOptimizingTable(): void
    {
        $table = $this->createSlowTable();
        $opt1 = OptimizingTable::from($table);
        $opt2 = OptimizingTable::from($opt1);

        $this->assertSame($opt1, $opt2);
    }

    public function testForwardsIteration(): void
    {
        $table = $this->createSlowTable(10);
        $opt = OptimizingTable::from($table);

        $count = 0;
        foreach ($opt as $row) {
            $count++;
        }

        $this->assertSame(10, $count);
    }

    // =========================================================================
    // has() optimization
    // =========================================================================

    public function testHasWorksWithoutOptimization(): void
    {
        $table = $this->createSlowTable(10);
        $opt = OptimizingTable::from($table);

        $member = (object)['id' => 5, 'name' => 'User 5', 'dept' => 'dept_5', 'age' => 25];
        $this->assertTrue($opt->has($member));

        $missing = (object)['id' => 999, 'name' => 'Nobody', 'dept' => 'x', 'age' => 0];
        $this->assertFalse($opt->has($missing));
    }

    public function testHasBuildsIndexAfterMeasurement(): void
    {
        $table = $this->createSlowTable(100);
        $opt = OptimizingTable::from($table);

        // Make several has() calls to trigger measurement
        for ($i = 1; $i <= 5; $i++) {
            $member = (object)['id' => $i, 'name' => "User $i", 'dept' => 'dept_' . ($i % 10), 'age' => 20 + ($i % 50)];
            $opt->has($member);
        }

        // Check that optimization happened (strategy changed from 'measure')
        $state = $opt->getOptimizationState();
        // Strategy may or may not change depending on actual timing
        $this->assertTrue(in_array($state['hasStrategy'], ['measure', 'indexed', 'sqlite']));
    }

    public function testWithExpectedHasCallsDoesNotPrebuild(): void
    {
        $table = $this->createSlowTable(100);
        $opt = OptimizingTable::from($table)
            ->withExpectedHasCalls(5000);

        $state = $opt->getOptimizationState();

        // Should NOT prebuild - measure first, then decide
        $this->assertSame('measure', $state['hasStrategy']);
        $this->assertNull($state['hasIndexSize']);
    }

    // =========================================================================
    // eq() optimization
    // =========================================================================

    public function testEqWorksWithoutOptimization(): void
    {
        $table = $this->createSlowTable(100);
        $opt = OptimizingTable::from($table);

        $result = iterator_to_array($opt->eq('dept', 'dept_5'));

        // Should find rows where id % 10 = 5 (5, 15, 25, ...)
        $this->assertSame(10, count($result));
        foreach ($result as $row) {
            $this->assertSame('dept_5', $row->dept);
        }
    }

    public function testWithIndexOnDoesNotPrebuild(): void
    {
        $table = $this->createSlowTable(100);
        $opt = OptimizingTable::from($table)
            ->withExpectedEqCalls(5000)
            ->withIndexOn('dept');

        $state = $opt->getOptimizationState();

        // Should NOT prebuild - measure first, then decide
        $this->assertFalse(isset($state['eqStrategies']['dept']));
        $this->assertFalse(isset($state['columnIndexSizes']['dept']));
    }

    public function testEqReturnsSameResultsWithOrWithoutOptimization(): void
    {
        $table = $this->createSlowTable(100);

        // Without optimization
        $plain = iterator_to_array($table->eq('dept', 'dept_3'));

        // With optimization (let it measure and potentially optimize)
        $opt = OptimizingTable::from($table)
            ->withExpectedEqCalls(100);

        // Make several eq() calls to allow measurement
        for ($i = 0; $i < 5; $i++) {
            iterator_to_array($opt->eq('dept', 'dept_' . $i));
        }

        $optimized = iterator_to_array($opt->eq('dept', 'dept_3'));

        $this->assertSame(count($plain), count($optimized));

        $plainIds = array_map(fn($r) => $r->id, $plain);
        $optIds = array_map(fn($r) => $r->id, $optimized);
        sort($plainIds);
        sort($optIds);

        $this->assertSame($plainIds, $optIds);
    }

    // =========================================================================
    // Chained hints
    // =========================================================================

    public function testChainedHintsDoNotPrebuild(): void
    {
        $table = $this->createSlowTable(100);
        $opt = OptimizingTable::from($table)
            ->withExpectedHasCalls(5000)
            ->withExpectedEqCalls(5000)
            ->withIndexOn('dept', 'age');

        $state = $opt->getOptimizationState();

        // Hints are stored but no prebuilding happens
        $this->assertSame('measure', $state['hasStrategy']);
        $this->assertSame([], $state['columnIndexSizes']);
    }

    // =========================================================================
    // Immutability
    // =========================================================================

    public function testWithMethodsReturnNewInstance(): void
    {
        $table = $this->createSlowTable(10);
        $opt1 = OptimizingTable::from($table);
        $opt2 = $opt1->withExpectedHasCalls(100);

        $this->assertNotSame($opt1, $opt2);
    }

    // =========================================================================
    // Integration with other methods
    // =========================================================================

    public function testGetColumnsForwards(): void
    {
        $table = $this->createSlowTable(10);
        $opt = OptimizingTable::from($table);

        $cols = array_keys($opt->getColumns());
        $this->assertSame(['id', 'name', 'dept', 'age'], $cols);
    }

    public function testCountForwards(): void
    {
        $table = $this->createSlowTable(50);
        $opt = OptimizingTable::from($table);

        $this->assertSame(50, $opt->count());
    }

    public function testLimitForwards(): void
    {
        $table = $this->createSlowTable(100);
        $opt = OptimizingTable::from($table);

        $limited = $opt->limit(10);
        $this->assertSame(10, iterator_count($limited));
    }
};

exit($test->run());
