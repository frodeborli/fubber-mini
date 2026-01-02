<?php
/**
 * Benchmark TreapIndex vs LsmIndex
 */

require __DIR__ . '/../../../ensure-autoloader.php';

use mini\Table\Index\TreapIndex;
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
    return ($end - $start) / 1_000_000;
}

// ============================================================================
// Configuration
// ============================================================================

$sizes = [1_000, 10_000, 100_000];

echo "TreapIndex vs LsmIndex Benchmark\n";
echo str_repeat("=", 80) . "\n\n";

// ============================================================================
// 1. Insert-only (hash mode for Treap)
// ============================================================================

echo "1. INSERT-ONLY (Treap stays in hash mode)\n";
echo str_repeat("-", 80) . "\n";
printf("%-10s | %-15s | %-15s | %-10s\n", "Size", "Treap", "LsmIndex", "Winner");
echo str_repeat("-", 80) . "\n";

foreach ($sizes as $size) {
    $keys = [];
    for ($i = 0; $i < $size; $i++) {
        $keys[] = sprintf('%012d', mt_rand(0, $size * 10));
    }

    // Treap
    $treap = new TreapIndex();
    $treapTime = benchmark(function() use ($treap, $keys, $size) {
        for ($i = 0; $i < $size; $i++) {
            $treap->insert($keys[$i], $i);
        }
    });

    // LsmIndex
    $lsm = new LsmIndex();
    $lsmTime = benchmark(function() use ($lsm, $keys, $size) {
        for ($i = 0; $i < $size; $i++) {
            $lsm->insert($keys[$i], $i);
        }
    });

    $winner = $treapTime < $lsmTime ? 'Treap' : 'LSM';
    printf("%-10s | %-15s | %-15s | %-10s\n",
        formatNumber($size),
        formatTime($treapTime),
        formatTime($lsmTime),
        $winner
    );

    unset($treap, $lsm, $keys);
    gc_collect_cycles();
}

echo "\n";

// ============================================================================
// 2. Insert + eq() lookups (Treap stays in hash mode)
// ============================================================================

echo "2. INSERT + EQ LOOKUPS (Treap stays in hash mode)\n";
echo str_repeat("-", 80) . "\n";
printf("%-10s | %-15s | %-15s | %-10s\n", "Size", "Treap", "LsmIndex", "Winner");
echo str_repeat("-", 80) . "\n";

foreach ($sizes as $size) {
    $keys = [];
    for ($i = 0; $i < $size; $i++) {
        $keys[] = sprintf('%012d', mt_rand(0, $size * 10));
    }

    // Treap
    $treap = new TreapIndex();
    $treapTime = benchmark(function() use ($treap, $keys, $size) {
        for ($i = 0; $i < $size; $i++) {
            $treap->insert($keys[$i], $i);
            if ($i % 100 === 0 && $i > 0) {
                iterator_to_array($treap->eq($keys[mt_rand(0, $i - 1)]));
            }
        }
    });

    // LsmIndex
    $lsm = new LsmIndex();
    $lsmTime = benchmark(function() use ($lsm, $keys, $size) {
        for ($i = 0; $i < $size; $i++) {
            $lsm->insert($keys[$i], $i);
            if ($i % 100 === 0 && $i > 0) {
                iterator_to_array($lsm->eq($keys[mt_rand(0, $i - 1)]));
            }
        }
    });

    $winner = $treapTime < $lsmTime ? 'Treap' : 'LSM';
    printf("%-10s | %-15s | %-15s | %-10s\n",
        formatNumber($size),
        formatTime($treapTime),
        formatTime($lsmTime),
        $winner
    );

    unset($treap, $lsm, $keys);
    gc_collect_cycles();
}

echo "\n";

// ============================================================================
// 3. Insert then range (migration cost)
// ============================================================================

echo "3. INSERT THEN RANGE (includes Treap migration cost)\n";
echo str_repeat("-", 80) . "\n";
printf("%-10s | %-15s | %-15s | %-10s\n", "Size", "Treap", "LsmIndex", "Winner");
echo str_repeat("-", 80) . "\n";

