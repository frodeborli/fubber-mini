<?php
/**
 * Profile TreapIndex to find bottlenecks
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

use mini\Table\Index\TreapIndex;
use mini\Table\Index\LsmIndex;

$size = 10_000;

echo "Profiling TreapIndex range() with $size keys\n";
echo str_repeat("=", 60) . "\n\n";

// Build indexes
$treap = new TreapIndex();
$lsm = new LsmIndex();
$keys = [];

for ($i = 0; $i < $size; $i++) {
    $keys[] = sprintf('%012d', mt_rand(0, $size * 10));
}

foreach ($keys as $i => $key) {
    $treap->insert($key, $i);
    $lsm->insert($key, $i);
}

// Profile using excimer
$profiler = new ExcimerProfiler();
$profiler->setPeriod(0.0001); // 100μs sampling
$profiler->setEventType(EXCIMER_REAL);

echo "Profiling Treap range()...\n";
$profiler->start();

for ($run = 0; $run < 10; $run++) {
    $count = 0;
    foreach ($treap->range() as $rowId) {
        $count++;
    }
}

$profiler->stop();
$log = $profiler->getLog();

echo "\nTreap Profile - collapsed stacks:\n";
echo str_repeat("-", 60) . "\n";

// Parse collapsed format to get function counts
$collapsed = $log->formatCollapsed();
$funcCounts = [];
foreach (explode("\n", $collapsed) as $line) {
    if (empty($line)) continue;
    $parts = explode(' ', $line);
    $count = (int)array_pop($parts);
    $stack = implode(' ', $parts);
    $funcs = explode(';', $stack);
    foreach ($funcs as $f) {
        $funcCounts[$f] = ($funcCounts[$f] ?? 0) + $count;
    }
}
arsort($funcCounts);
$total = array_sum($funcCounts);
$i = 0;
foreach ($funcCounts as $func => $samples) {
    if ($i++ >= 15) break;
    $pct = $total > 0 ? ($samples / $total) * 100 : 0;
    printf("%-50s %5d (%5.1f%%)\n", substr($func, 0, 50), $samples, $pct);
}

echo "\n\n" . str_repeat("=", 60) . "\n";
echo "Now profiling LsmIndex range() for comparison...\n";

$profiler2 = new ExcimerProfiler();
$profiler2->setPeriod(0.0001);
$profiler2->setEventType(EXCIMER_REAL);
$profiler2->start();

for ($run = 0; $run < 10; $run++) {
    $count = 0;
    foreach ($lsm->range() as $rowId) {
        $count++;
    }
}

$profiler2->stop();
$log2 = $profiler2->getLog();

echo "\nLsmIndex Profile - collapsed stacks:\n";
echo str_repeat("-", 60) . "\n";

$collapsed2 = $log2->formatCollapsed();
$funcCounts2 = [];
foreach (explode("\n", $collapsed2) as $line) {
    if (empty($line)) continue;
    $parts = explode(' ', $line);
    $count = (int)array_pop($parts);
    $stack = implode(' ', $parts);
    $funcs = explode(';', $stack);
    foreach ($funcs as $f) {
        $funcCounts2[$f] = ($funcCounts2[$f] ?? 0) + $count;
    }
}
arsort($funcCounts2);
$total2 = array_sum($funcCounts2);
$i = 0;
foreach ($funcCounts2 as $func => $samples) {
    if ($i++ >= 15) break;
    $pct = $total2 > 0 ? ($samples / $total2) * 100 : 0;
    printf("%-50s %5d (%5.1f%%)\n", substr($func, 0, 50), $samples, $pct);
}

echo "\n\nTotal samples: Treap=$total, LsmIndex=$total2\n";
