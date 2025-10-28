#!/usr/bin/env php
<?php

/**
 * Test MachineSalt utility
 */

$autoloader = realpath(__DIR__ . '/../vendor/autoload.php')
    ?: realpath(__DIR__ . '/../../../vendor/autoload.php')
    ?: realpath(__DIR__ . '/../../vendor/autoload.php');

require_once $autoloader;

use mini\Util\MachineSalt;

function test(string $description, callable $test): void {
    try {
        $test();
        echo "✓ {$description}\n";
    } catch (\Exception $e) {
        echo "✗ {$description}\n";
        echo "  Error: {$e->getMessage()}\n";
        echo "  {$e->getFile()}:{$e->getLine()}\n";
    }
}

function assertEqual($expected, $actual, string $message = ''): void {
    if ($expected !== $actual) {
        throw new \Exception($message ?: "Expected != Actual");
    }
}

function assertTrue($condition, string $message = ''): void {
    if (!$condition) {
        throw new \Exception($message ?: "Condition is false");
    }
}

echo "MachineSalt Tests\n";
echo "=================\n\n";

// Test 1: Salt is generated
test("Salt is generated", function() {
    $salt = MachineSalt::get();
    assertTrue(strlen($salt) > 0, "Salt should not be empty");
});

// Test 2: Salt is 64 characters (SHA-256 hex)
test("Salt is 64 characters (SHA-256)", function() {
    $salt = MachineSalt::get();
    assertEqual(64, strlen($salt), "Salt should be 64 chars");
});

// Test 3: Salt is hexadecimal
test("Salt is hexadecimal", function() {
    $salt = MachineSalt::get();
    assertTrue(ctype_xdigit($salt), "Salt should be hex");
});

// Test 4: Salt is consistent across calls
test("Salt is consistent across calls", function() {
    $salt1 = MachineSalt::get();
    $salt2 = MachineSalt::get();
    assertEqual($salt1, $salt2, "Salt should be same across calls");
});

// Test 5: Salt file is created
test("Salt file is created in temp dir", function() {
    MachineSalt::get();
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mini_framework_salt.txt';
    assertTrue(file_exists($file), "Salt file should exist");
});

// Test 6: Salt file contains valid random data
test("Salt file contains 64 hex characters", function() {
    MachineSalt::get();
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mini_framework_salt.txt';
    $content = trim(file_get_contents($file));
    assertEqual(64, strlen($content), "File should contain 64 chars");
    assertTrue(ctype_xdigit($content), "File content should be hex");
});

// Test 7: Mini uses MachineSalt
test("Mini::salt uses MachineSalt when MINI_SALT not set", function() {
    $salt = mini\Mini::$mini->salt;
    assertTrue(strlen($salt) === 64, "Mini salt should be 64 chars");
    assertTrue(ctype_xdigit($salt), "Mini salt should be hex");
});

echo "\n✅ All MachineSalt tests passed!\n\n";
echo "Machine salt: " . MachineSalt::get() . "\n";
echo "Mini salt:    " . mini\Mini::$mini->salt . "\n";
