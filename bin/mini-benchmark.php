#!/usr/bin/env php
<?php

/**
 * Mini Framework Benchmark
 *
 * Usage: php bin/mini-benchmark.php
 */

echo "\nMini Framework Benchmark\n";
echo str_repeat("=", 40) . "\n\n";

// Start server in background
$cmd = 'php -S 127.0.0.1:8765 -t ' . dirname(__DIR__) . '/html >/dev/null 2>&1 & echo $!';
$pid = trim(shell_exec($cmd));

echo "Starting server (PID: $pid)...\n";
sleep(1); // Wait for server to start

// Use ab if available, otherwise fallback
exec('which ab', $output, $code);
if ($code === 0) {
    echo "Running Apache Bench (1000 requests)...\n\n";
    passthru('ab -n 1000 -c 1 -q http://127.0.0.1:8765/ping 2>&1 | grep -E "(Requests per second|Time per request:|  50%|  95%)"');
} else {
    echo "Apache Bench not found, using curl...\n";
    $start = microtime(true);
    for ($i = 0; $i < 100; $i++) {
        @file_get_contents('http://127.0.0.1:8765/ping');
    }
    $elapsed = microtime(true) - $start;
    $rps = 100 / $elapsed;
    echo "\nRequests/sec: " . number_format($rps, 2) . "\n";
}

// Cleanup
posix_kill($pid, 15);
echo "\n";
