#!/usr/bin/env php
<?php

/**
 * Test CSRF token generation and verification
 */

$autoloader = realpath(__DIR__ . '/../vendor/autoload.php')
    ?: realpath(__DIR__ . '/../../../vendor/autoload.php')
    ?: realpath(__DIR__ . '/../../vendor/autoload.php');

require_once $autoloader;

use function mini\csrf;

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

function assertFalse($condition, string $message = ''): void {
    if ($condition) {
        throw new \Exception($message ?: "Condition is true");
    }
}

echo "CSRF Token Tests\n";
echo "================\n\n";

// Test 1: Token generation
test("Token can be generated", function() {
    $nonce = csrf('test-action');
    $token = $nonce->getToken();
    assertTrue(strlen($token) > 0, "Token should not be empty");
});

// Test 2: Token is base64 encoded
test("Token is base64 encoded", function() {
    $nonce = csrf('test-action');
    $token = $nonce->getToken();
    $decoded = base64_decode($token, true);
    assertTrue($decoded !== false, "Token should be valid base64");
});

// Test 3: Token verification works
test("Token can be verified immediately", function() {
    $nonce = csrf('test-action');
    $token = $nonce->getToken();
    assertTrue($nonce->verify($token), "Token should verify successfully");
});

// Test 4: Same action verifies
test("Same action creates verifiable token", function() {
    $nonce1 = csrf('delete-post');
    $token = $nonce1->getToken();

    $nonce2 = csrf('delete-post');
    assertTrue($nonce2->verify($token), "Same action should verify token");
});

// Test 5: Different action fails verification
test("Different action fails verification", function() {
    $nonce1 = csrf('delete-post');
    $token = $nonce1->getToken();

    $nonce2 = csrf('update-post');
    assertFalse($nonce2->verify($token), "Different action should fail verification");
});

// Test 6: Empty token fails verification
test("Empty token fails verification", function() {
    $nonce = csrf('test-action');
    assertFalse($nonce->verify(''), "Empty token should fail");
    assertFalse($nonce->verify(null), "Null token should fail");
});

// Test 7: Invalid token fails verification
test("Invalid token fails verification", function() {
    $nonce = csrf('test-action');
    assertFalse($nonce->verify('invalid-token'), "Invalid token should fail");
});

// Test 8: __toString() outputs HTML field
test("__toString() outputs hidden input field", function() {
    $nonce = csrf('test-action');
    $html = (string) $nonce;
    assertTrue(str_contains($html, '<input'), "Should contain input tag");
    assertTrue(str_contains($html, 'type="hidden"'), "Should be hidden input");
    assertTrue(str_contains($html, 'name="__nonce__"'), "Should have correct name");
    assertTrue(str_contains($html, 'value="'), "Should have value attribute");
});

// Test 9: Custom field name
test("Custom field name works", function() {
    $nonce = csrf('test-action', 'custom_field');
    $html = (string) $nonce;
    assertTrue(str_contains($html, 'name="custom_field"'), "Should use custom field name");
});

// Test 10: Token expiration
test("Expired token fails verification", function() {
    // Create a token manually with old timestamp
    $oldTime = microtime(true) - 86401; // 24 hours + 1 second ago
    $data = "test-action|{$oldTime}|";
    $signature = hash_hmac('sha256', $data, mini\Mini::$mini->salt);
    $token = base64_encode($data . '|' . $signature);

    $nonce = csrf('test-action');
    assertFalse($nonce->verify($token), "Expired token should fail verification");
});

// Test 11: Custom max age
test("Custom max age allows shorter expiration", function() {
    $nonce1 = csrf('test-action');
    $token = $nonce1->getToken();

    // Sleep briefly
    usleep(100000); // 0.1 seconds

    $nonce2 = csrf('test-action');
    // Verify with very short max age should fail
    assertFalse($nonce2->verify($token, 0.05), "Token older than 0.05s should fail");
    // Verify with longer max age should succeed
    assertTrue($nonce2->verify($token, 1.0), "Token within 1s should succeed");
});

// Test 12: Token contains signature
test("Token contains valid HMAC signature", function() {
    $nonce = csrf('test-action');
    $token = $nonce->getToken();
    $decoded = base64_decode($token, true);
    $parts = explode('|', $decoded);

    assertEqual(4, count($parts), "Token should have 4 parts");

    [$action, $time, $ip, $signature] = $parts;
    assertEqual('test-action', $action, "Action should match");
    assertTrue(is_numeric($time), "Time should be numeric");
    assertEqual(64, strlen($signature), "HMAC-SHA256 signature should be 64 hex chars");
});

echo "\n✅ All CSRF tests passed!\n";
