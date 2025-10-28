<?php
/**
 * Benchmark Mini Framework Bootstrap Time
 *
 * Run this multiple times to get accurate measurements:
 * for i in {1..10}; do php tests/benchmark-bootstrap.php; done
 */

echo "Mini Framework Bootstrap Benchmark\n";
echo str_repeat('=', 70) . "\n\n";

// Measure autoload
$start = hrtime(true);
require __DIR__ . '/../vendor/autoload.php';
$autoloadTime = (hrtime(true) - $start) / 1_000_000;

// Measure Mini instantiation
$start = hrtime(true);
$mini = mini\Mini::$mini; // Triggers Mini::__construct()
$miniTime = (hrtime(true) - $start) / 1_000_000;

// Measure bootstrap
$start = hrtime(true);
mini\bootstrap();
$bootstrapTime = (hrtime(true) - $start) / 1_000_000;

// Measure router creation
$start = hrtime(true);
$router = new mini\SimpleRouter();
$routerTime = (hrtime(true) - $start) / 1_000_000;

$total = $autoloadTime + $miniTime + $bootstrapTime + $routerTime;

printf("Autoload (vendor/autoload.php):  %.3fms  (%.1f%%)\n", $autoloadTime, ($autoloadTime / $total) * 100);
printf("Mini::__construct():              %.3fms  (%.1f%%)\n", $miniTime, ($miniTime / $total) * 100);
printf("mini\\bootstrap():                 %.3fms  (%.1f%%)\n", $bootstrapTime, ($bootstrapTime / $total) * 100);
printf("new SimpleRouter():               %.3fms  (%.1f%%)\n", $routerTime, ($routerTime / $total) * 100);
echo str_repeat('-', 70) . "\n";
printf("TOTAL:                            %.3fms\n", $total);

echo "\n" . str_repeat('=', 70) . "\n";
echo "Run multiple times for accurate average:\n";
echo "  for i in {1..10}; do php tests/benchmark-bootstrap.php; done\n";
