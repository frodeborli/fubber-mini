<?php
/**
 * Run SQLLogicTest suite against VirtualDatabase and SQLite
 *
 * This test requires the SQLLogicTest data files to be cloned:
 *   git clone https://github.com/dolthub/sqllogictest tests/sqllogictest-data
 *
 * The test auto-skips if the data folder is missing.
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Test\SqlLogicTest;
use mini\Database\VirtualDatabase;
use mini\Database\PDODatabase;

// Check if test data exists
$dataDir = __DIR__ . '/../sqllogictest-data';
if (!is_dir($dataDir)) {
    echo "SKIP: SQLLogicTest data not found\n";
    echo "To run these tests, clone the test data:\n";
    echo "  git clone https://github.com/dolthub/sqllogictest tests/sqllogictest-data\n";
    exit(0);
}

// Collect all test files
$testFiles = [
    // Main select tests
    'test/select1.test',
    'test/select2.test',
    'test/select3.test',
    // select4 and select5 skipped - contain multi-table cross joins that are slow without optimization
    // 'test/select4.test',
    // 'test/select5.test',
];

// Add all evidence tests
foreach (glob($dataDir . '/test/evidence/*.test') as $file) {
    $testFiles[] = 'test/evidence/' . basename($file);
}

function createSqlite(): PDODatabase {
    $pdo = new \PDO('sqlite::memory:');
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    return new PDODatabase($pdo);
}

// Results storage
$totals = [
    'sqlite' => ['pass' => 0, 'fail' => 0, 'skip' => 0, 'time' => 0.0],
    'vdb' => ['pass' => 0, 'fail' => 0, 'skip' => 0, 'time' => 0.0],
];

echo "SQLLogicTest Suite\n";
echo str_repeat("=", 100) . "\n\n";

foreach ($testFiles as $file) {
    $path = $dataDir . '/' . $file;
    if (!file_exists($path)) {
        continue;
    }

    // Fresh databases for each file
    $runner = new SqlLogicTest();
    $runner->addBackend('sqlite', createSqlite());
    $vdb = new VirtualDatabase();
    $vdb->setQueryTimeout(1.0); // 1 second max per query
    $runner->addBackend('vdb', $vdb);

    $content = file_get_contents($path);
    $result = $runner->run($content);
    $stats = $result->getStats();
    $times = $result->getTimes();

    $sqlite = $stats['sqlite'] ?? ['pass' => 0, 'fail' => 0, 'skip' => 0];
    $vdb = $stats['vdb'] ?? ['pass' => 0, 'fail' => 0, 'skip' => 0];
    $sqliteTime = $times['sqlite'] ?? 0.0;
    $vdbTime = $times['vdb'] ?? 0.0;

    // Accumulate totals
    $totals['sqlite']['pass'] += $sqlite['pass'];
    $totals['sqlite']['fail'] += $sqlite['fail'];
    $totals['sqlite']['skip'] += $sqlite['skip'];
    $totals['sqlite']['time'] += $sqliteTime;
    $totals['vdb']['pass'] += $vdb['pass'];
    $totals['vdb']['fail'] += $vdb['fail'];
    $totals['vdb']['skip'] += $vdb['skip'];
    $totals['vdb']['time'] += $vdbTime;

    // Print result line
    $sqliteTotal = $sqlite['pass'] + $sqlite['fail'] + $sqlite['skip'];
    $vdbTotal = $vdb['pass'] + $vdb['fail'] + $vdb['skip'];

    $sqliteStatus = $sqlite['fail'] === 0 ? '✓' : '✗';
    $vdbPct = $sqliteTotal > 0 ? round(100 * $vdb['pass'] / $sqliteTotal) : 0;

    printf("%-40s SQLite: %s %4d/%-4d %5.1fs  VDB: %3d%% (%d/%d) %5.1fs\n",
        $file,
        $sqliteStatus,
        $sqlite['pass'],
        $sqliteTotal,
        $sqliteTime,
        $vdbPct,
        $vdb['pass'],
        $vdbTotal,
        $vdbTime
    );
}

echo "\n" . str_repeat("=", 100) . "\n";
echo "TOTALS\n";
echo str_repeat("-", 100) . "\n";

$sqliteTotal = $totals['sqlite']['pass'] + $totals['sqlite']['fail'] + $totals['sqlite']['skip'];
$vdbTotal = $totals['vdb']['pass'] + $totals['vdb']['fail'] + $totals['vdb']['skip'];

printf("SQLite: %d passed, %d failed, %d skipped (of %d) in %.1fs\n",
    $totals['sqlite']['pass'],
    $totals['sqlite']['fail'],
    $totals['sqlite']['skip'],
    $sqliteTotal,
    $totals['sqlite']['time']
);

printf("VDB:    %d passed, %d failed, %d skipped (of %d) in %.1fs\n",
    $totals['vdb']['pass'],
    $totals['vdb']['fail'],
    $totals['vdb']['skip'],
    $vdbTotal,
    $totals['vdb']['time']
);

$vdbPct = $sqliteTotal > 0 ? round(100 * $totals['vdb']['pass'] / $sqliteTotal, 1) : 0;
$speedRatio = $totals['sqlite']['time'] > 0 ? $totals['vdb']['time'] / $totals['sqlite']['time'] : 0;
printf("\nVDB passes %s%% of tests that SQLite runs (%.1fx slower)\n", $vdbPct, $speedRatio);

// Exit with error if SQLite has failures (indicates test runner bug)
$sqliteFailures = $totals['sqlite']['fail'];
if ($sqliteFailures > 0) {
    echo "\nWARNING: SQLite has $sqliteFailures failures - may indicate test runner issues\n";
}

exit($sqliteFailures > 20 ? 1 : 0); // Allow some SQLite failures for unsupported syntax
