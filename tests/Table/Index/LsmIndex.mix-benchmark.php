<?php
/**
 * Benchmark LsmIndex with various insert/range-search mixes
 *
 * Usage: php LsmIndex.mix-benchmark.php [inserts=100000]
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

use mini\Table\Index\LsmIndex;

$totalInserts = (int)($argv[1] ?? 100_000);

function formatTime(float $ms): string {
    if ($ms >= 1000) return sprintf('%.2fs', $ms / 1000);
    return sprintf('%.1fms', $ms);
}

function formatRate(float $perSec): string {
    if ($perSec >= 1_000_000) return sprintf('%.1fM/s', $perSec / 1_000_000);
    if ($perSec >= 1_000) return sprintf('%.0fK/s', $perSec / 1_000);
    return sprintf('%.0f/s', $perSec);
}

function runBenchmark(int $totalInserts, float $rangePercent): array {
    $index = new LsmIndex();

    $rangeInterval = $rangePercent > 0 ? (int)(100 / $rangePercent) : 0;

    $insertTime = 0;
    $rangeTime = 0;
    $rangeCount = 0;
    $totalRangeRows = 0;
    $layerCreations = 0;
    $sorts = 0;

    // Track events via logger
    LsmIndex::$logger = function($msg) use (&$layerCreations, &$sorts) {
        if (str_contains($msg, 'Creating inner')) $layerCreations++;
        if (str_contains($msg, 'Sorting')) $sorts++;
    };

    for ($i = 0; $i < $totalInserts; $i++) {
        $key = sprintf("%012d", mt_rand(0, $totalInserts * 10));

        $start = hrtime(true);
        $index->insert($key, $i);
        $insertTime += hrtime(true) - $start;

        // Do bounded range search at interval (~10% of keyspace)
        if ($rangeInterval > 0 && ($i + 1) % $rangeInterval === 0) {
            $from = sprintf('%012d', mt_rand(0, $totalInserts * 9));
            $to = sprintf('%012d', (int)$from + $totalInserts);
            $start = hrtime(true);
            $rows = 0;
            foreach ($index->range(start: $from, end: $to) as $rowId) {
                $rows++;
            }
            $rangeTime += hrtime(true) - $start;
            $rangeCount++;
            $totalRangeRows += $rows;
        }
    }

    LsmIndex::$logger = null;

    // Final range scan
    $start = hrtime(true);
    $finalRows = 0;
    foreach ($index->range() as $rowId) {
        $finalRows++;
    }
    $finalRangeTime = (hrtime(true) - $start) / 1_000_000;

    return [
        'insert_ms' => $insertTime / 1_000_000,
        'range_ms' => $rangeTime / 1_000_000,
        'range_count' => $rangeCount,
        'total_range_rows' => $totalRangeRows,
        'final_range_ms' => $finalRangeTime,
        'final_rows' => $finalRows,
        'unique_keys' => count($index),
        'layer_creations' => $layerCreations,
        'sorts' => $sorts,
    ];
}

echo "LsmIndex Insert/Range Mix Benchmark\n";
echo "====================================\n";
echo "Total inserts: " . number_format($totalInserts) . "\n\n";

$mixes = [
    '0% (baseline)' => 0,
    '0.1%' => 0.1,
    '1%' => 1,
    '10%' => 10,
];

$results = [];
foreach ($mixes as $label => $percent) {
    echo "Running $label mix... ";
    flush();
    $start = hrtime(true);
    $results[$label] = runBenchmark($totalInserts, $percent);
    $elapsed = (hrtime(true) - $start) / 1e9;
    echo round($elapsed, 1) . "s\n";
}

echo "\n";

// Table 1: Time breakdown
echo "TIME BREAKDOWN\n";
echo str_repeat("-", 85) . "\n";
printf("%-15s | %-12s | %-12s | %-12s | %-12s | %-12s\n",
    "Mix", "Insert", "Mid-Ranges", "Final Range", "Total", "Insert Rate");
echo str_repeat("-", 85) . "\n";

foreach ($results as $label => $r) {
    $total = $r['insert_ms'] + $r['range_ms'] + $r['final_range_ms'];
    $insertRate = $totalInserts / ($r['insert_ms'] / 1000);
    printf("%-15s | %-12s | %-12s | %-12s | %-12s | %-12s\n",
        $label,
        formatTime($r['insert_ms']),
        formatTime($r['range_ms']),
        formatTime($r['final_range_ms']),
        formatTime($total),
        formatRate($insertRate)
    );
}

echo "\n";

// Table 2: Range scan details
echo "RANGE SCAN DETAILS\n";
echo str_repeat("-", 75) . "\n";
printf("%-15s | %-12s | %-15s | %-12s | %-12s\n",
    "Mix", "# Scans", "Rows Scanned", "Final Rows", "Unique Keys");
echo str_repeat("-", 75) . "\n";

foreach ($results as $label => $r) {
    printf("%-15s | %-12s | %-15s | %-12s | %-12s\n",
        $label,
        number_format($r['range_count']),
        number_format($r['total_range_rows']),
        number_format($r['final_rows']),
        number_format($r['unique_keys'])
    );
}

echo "\n";

// Table 3: Internal operations
echo "INTERNAL OPERATIONS\n";
echo str_repeat("-", 55) . "\n";
printf("%-15s | %-15s | %-15s\n",
    "Mix", "Layer Creates", "Sort Ops");
echo str_repeat("-", 55) . "\n";

foreach ($results as $label => $r) {
    printf("%-15s | %-15s | %-15s\n",
        $label,
        number_format($r['layer_creations']),
        number_format($r['sorts'])
    );
}

echo "\n";

// Analysis
echo "ANALYSIS\n";
echo str_repeat("-", 55) . "\n";
$baseline = $results['0% (baseline)'];
foreach ($results as $label => $r) {
    if ($label === '0% (baseline)') continue;
    $total = $r['insert_ms'] + $r['range_ms'] + $r['final_range_ms'];
    $baseTotal = $baseline['insert_ms'] + $baseline['range_ms'] + $baseline['final_range_ms'];
    $overhead = (($total / $baseTotal) - 1) * 100;
    printf("%-15s: %.1f%% overhead vs baseline\n", $label, $overhead);
}

echo "\nDone.\n";
