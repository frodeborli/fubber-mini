<?php
/**
 * Test that method call ordering doesn't affect results
 *
 * The fluent API should be commutative - different orderings of the same
 * operations should produce identical results.
 *
 * For example:
 *   $table->eq('x', 1)->order('y ASC')->lt('z', 5)
 *   $table->lt('z', 5)->eq('x', 1)->order('y ASC')
 *   $table->order('y ASC')->lt('z', 5)->eq('x', 1)
 *
 * Should all produce the same rows in the same order.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use mini\Test;
use mini\Table\GeneratorTable;
use mini\Table\Contracts\TableInterface;
use mini\Table\ColumnDef;
use mini\Table\Types\ColumnType;
use mini\Table\Utility\Set;

$test = new class extends Test {

    private TableInterface $source;

    protected function setUp(): void
    {
        // Create test data with variety
        $rows = [];
        for ($i = 1; $i <= 50; $i++) {
            $rows[] = (object)[
                'id' => $i,
                'name' => ['Alice', 'Bob', 'Carol', 'Dave', 'Eve'][$i % 5],
                'age' => 20 + ($i % 30),
                'dept' => ['Engineering', 'Sales', 'Marketing', 'HR'][$i % 4],
                'score' => 50.0 + ($i * 1.5),
                'active' => $i % 2,
            ];
        }

        $this->source = new GeneratorTable(
            fn() => yield from $rows,
            new ColumnDef('id', ColumnType::Int),
            new ColumnDef('name', ColumnType::Text),
            new ColumnDef('age', ColumnType::Int),
            new ColumnDef('dept', ColumnType::Text),
            new ColumnDef('score', ColumnType::Float),
            new ColumnDef('active', ColumnType::Int),
        );
    }

    /**
     * Compare two tables for identical results (order matters)
     */
    private function assertSameResults(TableInterface $a, TableInterface $b, string $message): void
    {
        $rowsA = [];
        foreach ($a as $row) {
            $rowsA[] = (array) $row;
        }

        $rowsB = [];
        foreach ($b as $row) {
            $rowsB[] = (array) $row;
        }

        $this->assertEquals($rowsA, $rowsB, $message);
    }

    /**
     * Apply a sequence of operations to a table
     *
     * @param array $ops Array of [method, ...args]
     */
    private function applyOps(TableInterface $table, array $ops): TableInterface
    {
        foreach ($ops as $op) {
            $method = array_shift($op);
            $table = $table->$method(...$op);
        }
        return $table;
    }

    /**
     * Generate all permutations of an array
     */
    private function permutations(array $items): array
    {
        if (count($items) <= 1) {
            return [$items];
        }

        $result = [];
        foreach ($items as $i => $item) {
            $rest = $items;
            unset($rest[$i]);
            foreach ($this->permutations(array_values($rest)) as $perm) {
                $result[] = [$item, ...$perm];
            }
        }
        return $result;
    }

    /**
     * Test all permutations of operations produce same results
     */
    private function testPermutations(array $ops, string $description): void
    {
        $perms = $this->permutations($ops);
        $reference = $this->applyOps($this->source, $perms[0]);

        foreach (array_slice($perms, 1) as $i => $perm) {
            $result = $this->applyOps($this->source, $perm);
            $this->assertSameResults(
                $reference,
                $result,
                "$description: permutation $i differs from reference"
            );
        }
    }

    // =========================================================================
    // Two-operation combinations
    // =========================================================================

    public function testEqAndLt(): void
    {
        $this->testPermutations([
            ['eq', 'active', 1],
            ['lt', 'age', 40],
        ], 'eq + lt');
    }

    public function testEqAndGt(): void
    {
        $this->testPermutations([
            ['eq', 'dept', 'Engineering'],
            ['gt', 'score', 60.0],
        ], 'eq + gt');
    }

    public function testLtAndGte(): void
    {
        $this->testPermutations([
            ['lt', 'age', 45],
            ['gte', 'score', 55.0],
        ], 'lt + gte');
    }

    public function testEqAndOrder(): void
    {
        $this->testPermutations([
            ['eq', 'active', 1],
            ['order', 'age ASC'],
        ], 'eq + order');
    }

    public function testLtAndOrder(): void
    {
        $this->testPermutations([
            ['lt', 'age', 35],
            ['order', 'name DESC'],
        ], 'lt + order');
    }

    public function testEqAndLimit(): void
    {
        $ops1 = [['eq', 'active', 1], ['limit', 5]];
        $ops2 = [['limit', 5], ['eq', 'active', 1]];

        // Note: limit before filter vs after filter may differ
        // We test that each ordering is internally consistent
        $a = $this->applyOps($this->source, $ops1);
        $b = $this->applyOps($this->source, $ops1);
        $this->assertSameResults($a, $b, 'eq->limit consistent');

        $c = $this->applyOps($this->source, $ops2);
        $d = $this->applyOps($this->source, $ops2);
        $this->assertSameResults($c, $d, 'limit->eq consistent');
    }

    public function testOrderAndLimit(): void
    {
        $this->testPermutations([
            ['order', 'score DESC'],
            ['limit', 10],
        ], 'order + limit');
    }

    public function testLikeAndEq(): void
    {
        $this->testPermutations([
            ['like', 'name', 'A%'],
            ['eq', 'active', 1],
        ], 'like + eq');
    }

    // =========================================================================
    // Three-operation combinations
    // =========================================================================

    public function testEqLtOrder(): void
    {
        $this->testPermutations([
            ['eq', 'active', 1],
            ['lt', 'age', 40],
            ['order', 'score ASC'],
        ], 'eq + lt + order');
    }

    public function testGtLteEq(): void
    {
        $this->testPermutations([
            ['gt', 'age', 25],
            ['lte', 'score', 80.0],
            ['eq', 'active', 0],
        ], 'gt + lte + eq');
    }

    public function testEqOrderLimit(): void
    {
        $this->testPermutations([
            ['eq', 'dept', 'Sales'],
            ['order', 'age DESC'],
            ['limit', 5],
        ], 'eq + order + limit');
    }

    public function testLtGtOrder(): void
    {
        $this->testPermutations([
            ['lt', 'age', 45],
            ['gt', 'score', 55.0],
            ['order', 'name ASC'],
        ], 'lt + gt + order');
    }

    public function testMultipleFiltersOnSameColumn(): void
    {
        $this->testPermutations([
            ['gt', 'age', 25],
            ['lt', 'age', 40],
            ['order', 'age ASC'],
        ], 'gt(age) + lt(age) + order');
    }

    // =========================================================================
    // Four-operation combinations
    // =========================================================================

    public function testEqLtOrderLimit(): void
    {
        $this->testPermutations([
            ['eq', 'active', 1],
            ['lt', 'age', 40],
            ['order', 'score DESC'],
            ['limit', 5],
        ], 'eq + lt + order + limit');
    }

    public function testMultipleFiltersOrderLimit(): void
    {
        $this->testPermutations([
            ['gt', 'age', 22],
            ['lte', 'score', 90.0],
            ['order', 'name ASC'],
            ['limit', 10],
        ], 'gt + lte + order + limit');
    }

    // =========================================================================
    // With offset
    // =========================================================================

    public function testOrderOffsetLimit(): void
    {
        $this->testPermutations([
            ['order', 'id ASC'],
            ['offset', 5],
            ['limit', 10],
        ], 'order + offset + limit');
    }

    public function testEqOrderOffsetLimit(): void
    {
        $this->testPermutations([
            ['eq', 'active', 1],
            ['order', 'age ASC'],
            ['offset', 3],
            ['limit', 5],
        ], 'eq + order + offset + limit');
    }

    // =========================================================================
    // With columns projection
    // =========================================================================

    public function testColumnsAndFilter(): void
    {
        $this->testPermutations([
            ['columns', 'id', 'name', 'age'],
            ['eq', 'age', 25],
        ], 'columns + eq');
    }

    public function testColumnsFilterOrder(): void
    {
        $this->testPermutations([
            ['columns', 'id', 'name', 'score'],
            ['gt', 'score', 60.0],
            ['order', 'score DESC'],
        ], 'columns + gt + order');
    }

    // =========================================================================
    // With IN operator
    // =========================================================================

    public function testInAndEq(): void
    {
        $set = new Set('name', ['Alice', 'Bob', 'Carol']);

        $ops = [
            ['in', 'name', $set],
            ['eq', 'active', 1],
        ];

        $this->testPermutations($ops, 'in + eq');
    }

    public function testInOrderLimit(): void
    {
        $set = new Set('dept', ['Engineering', 'Sales']);

        $ops = [
            ['in', 'dept', $set],
            ['order', 'score DESC'],
            ['limit', 5],
        ];

        $this->testPermutations($ops, 'in + order + limit');
    }

    // =========================================================================
    // Complex combinations
    // =========================================================================

    public function testComplexChain(): void
    {
        $this->testPermutations([
            ['gt', 'age', 22],
            ['lt', 'age', 45],
            ['eq', 'active', 1],
            ['order', 'score DESC'],
        ], 'complex: gt + lt + eq + order');
    }

    public function testAllFilterTypes(): void
    {
        // Use different columns to avoid conflicts
        $this->testPermutations([
            ['eq', 'active', 1],
            ['gt', 'age', 20],
            ['lte', 'score', 100.0],
        ], 'eq + gt + lte on different columns');
    }
};

exit($test->run());
