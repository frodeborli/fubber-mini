<?php

/**
 * Benchmark: Mini Framework Bootstrap Performance
 *
 * This script benchmarks the Mini framework by:
 * 1. Starting PHP's built-in web server
 * 2. Making 10,000 requests to a simple ping endpoint
 * 3. Measuring throughput and latency
 * 4. Cleaning up the server process
 *
 * Usage:
 *   php tests/benchmark-ping.php
 */

echo "=" . str_repeat("=", 70) . "\n";
echo "Mini Framework Bootstrap Benchmark\n";
echo "=" . str_repeat("=", 70) . "\n\n";

// Configuration
$host = '127.0.0.1';
$port = 8765;
$url = "http://{$host}:{$port}/ping";
$requests = 10000;
$docRoot = __DIR__ . '/../html';

// Verify document root exists
if (!is_dir($docRoot)) {
    echo "Error: Document root not found: {$docRoot}\n";
    echo "Please ensure html/index.php exists.\n";
    exit(1);
}

// Step 1: Start PHP built-in server
echo "Starting PHP built-in web server...\n";
echo "  Host: {$host}:{$port}\n";
echo "  Document Root: {$docRoot}\n";

$command = sprintf(
    'php -S %s:%d -t %s > /dev/null 2>&1 & echo $!',
    escapeshellarg($host),
    $port,
    escapeshellarg($docRoot)
);

$serverPid = trim(shell_exec($command));

if (!$serverPid || !is_numeric($serverPid)) {
    echo "Error: Failed to start web server\n";
    exit(1);
}

echo "  Server PID: {$serverPid}\n";

// Wait for server to be ready
echo "\nWaiting for server to be ready...\n";
$maxAttempts = 50;
$attempt = 0;
$ready = false;

while ($attempt < $maxAttempts && !$ready) {
    $attempt++;
    usleep(100000); // 100ms

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 1);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);

    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $ready = true;
        echo "  Server ready after {$attempt} attempts\n";
    }
}

if (!$ready) {
    echo "Error: Server failed to start after {$maxAttempts} attempts\n";
    killServer($serverPid);
    exit(1);
}

// Step 2: Warm-up requests
echo "\nWarm-up phase (100 requests)...\n";
for ($i = 0; $i < 100; $i++) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_exec($ch);
    curl_close($ch);
}
echo "  Warm-up complete\n";

// Step 3: Run benchmark
echo "\n" . str_repeat("-", 72) . "\n";
echo "Running benchmark: {$requests} requests to {$url}\n";
echo str_repeat("-", 72) . "\n\n";

$timings = [];
$errors = 0;
$totalBytes = 0;

$overallStart = microtime(true);

for ($i = 0; $i < $requests; $i++) {
    $start = microtime(true);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $size = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
    curl_close($ch);

    $elapsed = (microtime(true) - $start) * 1000; // Convert to milliseconds

    if ($httpCode === 200 && $response !== false) {
        $timings[] = $elapsed;
        $totalBytes += $size;
    } else {
        $errors++;
    }

    // Progress indicator
    if (($i + 1) % 1000 === 0) {
        $progress = ($i + 1) / $requests * 100;
        $currentReqPerSec = ($i + 1) / (microtime(true) - $overallStart);
        echo sprintf(
            "Progress: %d/%d (%.1f%%) - %.0f req/sec\n",
            $i + 1,
            $requests,
            $progress,
            $currentReqPerSec
        );
    }
}

$overallElapsed = microtime(true) - $overallStart;

// Step 4: Calculate statistics
echo "\n" . str_repeat("=", 72) . "\n";
echo "Results\n";
echo str_repeat("=", 72) . "\n\n";

$successful = count($timings);
sort($timings);

$min = min($timings);
$max = max($timings);
$mean = array_sum($timings) / $successful;
$median = $timings[floor($successful / 2)];
$p95 = $timings[floor($successful * 0.95)];
$p99 = $timings[floor($successful * 0.99)];

$requestsPerSecond = $successful / $overallElapsed;
$avgBytesPerRequest = $totalBytes / $successful;

echo "Request Statistics:\n";
echo "  Total Requests:        " . number_format($requests) . "\n";
echo "  Successful:            " . number_format($successful) . "\n";
echo "  Failed:                " . number_format($errors) . "\n";
echo "  Success Rate:          " . sprintf("%.2f%%", ($successful / $requests) * 100) . "\n";
echo "\n";

echo "Performance:\n";
echo "  Total Time:            " . sprintf("%.3f seconds", $overallElapsed) . "\n";
echo "  Requests/Second:       " . sprintf("%.2f", $requestsPerSecond) . "\n";
echo "  Average Time:          " . sprintf("%.3f ms", $mean) . "\n";
echo "  Bytes/Request:         " . sprintf("%.0f bytes", $avgBytesPerRequest) . "\n";
echo "\n";

echo "Latency Distribution:\n";
echo "  Minimum:               " . sprintf("%.3f ms", $min) . "\n";
echo "  Median (50%):          " . sprintf("%.3f ms", $median) . "\n";
echo "  95th Percentile:       " . sprintf("%.3f ms", $p95) . "\n";
echo "  99th Percentile:       " . sprintf("%.3f ms", $p99) . "\n";
echo "  Maximum:               " . sprintf("%.3f ms", $max) . "\n";
echo "\n";

// Calculate histogram
echo "Response Time Histogram:\n";
$buckets = [1, 2, 5, 10, 20, 50, 100, 200, 500];
$histogram = array_fill_keys($buckets, 0);
$histogram['500+'] = 0;

foreach ($timings as $time) {
    $placed = false;
    foreach ($buckets as $bucket) {
        if ($time < $bucket) {
            $histogram[$bucket]++;
            $placed = true;
            break;
        }
    }
    if (!$placed) {
        $histogram['500+']++;
    }
}

foreach ($histogram as $bucket => $count) {
    if ($count > 0) {
        $percentage = ($count / $successful) * 100;
        $bar = str_repeat('█', min(50, round($percentage)));

        if (is_numeric($bucket)) {
            $label = sprintf("  < %3d ms", $bucket);
        } else {
            $label = "  " . $bucket . " ms";
        }

        echo sprintf(
            "%s: %6d (% 5.1f%%) %s\n",
            $label,
            $count,
            $percentage,
            $bar
        );
    }
}

echo "\n" . str_repeat("=", 72) . "\n";

// Step 5: Clean up
echo "\nCleaning up...\n";
killServer($serverPid);
echo "  Server stopped (PID: {$serverPid})\n";

echo "\nBenchmark complete!\n";

/**
 * Kill the web server process
 */
function killServer(string $pid): void
{
    if (PHP_OS_FAMILY === 'Windows') {
        shell_exec("taskkill /F /PID {$pid} 2>&1");
    } else {
        shell_exec("kill {$pid} 2>&1");
    }

    // Wait a moment for process to die
    usleep(100000);
}
