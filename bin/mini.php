#!/usr/bin/env php
<?php

/**
 * Mini Framework CLI Tool
 *
 * Unified command-line interface for Mini framework tools
 */

// We need to load autoloader first before we can use ArgManager
// So we'll keep this simple - just get the command name
$command = $argv[1] ?? null;

$availableCommands = [
    'serve' => [
        'description' => 'Start development server',
        'script' => 'mini-serve.php',
        'examples' => [
            'serve' => 'Start dev server on 127.0.0.1:8080',
            'serve --host 0.0.0.0 --port 3000' => 'Start on all interfaces, port 3000'
        ]
    ],
    'benchmark' => [
        'description' => 'Benchmark framework performance',
        'script' => 'mini-benchmark.php',
        'examples' => [
            'benchmark' => 'Run HTTP benchmark'
        ]
    ],
    'translations' => [
        'description' => 'Manage translation files',
        'script' => 'mini-translations.php',
        'examples' => [
            'translations' => 'Validate all translations',
            'translations add-missing' => 'Add missing translation strings',
            'translations add-language es' => 'Create Spanish translation files',
            'translations remove-orphans' => 'Remove unused translations'
        ]
    ],
    'migrations' => [
        'description' => 'Run migrations with tracking and rollback',
        'script' => 'mini-migrations.php',
        'examples' => [
            'migrations' => 'Run all pending migrations',
            'migrations status' => 'Show migration status',
            'migrations rollback' => 'Rollback last batch',
            'migrations make create_users' => 'Create new migration file'
        ]
    ],
    'docs' => [
        'description' => 'Browse PHP documentation',
        'script' => 'mini-docs.php',
        'examples' => [
            'docs mini' => 'Show mini namespace overview',
            'docs "mini\\Mini"' => 'Show Mini class documentation',
            'docs search Router' => 'Search entity names for "Router"',
            'docs "mini\\db"' => 'Show db() function documentation'
        ]
    ],
    'test' => [
        'description' => 'Run tests',
        'script' => 'mini-test.php',
        'examples' => [
            'test' => 'Run all tests in tests/',
            'test tests/ Router' => 'Run tests matching "Router"',
            'test --list' => 'List available tests'
        ]
    ],
    'db' => [
        'description' => 'Interactive database shell',
        'script' => 'mini-db.php',
        'examples' => [
            'db' => 'Start interactive SQL REPL',
            "db 'SELECT * FROM users'" => 'Execute query directly'
        ]
    ],
    'vdb' => [
        'description' => 'VirtualDatabase shell (for testing)',
        'script' => 'mini-db.php',
        'prepend_args' => ['-v'],
        'examples' => [
            'vdb' => 'Start VirtualDatabase REPL',
            "vdb 'SELECT * FROM users'" => 'Query VirtualDatabase directly',
            "vdb '.schema'" => 'Show VirtualDatabase schema'
        ]
    ]
];

function showHelp($availableCommands) {
    echo "Mini Framework CLI\n";
    echo "==================\n\n";
    echo "Usage: mini <command> [options]\n\n";
    echo "Available commands:\n";

    foreach ($availableCommands as $cmd => $info) {
        echo sprintf("  %-12s %s\n", $cmd, $info['description']);
    }

    echo "\nExamples:\n";
    foreach ($availableCommands as $info) {
        foreach ($info['examples'] as $example => $description) {
            echo sprintf("  mini %-20s # %s\n", $example, $description);
        }
    }

    echo "\nFor more help on a specific command, run: mini <command> --help\n";
}

if (!$command || $command === 'help' || $command === '--help' || $command === '-h') {
    showHelp($availableCommands);
    exit(0);
}

if (!isset($availableCommands[$command])) {
    echo "Unknown command: {$command}\n";
    echo "Run 'mini help' to see available commands.\n";
    exit(1);
}

// Execute the appropriate script
$scriptPath = __DIR__ . '/' . $availableCommands[$command]['script'];

if (!file_exists($scriptPath)) {
    echo "Error: Script not found: {$scriptPath}\n";
    exit(1);
}

// Build arguments
$args = [];

// Add prepend_args if defined (e.g., vdb prepends -v)
if (isset($availableCommands[$command]['prepend_args'])) {
    $args = array_merge($args, $availableCommands[$command]['prepend_args']);
}

$args = array_merge($args, array_slice($argv, 2));

// Build the command array
$cmd = ['php', $scriptPath];
$cmd = array_merge($cmd, $args);

// Ignore SIGINT in parent - let child handle it
pcntl_signal(SIGINT, SIG_IGN);

// Use proc_open with direct FD pass-through to preserve TTY
$process = proc_open($cmd, [STDIN, STDOUT, STDERR], $pipes);
if (is_resource($process)) {
    $exitCode = proc_close($process);
    exit($exitCode);
}

echo "Failed to execute command\n";
exit(1);