<?php
/**
 * Benchmark a complete Mini request cycle
 *
 * Simulates: autoload → router() → route file execution
 */

// Set up minimal request environment
$_SERVER['REQUEST_URI'] = '/ping';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['SERVER_PORT'] = '80';

echo "Mini Framework Full Request Benchmark\n";
echo str_repeat('=', 70) . "\n\n";

$markers = [];

// 1. Autoload
$start = hrtime(true);
require __DIR__ . '/../vendor/autoload.php';
$markers['autoload'] = (hrtime(true) - $start) / 1_000_000;

// 2. Call router() which triggers Mini, bootstrap, and routing
$start = hrtime(true);

// Capture output from router
ob_start();
try {
    mini\router();
} catch (\Throwable $e) {
    // If 404 or other error, that's fine for benchmark
}
$output = ob_get_clean();

$markers['router_total'] = (hrtime(true) - $start) / 1_000_000;

// Calculate total
$total = $markers['autoload'] + $markers['router_total'];

// Display results
printf("1. Autoload (vendor/autoload.php):  %.3fms  (%.1f%%)\n",
    $markers['autoload'],
    ($markers['autoload'] / $total) * 100
);

printf("2. mini\\router() complete:          %.3fms  (%.1f%%)\n",
    $markers['router_total'],
    ($markers['router_total'] / $total) * 100
);

echo str_repeat('-', 70) . "\n";
printf("TOTAL REQUEST TIME:                 %.3fms\n", $total);

echo "\n" . str_repeat('=', 70) . "\n";
echo "What router() includes:\n";
echo "  - Mini::__construct() (lazy singleton)\n";
echo "  - mini\\bootstrap() (error handlers, output buffering)\n";
echo "  - SimpleRouter instantiation\n";
echo "  - Route resolution\n";
echo "  - Route file execution (if found)\n";
echo "\nNote: First run is slower (cold opcache)\n";
echo "Run multiple times: for i in {1..5}; do php tests/benchmark-request.php; done\n";
