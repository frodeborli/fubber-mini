<?php
/**
 * Run SQLLogicTest suite against VirtualDatabase and SQLite
 *
 * This test requires the SQLLogicTest data files to be cloned:
 *   git clone https://github.com/dolthub/sqllogictest tests/sqllogictest-data
 *
 * The test auto-skips if the data folder is missing.
 *
 * Options:
 *   --print-query    Print each query before running
 *   --print-results  Print actual result rows from SQLite and VDB
 *   --print-errors   Print VDB exceptions and parse errors
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Test\SqlLogicTest;
use mini\Database\VirtualDatabase;
use mini\Database\PDODatabase;
use mini\CLI\ArgManager;
use function mini\args;

// Configure command-line arguments
args(
    (new ArgManager())
        ->withFlag(null, 'print-query')
        ->withFlag(null, 'print-results')
        ->withFlag(null, 'print-errors')
        ->withFlag(null, 'stop-on-error')
        ->withFlag(null, 'array-table')
        ->withRequiredValue(null, 'include-query')
        ->withRequiredValue(null, 'exclude-query')
        ->withFlag('h', 'help')
);

if (args()->getFlag('help')) {
    echo "Usage: php tests/Database/SqlLogicTestSuite.php [options] [pattern]\n\n";
    echo "Arguments:\n";
    echo "  pattern               Filter test files by regex (e.g. 'select[123]', 'evidence')\n\n";
    echo "Options:\n";
    echo "  -h, --help            Show this help message\n";
    echo "  --print-query         Print each query before running\n";
    echo "  --print-results       Print actual result rows from SQLite and VDB\n";
    echo "  --print-errors        Print VDB exceptions and parse errors\n";
    echo "  --stop-on-error       Stop on first VDB failure, show query and results\n";
    echo "  --include-query RE    Only run queries matching regex (e.g. '.*exist.*')\n";
    echo "  --exclude-query RE    Skip queries matching regex (e.g. '.*select.*')\n";
    exit(0);
}

$pattern = args()->getUnparsedArgs()[0] ?? null;

// Check if test data exists
$dataDir = __DIR__ . '/../sqllogictest-data';
if (!is_dir($dataDir)) {
    echo "SKIP: SQLLogicTest data not found\n";
    echo "To run these tests, clone the test data:\n";
    echo "  git clone https://github.com/dolthub/sqllogictest tests/sqllogictest-data\n";
    exit(0);
}

// Collect all test files via glob
$testFiles = [];
foreach (glob($dataDir . '/test/*.test') as $file) {
    $testFiles[] = 'test/' . basename($file);
}
foreach (glob($dataDir . '/test/evidence/*.test') as $file) {
    $testFiles[] = 'test/evidence/' . basename($file);
}
sort($testFiles);

// Filter by pattern if specified (supports regex with chr(1) delimiter)
if ($pattern !== null) {
    $d = chr(1);
    $testFiles = array_filter($testFiles, fn($f) => preg_match("{$d}{$pattern}{$d}i", $f));
    if (empty($testFiles)) {
        echo "No test files matching '$pattern'\n";
        exit(1);
    }
}

function createSqlite(): PDODatabase {
    $pdo = new \PDO('sqlite::memory:');
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    return new PDODatabase($pdo);
}

// Results storage
$totals = [
    'sqlite' => ['pass' => 0, 'fail' => 0, 'skip_na' => 0, 'skip_limit' => 0, 'skip_other' => 0],
    'vdb' => ['pass' => 0, 'fail' => 0, 'skip_na' => 0, 'skip_limit' => 0, 'skip_other' => 0],
];
$sharedTotals = ['sqlite' => 0.0, 'vdb' => 0.0, 'count' => 0];

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
    if (args()->getFlag('array-table')) {
        $vdb->useArrayTable();
    }
    $runner->addBackend('vdb', $vdb);

    // Apply debug flags
    if (args()->getFlag('print-query')) {
        $runner->printQuery(true);
    }
    if (args()->getFlag('print-results')) {
        $runner->printResults(true);
    }
    if (args()->getFlag('print-errors')) {
        $runner->printErrors(true);
    }
    if ($includeQuery = args()->getOption('include-query')) {
        $runner->includeQuery($includeQuery);
    }
    if ($excludeQuery = args()->getOption('exclude-query')) {
        $runner->excludeQuery($excludeQuery);
    }
    if (args()->getFlag('stop-on-error')) {
        $runner->stopOnError(true);
    }

    $content = file_get_contents($path);
    $result = $runner->run($content);
    $stats = $result->getStats();
    $sharedTimes = $result->getSharedTimes();

    $sqlite = $stats['sqlite'] ?? ['pass' => 0, 'fail' => 0, 'skip_na' => 0, 'skip_limit' => 0, 'skip_other' => 0];
    $vdb = $stats['vdb'] ?? ['pass' => 0, 'fail' => 0, 'skip_na' => 0, 'skip_limit' => 0, 'skip_other' => 0];

    // Accumulate totals
    $totals['sqlite']['pass'] += $sqlite['pass'];
    $totals['sqlite']['fail'] += $sqlite['fail'];
    $totals['sqlite']['skip_na'] += $sqlite['skip_na'] ?? 0;
    $totals['sqlite']['skip_limit'] += $sqlite['skip_limit'] ?? 0;
    $totals['sqlite']['skip_other'] += $sqlite['skip_other'] ?? 0;
    $totals['vdb']['pass'] += $vdb['pass'];
    $totals['vdb']['fail'] += $vdb['fail'];
    $totals['vdb']['skip_na'] += $vdb['skip_na'] ?? 0;
    $totals['vdb']['skip_limit'] += $vdb['skip_limit'] ?? 0;
    $totals['vdb']['skip_other'] += $vdb['skip_other'] ?? 0;

    // Accumulate shared times (for fair comparison)
    $sharedTotals['sqlite'] += $sharedTimes['sqlite'];
    $sharedTotals['vdb'] += $sharedTimes['vdb'];
    $sharedTotals['count'] += $sharedTimes['count'];

    // Calculate VDB percentage from applicable tests only (excluding skip_na)
    // Applicable = pass + fail + skip_limit (limitation skips count as "attempted but couldn't")
    $vdbApplicable = $vdb['pass'] + $vdb['fail'] + ($vdb['skip_limit'] ?? 0);
    $vdbPct = $vdbApplicable > 0 ? round(100 * $vdb['pass'] / $vdbApplicable) : 100;

    // Total tests VDB saw (for display)
    $vdbTotal = $vdb['pass'] + $vdb['fail'] + ($vdb['skip_na'] ?? 0) + ($vdb['skip_limit'] ?? 0) + ($vdb['skip_other'] ?? 0);
    $sqliteTotal = $sqlite['pass'] + $sqlite['fail'] + ($sqlite['skip_na'] ?? 0) + ($sqlite['skip_limit'] ?? 0) + ($sqlite['skip_other'] ?? 0);

    $sqliteStatus = $sqlite['fail'] === 0 ? '✓' : '✗';
    $vdbStatus = $vdb['fail'] === 0 ? '✓' : '✗';

    // Show limitation skips if any
    $limitStr = '';
    if (($vdb['skip_limit'] ?? 0) > 0) {
        $limitStr = sprintf(" [%d limited]", $vdb['skip_limit']);
    }

    printf("%-40s SQLite: %s %4d/%-4d  VDB: %s %3d%% (%d/%d)%s\n",
        $file,
        $sqliteStatus,
        $sqlite['pass'],
        $sqliteTotal,
        $vdbStatus,
        $vdbPct,
        $vdb['pass'],
        $vdbApplicable,
        $limitStr
    );
}

echo "\n" . str_repeat("=", 100) . "\n";
echo "TOTALS\n";
echo str_repeat("-", 100) . "\n";

$sqliteSkipTotal = $totals['sqlite']['skip_na'] + $totals['sqlite']['skip_limit'] + $totals['sqlite']['skip_other'];
$vdbSkipTotal = $totals['vdb']['skip_na'] + $totals['vdb']['skip_limit'] + $totals['vdb']['skip_other'];

$sqliteTotal = $totals['sqlite']['pass'] + $totals['sqlite']['fail'] + $sqliteSkipTotal;
$vdbTotal = $totals['vdb']['pass'] + $totals['vdb']['fail'] + $vdbSkipTotal;

// VDB applicable tests = pass + fail + limitation_skips (excluding skip_na which are just not applicable)
$vdbApplicable = $totals['vdb']['pass'] + $totals['vdb']['fail'] + $totals['vdb']['skip_limit'];
$vdbPct = $vdbApplicable > 0 ? round(100 * $totals['vdb']['pass'] / $vdbApplicable, 1) : 100;

printf("SQLite: %d passed, %d failed, %d skipped (of %d)\n",
    $totals['sqlite']['pass'],
    $totals['sqlite']['fail'],
    $sqliteSkipTotal,
    $sqliteTotal
);

printf("VDB:    %d passed, %d failed",
    $totals['vdb']['pass'],
    $totals['vdb']['fail']
);

if ($totals['vdb']['skip_limit'] > 0) {
    printf(", %d limited", $totals['vdb']['skip_limit']);
}
if ($totals['vdb']['skip_na'] > 0) {
    printf(", %d n/a", $totals['vdb']['skip_na']);
}
echo "\n";

// Fair speed comparison: only count time for tests both backends ran
$speedRatio = $sharedTotals['sqlite'] > 0 ? $sharedTotals['vdb'] / $sharedTotals['sqlite'] : 0;

printf("\nVDB: %.1f%% of applicable tests pass", $vdbPct);
if ($totals['vdb']['skip_limit'] > 0) {
    printf(" (%d tests hit VDB limitations)", $totals['vdb']['skip_limit']);
}
echo "\n";

printf("Speed: %.1fx slower on %d shared tests (SQLite: %.1fs, VDB: %.1fs)\n",
    $speedRatio,
    $sharedTotals['count'],
    $sharedTotals['sqlite'],
    $sharedTotals['vdb']
);

// Exit with error if SQLite has failures (indicates test runner bug)
$sqliteFailures = $totals['sqlite']['fail'];
if ($sqliteFailures > 0) {
    echo "\nWARNING: SQLite has $sqliteFailures failures - may indicate test runner issues\n";
}

exit($sqliteFailures > 20 ? 1 : 0); // Allow some SQLite failures for unsupported syntax
