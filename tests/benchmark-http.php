<?php
/**
 * Benchmark Mini Framework HTTP Performance
 *
 * Launches PHP's built-in web server and hammers it with requests
 * for 5 seconds to measure throughput.
 */

echo "Mini Framework HTTP Benchmark\n";
echo str_repeat('=', 70) . "\n\n";

$host = '127.0.0.1';
$port = 8765;
$url = "http://{$host}:{$port}/ping";
$duration = 5; // Run for 5 seconds
$docRoot = __DIR__ . '/../html';

// Start PHP built-in server
echo "Starting server...\n";
$serverCmd = sprintf('php -S %s:%d -t %s', $host, $port, escapeshellarg($docRoot));
$process = proc_open($serverCmd, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $docRoot);

if (!is_resource($process)) {
    die("ERROR: Failed to start web server\n");
}

// Wait for server to be ready
echo "Waiting for server";
$ready = false;
for ($i = 0; $i < 50 && !$ready; $i++) {
    usleep(100000); // 100ms
    echo ".";
    $context = stream_context_create(['http' => ['timeout' => 1]]);
    if (@file_get_contents($url, false, $context) !== false) {
        $ready = true;
        echo " ready!\n\n";
    }
}

if (!$ready) {
    proc_terminate($process);
    die("ERROR: Server failed to start\n");
}

// Warm up
echo "Warming up (100 requests)...\n";
for ($i = 0; $i < 100; $i++) {
    @file_get_contents($url);
}
echo "Complete!\n\n";

// Run benchmark
echo "Running benchmark for {$duration} seconds...\n";
echo str_repeat('-', 70) . "\n";

$times = [];
$startTime = microtime(true);
$endTime = $startTime + $duration;
$requestCount = 0;

while (microtime(true) < $endTime) {
    $start = hrtime(true);
    $response = @file_get_contents($url);
    $elapsed = (hrtime(true) - $start) / 1_000_000; // Convert to ms

    if ($response !== false) {
        $times[] = $elapsed;
        $requestCount++;
    }
}

$totalTime = microtime(true) - $startTime;

// Stop server
echo "\nStopping server...\n";
proc_terminate($process);
proc_close($process);
fclose($pipes[0]);
fclose($pipes[1]);
fclose($pipes[2]);

sort($times);

$avg = array_sum($times) / count($times);
$min = min($times);
$max = max($times);
$p50 = $times[intval(count($times) * 0.50)];
$p95 = $times[intval(count($times) * 0.95)];
$p99 = $times[intval(count($times) * 0.99)];

echo "\n" . str_repeat('=', 70) . "\n";
echo "Results\n";
echo str_repeat('=', 70) . "\n\n";

$requestsPerSecond = $requestCount / $totalTime;
$p50 = $times[intval(count($times) * 0.50)];
$p95 = $times[intval(count($times) * 0.95)];
$p99 = $times[intval(count($times) * 0.99)];

echo "Throughput:\n";
printf("  Total Requests:    %s\n", number_format($requestCount));
printf("  Total Time:        %.3f seconds\n", $totalTime);
printf("  Requests/Second:   %.2f req/sec\n", $requestsPerSecond);
printf("  Avg Time/Request:  %.3f ms\n\n", $avg);

echo "Latency Distribution:\n";
printf("  Minimum:           %.3f ms\n", $min);
printf("  Median (50%%):      %.3f ms\n", $p50);
printf("  95th Percentile:   %.3f ms\n", $p95);
printf("  99th Percentile:   %.3f ms\n", $p99);
printf("  Maximum:           %.3f ms\n\n");

echo str_repeat('=', 70) . "\n";
echo "What this benchmark measures:\n";
echo "  - Mini framework bootstrap (autoload, init, routing)\n";
echo "  - Route resolution (_routes/ping.php)\n";
echo "  - PSR-7 response creation and emission\n";
echo "  - HTTP overhead (localhost)\n";
echo str_repeat('=', 70) . "\n";
