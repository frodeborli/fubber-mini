#!/usr/bin/env php
<?php

/**
 * Mini Framework Test Runner
 *
 * Finds and runs test files, reports results.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use mini\Test;

$args = array_slice($argv, 1);
$interrupted = false;

// Signal handling
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGINT, function() use (&$interrupted) {
        $interrupted = true;
    });
    pcntl_async_signals(true);
}

// Parse arguments
$path = null;
$filter = null;
$listMode = false;

foreach ($args as $arg) {
    if ($arg === '--list' || $arg === '-l') {
        $listMode = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        showHelp();
        exit(0);
    } elseif (!str_starts_with($arg, '-')) {
        // Positional arg: if it's an existing path use it, otherwise it's a filter
        if ($path === null && (is_dir($arg) || is_file($arg))) {
            $path = $arg;
        } elseif ($filter === null) {
            $filter = $arg;
        }
    }
}

$path = $path ? realpath($path) : realpath(getcwd() . '/tests');
if ($path === false) {
    fwrite(STDERR, "Error: Path not found\n");
    exit(1);
}

$testFiles = is_file($path) ? [$path] : findTestFiles($path);
if ($filter) {
    $testFiles = array_filter($testFiles, fn($f) => stripos(basename($f), $filter) !== false);
}

if ($listMode) {
    foreach ($testFiles as $file) {
        echo basename($file, '.php') . "\n";
    }
    exit(0);
}

if (empty($testFiles)) {
    fwrite(STDERR, "No tests found" . ($filter ? " matching '$filter'" : "") . "\n");
    exit(1);
}

// Run tests
$passed = 0;
$failed = 0;
$basePath = is_file($path) ? dirname($path) : $path;

echo "Running " . count($testFiles) . " test(s)...\n\n";

foreach ($testFiles as $file) {
    if ($interrupted) break;

    $name = preg_replace('/\.php$/', '', str_replace($basePath . '/', '', $file));
    echo "● $name\n";

    $result = Test::runTestFile($file);

    if ($interrupted) {
        echo "\nInterrupted.\n";
        break;
    }

    $result['exitCode'] === 0 ? $passed++ : $failed++;
}

// Summary
echo "\n─────────────────────────────────────────\n";
if ($interrupted) {
    $remaining = count($testFiles) - $passed - $failed;
    echo "Passed: $passed, Failed: $failed, Skipped: $remaining (interrupted)\n";
    exit(130);
}
echo "Passed: $passed, Failed: $failed\n";
exit($failed > 0 ? 1 : 0);

// --- Functions ---

function findTestFiles(string $dir): array {
    $files = [];
    $skip = ['/^debug_/', '/^_/', '/benchmark/i', '/profile/i', '/^assert$/'];

    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($iter as $file) {
        if ($file->getExtension() !== 'php') continue;
        $name = $file->getBasename('.php');
        $path = $file->getPathname();
        if (preg_match('#[/\\\\]_[^/\\\\]*[/\\\\]#', $path)) continue;
        foreach ($skip as $pattern) {
            if (preg_match($pattern, $name)) continue 2;
        }
        $files[] = $path;
    }

    sort($files);
    return $files;
}

function showHelp(): void {
    echo <<<'HELP'
Mini Framework Test Runner

Usage: mini test [path] [filter]

Arguments:
  path      Directory or file to test (default: tests/)
  filter    Only run tests matching this string

Options:
  --list, -l    List available tests
  --help, -h    Show this help

Examples:
  mini test                     Run all tests
  mini test tests/Auth.php      Run single test file
  mini test tests/ Router       Run tests matching "Router"

HELP;
}
