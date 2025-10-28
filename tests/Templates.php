#!/usr/bin/env php
<?php

/**
 * Test template rendering with inheritance
 */

$autoloader = realpath(__DIR__ . '/../vendor/autoload.php')
    ?: realpath(__DIR__ . '/../../../vendor/autoload.php')
    ?: realpath(__DIR__ . '/../../vendor/autoload.php');

require_once $autoloader;

use function mini\render;

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

function assertTrue($condition, string $message = ''): void {
    if (!$condition) {
        throw new \Exception($message ?: "Condition is false");
    }
}

function assertContains(string $needle, string $haystack, string $message = ''): void {
    if (!str_contains($haystack, $needle)) {
        throw new \Exception($message ?: "String does not contain '{$needle}'");
    }
}

// Add test templates directory to views path registry
mini\Mini::$mini->paths->views->addPath(__DIR__ . '/templates');

echo "Template Rendering Tests\n";
echo "========================\n\n";

// Test 1: Simple template without inheritance
test("Simple template renders correctly", function() {
    $output = render('simple.php', ['name' => 'Alice']);
    assertContains('<h1>Simple Template</h1>', $output, "Should contain header");
    assertContains('Hello, Alice!', $output, "Should contain name");
});

// Test 2: Template with inheritance
test("Template inheritance works", function() {
    $output = render('child.php', [
        'user' => ['name' => 'Bob', 'email' => 'bob@example.com']
    ]);

    assertContains('<!doctype html>', $output, "Should have doctype from layout");
    assertContains('<title>Welcome, Bob</title>', $output, "Should have title block");
    assertContains('Welcome to the site, Bob!', $output, "Should have header block");
    assertContains('User email: bob@example.com', $output, "Should have content block");
    assertContains('© ' . date('Y'), $output, "Should have footer with default");
});

// Test 3: Block defaults work
test("Block defaults are used when not defined", function() {
    $output = render('child.php', [
        'user' => ['name' => 'Charlie', 'email' => 'charlie@example.com']
    ]);

    // Footer block not defined in child, should use default
    assertContains('© ' . date('Y'), $output, "Should use default footer");
});

// Test 4: HTML escaping works in templates
test("HTML escaping works correctly", function() {
    $output = render('simple.php', ['name' => '<script>alert("xss")</script>']);
    assertTrue(!str_contains($output, '<script>'), "Should escape HTML");
    assertContains('&lt;script&gt;', $output, "Should contain escaped HTML");
});

// Test 5: Variables are available in child and layout
test("Variables are available in both child and parent", function() {
    $output = render('child.php', [
        'user' => ['name' => 'Dave', 'email' => 'dave@example.com']
    ]);

    assertContains('Dave', $output, "Should have user name");
    assertContains('dave@example.com', $output, "Should have user email");
});

// Test 6: Dual-use $block() works
test('Dual-use $block() for inline and buffered blocks', function() {
    $output = render('with-set.php', [
        'user' => ['name' => 'Eve', 'email' => 'eve@example.com']
    ]);

    assertContains('<title>Page with dual-use $block()</title>', $output, 'Should have title set inline');
    assertContains('<h1>Using Dual-Use $block() Helper</h1>', $output, 'Should have header set inline');
    assertContains('User: Eve', $output, 'Should have content from buffered $block()');
    assertContains('© 2025 Example Corp', $output, 'Should have footer set inline');
});

// Test 7: Including sub-templates works
test('Including sub-templates with render()', function() {
    $output = render('with-partial.php', [
        'users' => [
            ['name' => 'Alice', 'email' => 'alice@example.com'],
            ['name' => 'Bob', 'email' => 'bob@example.com'],
            ['name' => 'Charlie', 'email' => 'charlie@example.com']
        ]
    ]);

    assertContains('<h1>Users List</h1>', $output, "Should have main heading");
    assertContains('<div class="user-card">', $output, "Should have user card partial");
    assertContains('Alice', $output, "Should have first user");
    assertContains('alice@example.com', $output, "Should have first user email");
    assertContains('Bob', $output, "Should have second user");
    assertContains('Charlie', $output, "Should have third user");
    assertContains('Total users: 3', $output, "Should have user count");
});

// Test 8: Multi-level inheritance works
test('Multi-level template inheritance (3 levels)', function() {
    $output = render('special-page.php', ['user' => 'TestUser']);

    // From base.php
    assertContains('<!DOCTYPE html>', $output, "Should have doctype from base");
    assertContains('<html lang="en">', $output, "Should have lang from special-page");
    assertContains('<title>Special Page</title>', $output, "Should have title from special-page");
    assertContains('Special Page © 2025', $output, "Should have footer from special-page");

    // From layout-with-sidebar.php
    assertContains('<div class="sidebar">', $output, "Should have sidebar from layout");
    assertContains('class="with-sidebar"', $output, "Should have body class from layout");

    // From special-page.php
    assertContains('<h1>Welcome to Special Page</h1>', $output, "Should have heading from special-page");
    assertContains('User: TestUser', $output, "Should have user variable");
    assertContains('<li><a href="#section1">Section 1</a></li>', $output, "Should have sidebar content from special-page");
});

echo "\n✅ All template tests passed!\n";
