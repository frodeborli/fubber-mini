<?php
/**
 * Benchmark OptimizingTable on worst-case scenarios
 *
 * Tests O(n*m) operations with unindexed tables to verify optimization helps.
 *
 * Usage: php tests/Table/OptimizingTable.benchmark.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use mini\Table\GeneratorTable;
use mini\Table\InMemoryTable;
use mini\Table\ColumnDef;
use mini\Table\Types\ColumnType;
use mini\Table\Types\IndexType;
use mini\Table\Wrappers\SqlIntersectTable;
use mini\Table\Wrappers\SqlExceptTable;
use mini\Table\Wrappers\OptimizingTable;

// ============================================================================
// Helpers
// ============================================================================

function createUnindexedTable(int $size, int $offset = 0): GeneratorTable
{
    return new GeneratorTable(
        function () use ($size, $offset) {
            for ($i = 1; $i <= $size; $i++) {
                yield $i => (object)[
                    'id' => $offset + $i,
                    'name' => "Item " . ($offset + $i),
                    'value' => ($offset + $i) * 10,
                ];
            }
        },
        new ColumnDef('id', ColumnType::Int),      // No index!
        new ColumnDef('name', ColumnType::Text),
        new ColumnDef('value', ColumnType::Int),
    );
}

function createIndexedTable(int $size, int $offset = 0): InMemoryTable
{
    $table = new InMemoryTable(
        new ColumnDef('id', ColumnType::Int, IndexType::Primary),
        new ColumnDef('name', ColumnType::Text),
        new ColumnDef('value', ColumnType::Int),
    );

    for ($i = 1; $i <= $size; $i++) {
        $table->insert([
            'id' => $offset + $i,
            'name' => "Item " . ($offset + $i),
            'value' => ($offset + $i) * 10,
        ]);
    }

    return $table;
}

function benchmark(string $name, callable $fn): float
{
    // Warmup
    $fn();

    // Measure
    $start = microtime(true);
    $result = $fn();
    $elapsed = microtime(true) - $start;

    printf("%-50s %8.3f ms\n", $name, $elapsed * 1000);
    return $elapsed;
}

function benchmarkIteration(string $name, callable $fn): array
{
    $start = microtime(true);
    $count = 0;
    foreach ($fn() as $_) {
        $count++;
    }
    $elapsed = microtime(true) - $start;

    printf("%-50s %8.3f ms (%d rows)\n", $name, $elapsed * 1000, $count);
    return [$elapsed, $count];
}

// ============================================================================
// Benchmarks
// ============================================================================

echo "=============================================================================\n";
echo "OptimizingTable Benchmark - Worst Case Scenarios\n";
echo "=============================================================================\n\n";

$sizes = [100, 500, 1000];

foreach ($sizes as $n) {
    echo "--- Tables: {$n} x {$n} rows ---\n\n";

    // Create tables with 50% overlap
    $halfN = intdiv($n, 2);

    // Unindexed tables (GeneratorTable)
    $leftUnindexed = createUnindexedTable($n, 0);           // IDs 1..n
    $rightUnindexed = createUnindexedTable($n, $halfN);     // IDs halfN+1..n+halfN

    // Indexed tables (InMemoryTable with SQLite)
    $leftIndexed = createIndexedTable($n, 0);
    $rightIndexed = createIndexedTable($n, $halfN);

    echo "INTERSECT (50% overlap expected):\n";

    // Baseline: indexed tables
    benchmarkIteration("  Indexed (InMemoryTable)", function () use ($leftIndexed, $rightIndexed) {
        return new SqlIntersectTable($leftIndexed, $rightIndexed);
    });

    // Unindexed without OptimizingTable (would be O(n*m))
    // Skip for large sizes - too slow
    if ($n <= 100) {
        benchmarkIteration("  Unindexed raw (O(n*m))", function () use ($leftUnindexed, $rightUnindexed) {
            // Manually do without OptimizingTable by recreating tables
            $left = createUnindexedTable(100, 0);
            $right = createUnindexedTable(100, 50);
            $count = 0;
            foreach ($left as $row) {
                $member = (object)['id' => $row->id, 'name' => $row->name, 'value' => $row->value];
                if ($right->has($member)) {
                    $count++;
                }
            }
            return (function() use ($count) { for ($i = 0; $i < $count; $i++) yield $i; })();
        });
    } else {
        printf("  %-48s %s\n", "Unindexed raw (O(n*m))", "SKIPPED (too slow)");
    }

    // With OptimizingTable (should build index and be fast)
    benchmarkIteration("  Unindexed + OptimizingTable", function () use ($leftUnindexed, $rightUnindexed) {
        return new SqlIntersectTable($leftUnindexed, $rightUnindexed);
    });

    echo "\nEXCEPT (50% expected):\n";

    // Baseline: indexed tables
    benchmarkIteration("  Indexed (InMemoryTable)", function () use ($leftIndexed, $rightIndexed) {
        return new SqlExceptTable($leftIndexed, $rightIndexed);
    });

    // With OptimizingTable
    benchmarkIteration("  Unindexed + OptimizingTable", function () use ($leftUnindexed, $rightUnindexed) {
        return new SqlExceptTable($leftUnindexed, $rightUnindexed);
    });

    echo "\nhas() stress test ({$n} calls):\n";

    // Indexed has()
    benchmark("  Indexed has() x {$n}", function () use ($rightIndexed, $n) {
        for ($i = 1; $i <= $n; $i++) {
            $member = (object)['id' => $i, 'name' => "Item $i", 'value' => $i * 10];
            $rightIndexed->has($member);
        }
    });

    // Unindexed with OptimizingTable
    benchmark("  OptimizingTable has() x {$n}", function () use ($n) {
        $table = createUnindexedTable($n);
        $opt = OptimizingTable::from($table)->withExpectedHasCalls($n);

        for ($i = 1; $i <= $n; $i++) {
            $member = (object)['id' => $i, 'name' => "Item $i", 'value' => $i * 10];
            $opt->has($member);
        }
    });

    // Show optimization state
    $table = createUnindexedTable($n);
    $opt = OptimizingTable::from($table)->withExpectedHasCalls($n);
    for ($i = 1; $i <= min(10, $n); $i++) {
        $member = (object)['id' => $i, 'name' => "Item $i", 'value' => $i * 10];
        $opt->has($member);
    }
    $state = $opt->getOptimizationState();
    printf("  OptimizingTable state: strategy=%s, indexSize=%s\n",
        $state['hasStrategy'],
        $state['hasIndexSize'] ?? 'null'
    );

    echo "\n";
}

echo "=============================================================================\n";
echo "Interpretation:\n";
echo "- Indexed tables use SQLite under the hood (fast)\n";
echo "- Unindexed + OptimizingTable should approach indexed performance\n";
echo "- Raw unindexed is O(n*m) - skipped for large n to avoid waiting\n";
echo "=============================================================================\n";
