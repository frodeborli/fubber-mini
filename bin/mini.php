#!/usr/bin/env php
<?php

/**
 * Mini Framework CLI Tool
 *
 * Unified command-line interface for Mini framework tools
 */

$command = $argv[1] ?? null;
$args = array_slice($argv, 2);

$availableCommands = [
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
        'description' => 'Run database migrations',
        'script' => 'mini-migrations.php',
        'examples' => [
            'migrations' => 'Run all pending migrations'
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

// Build the command with arguments
$cmd = ['php', $scriptPath];
$cmd = array_merge($cmd, $args);

// Execute the command
$descriptorspec = [
    0 => STDIN,
    1 => STDOUT,
    2 => STDERR
];

$process = proc_open($cmd, $descriptorspec, $pipes);
if (is_resource($process)) {
    $exitCode = proc_close($process);
    exit($exitCode);
} else {
    echo "Failed to execute command\n";
    exit(1);
}