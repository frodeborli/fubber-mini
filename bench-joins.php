<?php
/**
 * Cross-join performance benchmark
 * 
 * Tests VDB performance on multi-table comma-joins with varying predicates.
 * Use this to iterate on join optimization without running full test suite.
 */

require __DIR__ . '/vendor/autoload.php';

use mini\Database\VirtualDatabase;

$useArrayTable = in_array('--array', $argv);
$profile = in_array('--profile', $argv);
$tables = (int)($argv[array_search('--tables', $argv) + 1] ?? 5);
$rows = (int)($argv[array_search('--rows', $argv) + 1] ?? 10);

if (in_array('--help', $argv) || in_array('-h', $argv)) {
    echo "Usage: php bench-joins.php [options]\n\n";
    echo "Options:\n";
    echo "  --array      Use ArrayTable instead of InMemoryTable\n";
    echo "  --profile    Profile VDB with Excimer (run single query, show hotspots)\n";
    echo "  --tables N   Number of tables to join (default: 5)\n";
    echo "  --rows N     Rows per table (default: 10)\n";
    echo "\nExamples:\n";
    echo "  php bench-joins.php --tables 5 --rows 10\n";
    echo "  php bench-joins.php --array --tables 8 --rows 10\n";
    echo "  php bench-joins.php --profile --tables 4 --rows 20\n";
    exit(0);
}

$backend = $useArrayTable ? 'ArrayTable' : 'InMemoryTable';
echo "Cross-Join Benchmark ($backend)\n";
echo "================================\n";
echo "Tables: $tables, Rows per table: $rows\n";
echo "Worst case: " . number_format(pow($rows, $tables)) . " row combinations\n\n";

// Setup
$vdb = new VirtualDatabase();
if ($useArrayTable) {
    $vdb->useArrayTable();
}
$vdb->setQueryTimeout(10.0);

// Create tables (like select5.test structure)
echo "Creating tables... ";
$start = microtime(true);
for ($i = 1; $i <= $tables; $i++) {
    $vdb->exec("CREATE TABLE t$i (a$i INTEGER PRIMARY KEY, b$i INTEGER, c$i INTEGER)");
    for ($j = 1; $j <= $rows; $j++) {
        $vdb->exec("INSERT INTO t$i VALUES($j, " . ($j * 10) . ", " . (($j % 5) + 1) . ")");
    }
}
echo round((microtime(true) - $start) * 1000) . "ms\n\n";

// Build table list for queries
$tableList = implode(',', array_map(fn($i) => "t$i", range(1, $tables)));

// Reference: PDO SQLite
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
for ($i = 1; $i <= $tables; $i++) {
    $pdo->exec("CREATE TABLE t$i (a$i INTEGER PRIMARY KEY, b$i INTEGER, c$i INTEGER)");
    for ($j = 1; $j <= $rows; $j++) {
        $pdo->exec("INSERT INTO t$i VALUES($j, " . ($j * 10) . ", " . (($j % 5) + 1) . ")");
    }
}

$tests = [
    // [description, WHERE clause, expected_rows_formula]
    ["No predicate (full explosion)", "", pow($rows, $tables)],
    ["Single equality (a1=1)", "WHERE a1=1", pow($rows, $tables - 1)],
    ["Two equalities (a1=1 AND a2=1)", "WHERE a1=1 AND a2=1", pow($rows, $tables - 2)],
    ["Three equalities", "WHERE a1=1 AND a2=1 AND a3=1", pow($rows, max(0, $tables - 3))],
    ["Range predicate (a1<3)", "WHERE a1<3", 2 * pow($rows, $tables - 1)],
    ["Join predicate (a1=a2)", "WHERE a1=a2", pow($rows, $tables - 1)],
    ["Mixed (a1=1 AND b2>50)", "WHERE a1=1 AND b2>50", pow($rows, $tables - 2) * 5],
];

echo "Benchmarks:\n";
echo str_repeat("-", 80) . "\n";
printf("%-35s %10s %10s %10s %8s\n", "Test", "Rows", "PDO", "VDB", "Ratio");
echo str_repeat("-", 80) . "\n";

foreach ($tests as [$desc, $where, $expected]) {
    if ($expected > 10000000) {
        printf("%-35s %10s %10s %10s %8s\n", $desc, ">10M", "skip", "skip", "-");
        continue;
    }
    
    $sql = "SELECT COUNT(*) FROM $tableList $where";
    
    // PDO
    $start = microtime(true);
    try {
        $pdoCount = $pdo->query($sql)->fetchColumn();
        $pdoTime = (microtime(true) - $start) * 1000;
    } catch (\Throwable $e) {
        $pdoCount = "err";
        $pdoTime = 0;
    }
    
    // VDB
    $start = microtime(true);
    try {
        $vdbCount = $vdb->queryField($sql);
        $vdbTime = (microtime(true) - $start) * 1000;
    } catch (\Throwable $e) {
        $vdbCount = "err";
        $vdbTime = 0;
        if (str_contains($e->getMessage(), 'timeout')) {
            $vdbCount = "timeout";
            $vdbTime = 10000;
        }
    }
    
    $ratio = ($pdoTime > 0 && $vdbTime > 0) ? round($vdbTime / $pdoTime, 1) . "x" : "-";
    
    printf("%-35s %10s %9.0fms %9.0fms %8s\n",
        $desc,
        is_numeric($vdbCount) ? number_format($vdbCount) : $vdbCount,
        $pdoTime,
        $vdbTime,
        $ratio
    );
}

echo str_repeat("-", 80) . "\n";

// Profiling mode
if ($profile) {
    echo "\n\nPROFILING MODE (Excimer)\n";
    echo "========================\n";

    $profiler = new ExcimerProfiler();
    $profiler->setPeriod(0.0001); // 100μs sampling
    $profiler->setEventType(EXCIMER_REAL);

    // Profile the full cross-join (no predicate)
    $sql = "SELECT COUNT(*) FROM $tableList";
    echo "Query: $sql\n\n";

    $profiler->start();
    for ($i = 0; $i < 3; $i++) {
        $vdb->queryField($sql);
    }
    $profiler->stop();

    $log = $profiler->getLog();
    $collapsed = $log->formatCollapsed();

    // Parse collapsed format to get function counts
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

    echo "Top functions by sample count:\n";
    echo str_repeat("-", 70) . "\n";
    printf("%-55s %6s %6s\n", "Function", "Samples", "%");
    echo str_repeat("-", 70) . "\n";

    $i = 0;
    foreach ($funcCounts as $func => $samples) {
        if ($i++ >= 20) break;
        $pct = $total > 0 ? ($samples / $total) * 100 : 0;
        // Shorten class names for readability
        $func = preg_replace('/mini\\\\Database\\\\/', '', $func);
        $func = preg_replace('/mini\\\\Table\\\\/', 'Table\\', $func);
        printf("%-55s %6d %5.1f%%\n", substr($func, 0, 55), $samples, $pct);
    }

    echo str_repeat("-", 70) . "\n";
    echo "Total samples: $total\n";
}
