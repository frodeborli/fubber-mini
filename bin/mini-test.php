#!/usr/bin/env php
<?php

/**
 * Mini Framework Test Runner
 *
 * Usage:
 *   mini test                    Run tests in tests/ (default)
 *   mini test tests/             Run tests in specified directory
 *   mini test tests/Auth.php     Run single test file
 *   mini test tests/ Router      Run tests matching "Router"
 *   mini test --list             List available tests
 */

$args = array_slice($argv, 1);

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
    } elseif ($path === null && (is_dir($arg) || is_file($arg))) {
        $path = $arg;
    } elseif ($filter === null && !str_starts_with($arg, '-')) {
        $filter = $arg;
    }
}

// Default to tests/ in current directory
if ($path === null) {
    $path = getcwd() . '/tests';
}

// Resolve to absolute path
$path = realpath($path);
if ($path === false) {
    fwrite(STDERR, "Error: Path not found\n");
    exit(1);
}

// Collect test files
$testFiles = [];

if (is_file($path)) {
    $testFiles[] = $path;
} else {
    $testFiles = findTestFiles($path);
}

// Apply filter
if ($filter) {
    $testFiles = array_filter($testFiles, fn($f) => stripos(basename($f), $filter) !== false);
}

// List mode
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
echo "Running " . count($testFiles) . " test(s)...\n\n";

$passed = 0;
$failed = 0;
$failedTests = [];

// Base path for display
$basePath = is_file($path) ? dirname($path) : $path;

foreach ($testFiles as $file) {
    // Show relative path from test directory
    $name = str_replace($basePath . '/', '', $file);
    $name = preg_replace('/\.php$/', '', $name);

    // Run test in subprocess for isolation
    // Enable assertions and make them throw exceptions
    $cmd = sprintf('php -d zend.assertions=1 -d assert.exception=1 %s 2>&1', escapeshellarg($file));
    $output = [];
    $exitCode = 0;
    exec($cmd, $output, $exitCode);

    if ($exitCode === 0) {
        echo "✓ $name\n";
        $passed++;
    } else {
        echo "✗ $name\n";
        $failedTests[$name] = implode("\n", $output);
        $failed++;
    }
}

echo "\n";

// Show failure details
if ($failed > 0) {
    echo "─────────────────────────────────────────\n";
    echo "FAILURES:\n\n";
    foreach ($failedTests as $name => $output) {
        echo "[$name]\n";
        echo $output . "\n\n";
    }
}

// Summary
echo "─────────────────────────────────────────\n";
echo "Passed: $passed, Failed: $failed\n";

exit($failed > 0 ? 1 : 0);

// --- Functions ---

function findTestFiles(string $dir): array
{
    $files = [];

    // Skip patterns
    $skipPrefixes = ['debug_', 'benchmark-', '_'];
    $skipNames = ['assert'];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') continue;

        $name = $file->getBasename('.php');
        $path = $file->getPathname();

        // Skip if any parent directory starts with _
        if (preg_match('#[/\\\\]_[^/\\\\]*[/\\\\]#', $path)) continue;

        // Skip helper/utility files
        if (in_array($name, $skipNames)) continue;

        // Skip by prefix
        foreach ($skipPrefixes as $prefix) {
            if (str_starts_with($name, $prefix)) continue 2;
        }

        $files[] = $path;
    }

    sort($files);
    return $files;
}

function showHelp(): void
{
    echo <<<'HELP'
Mini Framework Test Runner

Usage:
  mini test [path] [filter]

Arguments:
  path      Directory or file to test (default: tests/)
  filter    Only run tests matching this string

Options:
  --list, -l    List available tests
  --help, -h    Show this help

Examples:
  mini test                     Run all tests in tests/
  mini test tests/              Run all tests in tests/
  mini test tests/Auth.php      Run single test file
  mini test tests/ Router       Run tests matching "Router"
  mini test --list              List available tests

Writing Tests:
  A test is a PHP file that exits 0 on success, non-zero on failure.
  Use assert() or include tests/assert.php for helpers.

  <?php
  require __DIR__ . '/assert.php';

  assert_eq('expected', actualFunction());
  echo "✓ Test passed\n";

HELP;
}
