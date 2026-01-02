<?php
/**
 * Benchmark LsmIndex performance
 */

require __DIR__ . '/../../../ensure-autoloader.php';

use mini\Table\Index\LsmIndex;

function formatNumber(int $n): string {
    if ($n >= 1_000_000) return sprintf('%.1fM', $n / 1_000_000);
    if ($n >= 1_000) return sprintf('%.0fK', $n / 1_000);
    return (string)$n;
}

function formatTime(float $ms): string {
    if ($ms >= 1000) return sprintf('%.2fs', $ms / 1000);
    return sprintf('%.2fms', $ms);
}

function benchmark(callable $fn, int $iterations = 1): float {
    $start = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $fn();
    }
    $end = hrtime(true);
    return ($end - $start) / 1_000_000; // Convert to ms
}

// ============================================================================
// Configuration
// ============================================================================

$sizes = [1_000, 10_000, 100_000, 500_000, 1_000_000];
$mixedIntervals = [10_000, 1_000, 100];

echo "LsmIndex Benchmark\n";
echo str_repeat("=", 70) . "\n\n";

// ============================================================================
// Pure Insert + Range Benchmark
// ============================================================================

echo "1. PURE INSERT THEN RANGE SEARCH\n";
echo str_repeat("-", 70) . "\n";
printf("%-10s | %-12s | %-12s | %-12s | %-10s\n",
    "Size", "Insert", "Range All", "Range 10%", "Keys");
echo str_repeat("-", 70) . "\n";

foreach ($sizes as $size) {
    $index = new LsmIndex();

    // Generate random keys
    $keys = [];
    for ($i = 0; $i < $size; $i++) {
        $keys[] = sprintf('%012d', mt_rand(0, $size * 10));
    }

    // Benchmark inserts
    $insertTime = benchmark(function() use ($index, $keys, $size) {
        for ($i = 0; $i < $size; $i++) {
            $index->insert($keys[$i], $i);
        }
    });

    // Benchmark full range scan
    $rangeAllTime = benchmark(function() use ($index) {
        $count = 0;
        foreach ($index->range() as $rowId) {
            $count++;
        }
    });

    // Benchmark 10% range scan (middle portion)
    sort($keys);
    $startKey = $keys[(int)($size * 0.45)];
    $endKey = $keys[(int)($size * 0.55)];

    $range10Time = benchmark(function() use ($index, $startKey, $endKey) {
        $count = 0;
        foreach ($index->range(start: $startKey, end: $endKey) as $rowId) {
            $count++;
        }
    });

    printf("%-10s | %-12s | %-12s | %-12s | %-10s\n",
        formatNumber($size),
        formatTime($insertTime),
        formatTime($rangeAllTime),
        formatTime($range10Time),
        formatNumber(count($index))
    );

    unset($index, $keys);
    gc_collect_cycles();
}

echo "\n";

// ============================================================================
// Mixed Insert + Range Benchmark
// ============================================================================

echo "2. MIXED INSERT + RANGE (range every N inserts)\n";
echo str_repeat("-", 70) . "\n";
printf("%-10s | %-15s | %-15s | %-15s\n",
    "Size", "Every 10K", "Every 1K", "Every 100");
echo str_repeat("-", 70) . "\n";

foreach ($sizes as $size) {
    $results = [];

    foreach ($mixedIntervals as $interval) {
        if ($interval > $size) {
            $results[$interval] = '-';
            continue;
        }

        $index = new LsmIndex();

        $time = benchmark(function() use ($index, $size, $interval) {
            for ($i = 0; $i < $size; $i++) {
                $key = sprintf('%012d', mt_rand(0, $size * 10));
                $index->insert($key, $i);

                if (($i + 1) % $interval === 0) {
                    // Do a range scan
                    foreach ($index->range() as $rowId) {
                        // Just iterate
                    }
                }
            }
        });

        $results[$interval] = formatTime($time);
        unset($index);
        gc_collect_cycles();
    }

    printf("%-10s | %-15s | %-15s | %-15s\n",
        formatNumber($size),
        $results[10_000],
        $results[1_000],
        $results[100]
    );
}

echo "\n";

// ============================================================================
// Insert Rate Benchmark
// ============================================================================

echo "3. INSERT RATE (inserts/sec)\n";
echo str_repeat("-", 70) . "\n";
printf("%-10s | %-15s | %-15s\n",
    "Size", "Time", "Rate");
echo str_repeat("-", 70) . "\n";

foreach ($sizes as $size) {
    $index = new LsmIndex();

    $time = benchmark(function() use ($index, $size) {
        for ($i = 0; $i < $size; $i++) {
            $key = sprintf('%012d', mt_rand(0, $size * 10));
            $index->insert($key, $i);
        }
    });

    $rate = $size / ($time / 1000); // per second

    printf("%-10s | %-15s | %-15s\n",
        formatNumber($size),
        formatTime($time),
        formatNumber((int)$rate) . '/s'
    );

    unset($index);
    gc_collect_cycles();
}

echo "\n";

// ============================================================================
// Range Scan Rate Benchmark
// ============================================================================

echo "4. RANGE SCAN RATE (rows/sec for full scan)\n";
echo str_repeat("-", 70) . "\n";
printf("%-10s | %-15s | %-15s\n",
    "Size", "Time", "Rate");
echo str_repeat("-", 70) . "\n";

foreach ($sizes as $size) {
    $index = new LsmIndex();

    // Pre-fill index
    for ($i = 0; $i < $size; $i++) {
        $key = sprintf('%012d', $i);
        $index->insert($key, $i);
    }

    $rowCount = 0;
    $time = benchmark(function() use ($index, &$rowCount) {
        $rowCount = 0;
        foreach ($index->range() as $rowId) {
            $rowCount++;
        }
    });

    $rate = $rowCount / ($time / 1000);

    printf("%-10s | %-15s | %-15s\n",
        formatNumber($size),
        formatTime($time),
        formatNumber((int)$rate) . '/s'
    );

    unset($index);
    gc_collect_cycles();
}

echo "\n";

// ============================================================================
// Layer depth analysis
// ============================================================================

echo "5. LAYER STRUCTURE (with default threshold=1000)\n";
echo str_repeat("-", 70) . "\n";

foreach ([1_000, 5_000, 10_000, 50_000, 100_000] as $size) {
    $index = new LsmIndex();

    for ($i = 0; $i < $size; $i++) {
        $key = sprintf('%012d', $i);
        $index->insert($key, $i);
    }

    // Count layers via reflection
    $depth = 1;
    $ref = new ReflectionClass($index);
    $innerProp = $ref->getProperty('inner');
    $innerProp->setAccessible(true);

    $current = $index;
    while (($inner = $innerProp->getValue($current)) !== null) {
        $depth++;
        $current = $inner;
    }

    printf("Size: %-8s -> %d layer(s), %d unique keys\n",
        formatNumber($size),
        $depth,
        count($index)
    );

    unset($index);
}

echo "\n" . str_repeat("=", 70) . "\n";
echo "Benchmark complete.\n";
