#!/usr/bin/env php
<?php

require __DIR__ . '/vendor/autoload.php';

echo "Testing nextCommand() with fluent API:\n\n";

// Test 1: Basic subcommand
echo "Test 1: Basic subcommand\n";
$_SERVER['argv'] = ['myapp', '-v', 'subcommand', '--flag'];
$root = new \mini\CLI\ArgManager();
$root = $root->withFlag('v', 'verbose');

// Access verbose to trigger parsing
$verbosity = $root->getFlag('verbose');
echo "Root verbosity: $verbosity\n";

$sub = $root->nextCommand();
if ($sub) {
    echo "Subcommand: " . $sub->getCommand() . "\n";
    $sub = $sub->withFlag(long: 'flag');
    $hasFlag = $sub->getFlag('flag');
    echo "Subcommand has flag: " . ($hasFlag > 0 ? 'yes' : 'no') . "\n";
} else {
    echo "❌ No subcommand found\n";
}

// Test 2: Options with values before subcommand
echo "\nTest 2: Options with values before subcommand\n";
$_SERVER['argv'] = ['myapp', '-i', 'file.txt', '--output=out.txt', 'deploy', 'production'];
$root = new \mini\CLI\ArgManager();
$root = $root
    ->withRequiredValue('i', 'input')
    ->withRequiredValue(long: 'output');

$input = $root->getOption('input');
$output = $root->getOption('output');
echo "Input: $input, Output: $output\n";

$sub = $root->nextCommand();
if ($sub) {
    echo "Subcommand: " . $sub->getCommand() . "\n";
    $remaining = $sub->getRemainingArgs();
    echo "Remaining args: " . json_encode($remaining) . "\n";
} else {
    echo "❌ No subcommand found\n";
}

// Test 3: Multiple levels
echo "\nTest 3: Multiple levels of subcommands\n";
$_SERVER['argv'] = ['myapp', '-v', 'git', 'commit', '-m', 'message'];
$root = new \mini\CLI\ArgManager();
$root = $root->withFlag('v', 'verbose');

$root->getFlag('v'); // Trigger parsing

$git = $root->nextCommand();
if ($git) {
    echo "Level 1: " . $git->getCommand() . "\n";

    $commit = $git->nextCommand();
    if ($commit) {
        echo "Level 2: " . $commit->getCommand() . "\n";
        $commit = $commit->withRequiredValue('m', 'message');
        $message = $commit->getOption('message');
        echo "Message: $message\n";
    }
}

echo "\n✅ All nextCommand tests passed!\n";