foreach ($sizes as $size) {
    $keys = [];
    for ($i = 0; $i < $size; $i++) {
        $keys[] = sprintf('%012d', mt_rand(0, $size * 10));
    }

    // Treap
    $treap = new TreapIndex();
    for ($i = 0; $i < $size; $i++) {
        $treap->insert($keys[$i], $i);
    }
    $treapTime = benchmark(function() use ($treap) {
        $count = 0;
        foreach ($treap->range() as $rowId) {
            $count++;
        }
    });

    // LsmIndex
    $lsm = new LsmIndex();
    for ($i = 0; $i < $size; $i++) {
        $lsm->insert($keys[$i], $i);
    }
    $lsmTime = benchmark(function() use ($lsm) {
        $count = 0;
        foreach ($lsm->range() as $rowId) {
            $count++;
        }
    });

    $winner = $treapTime < $lsmTime ? 'Treap' : 'LSM';
    printf("%-10s | %-15s | %-15s | %-10s\n",
        formatNumber($size),
        formatTime($treapTime),
        formatTime($lsmTime),
        $winner
    );

    unset($treap, $lsm, $keys);
    gc_collect_cycles();
}

echo "\n";

// ============================================================================
// 4. Range query after treap is built
// ============================================================================

echo "4. RANGE QUERY (after structure is built)\n";
echo str_repeat("-", 80) . "\n";
printf("%-10s | %-15s | %-15s | %-10s\n", "Size", "Treap", "LsmIndex", "Winner");
echo str_repeat("-", 80) . "\n";

foreach ($sizes as $size) {
    $keys = [];
    for ($i = 0; $i < $size; $i++) {
        $keys[] = sprintf('%012d', $i); // Sequential for sorted order
    }

    // Pre-build Treap
    $treap = new TreapIndex();
    for ($i = 0; $i < $size; $i++) {
        $treap->insert($keys[$i], $i);
    }
    iterator_to_array($treap->range()); // Trigger migration

    // Pre-build LsmIndex
    $lsm = new LsmIndex();
    for ($i = 0; $i < $size; $i++) {
        $lsm->insert($keys[$i], $i);
    }
    iterator_to_array($lsm->range()); // Trigger sort

    // Benchmark 10% bounded range
    $start = $keys[(int)($size * 0.45)];
    $end = $keys[(int)($size * 0.55)];

    $treapTime = benchmark(function() use ($treap, $start, $end) {
        $count = 0;
        foreach ($treap->range(start: $start, end: $end) as $rowId) {
            $count++;
        }
    }, 10);

    $lsmTime = benchmark(function() use ($lsm, $start, $end) {
        $count = 0;
        foreach ($lsm->range(start: $start, end: $end) as $rowId) {
            $count++;
        }
    }, 10);

    $winner = $treapTime < $lsmTime ? 'Treap' : 'LSM';
    printf("%-10s | %-15s | %-15s | %-10s\n",
        formatNumber($size),
        formatTime($treapTime / 10),
        formatTime($lsmTime / 10),
        $winner
    );

    unset($treap, $lsm, $keys);
    gc_collect_cycles();
}

echo "\n";

// ============================================================================
// 5. Mixed insert + range (most realistic)
// ============================================================================

echo "5. MIXED INSERT + RANGE (range every 1000 inserts)\n";
echo str_repeat("-", 80) . "\n";
printf("%-10s | %-15s | %-15s | %-10s\n", "Size", "Treap", "LsmIndex", "Winner");
echo str_repeat("-", 80) . "\n";

foreach ($sizes as $size) {
    // Treap
    $treap = new TreapIndex();
    $treapTime = benchmark(function() use ($treap, $size) {
        for ($i = 0; $i < $size; $i++) {
            $key = sprintf('%012d', mt_rand(0, $size * 10));
            $treap->insert($key, $i);
            if (($i + 1) % 1000 === 0) {
                foreach ($treap->range() as $rowId) {}
            }
        }
    });

    // LsmIndex
    $lsm = new LsmIndex();
    $lsmTime = benchmark(function() use ($lsm, $size) {
        for ($i = 0; $i < $size; $i++) {
            $key = sprintf('%012d', mt_rand(0, $size * 10));
            $lsm->insert($key, $i);
            if (($i + 1) % 1000 === 0) {
                foreach ($lsm->range() as $rowId) {}
            }
        }
    });

    $winner = $treapTime < $lsmTime ? 'Treap' : 'LSM';
    printf("%-10s | %-15s | %-15s | %-10s\n",
        formatNumber($size),
        formatTime($treapTime),
        formatTime($lsmTime),
        $winner
    );

    unset($treap, $lsm);
    gc_collect_cycles();
}

echo "\n" . str_repeat("=", 80) . "\n";
echo "Benchmark complete.\n";
