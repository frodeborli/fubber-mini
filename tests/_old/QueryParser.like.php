<?php

/**
 * Test runner for LIKE operator functionality in QueryParser class
 *
 * Usage: php mini/tests/QueryParser.like.php
 */

// Find composer autoloader using the standard pattern
$autoloader = realpath(__DIR__ . '/../vendor/autoload.php')
    ?: realpath(__DIR__ . '/../../../vendor/autoload.php')
    ?: realpath(__DIR__ . '/../../vendor/autoload.php');

if (!require $autoloader) {
    fwrite(STDERR, "Error: Could not find composer autoloader\n");
    exit(1);
}

use mini\Util\QueryParser;

/**
 * Simple test assertion helper
 */
function test(string $description, callable $test): void
{
    try {
        $test();
        echo "✓ $description\n";
    } catch (Exception $e) {
        fwrite(STDERR, "✗ $description\n");
        fwrite(STDERR, "  Error: " . $e->getMessage() . "\n");
        exit(1);
    }
}

/**
 * Assert two values are equal
 */
function assertEqual($expected, $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        $msg = $message ?: "Expected '$expected', got '$actual'";
        throw new Exception($msg);
    }
}

echo "Running QueryParser LIKE operator tests...\n\n";

// Test 1: Contains pattern (*pattern*)
test("Contains pattern matching", function() {
    $qp = new QueryParser('name:like=*john*');

    assertEqual(true, $qp->matches(['name' => 'john']));
    assertEqual(true, $qp->matches(['name' => 'John'])); // Case insensitive
    assertEqual(true, $qp->matches(['name' => 'Mr. John Doe']));
    assertEqual(true, $qp->matches(['name' => 'johnny']));
    assertEqual(false, $qp->matches(['name' => 'jane']));
});

// Test 2: Starts with pattern (pattern*)
test("Starts with pattern matching", function() {
    $qp = new QueryParser('name:like=John*');

    assertEqual(true, $qp->matches(['name' => 'John']));
    assertEqual(true, $qp->matches(['name' => 'john'])); // Case insensitive
    assertEqual(true, $qp->matches(['name' => 'Johnny']));
    assertEqual(true, $qp->matches(['name' => 'John Doe']));
    assertEqual(false, $qp->matches(['name' => 'Mr. John']));
    assertEqual(false, $qp->matches(['name' => 'jane']));
});

// Test 3: Ends with pattern (*pattern)
test("Ends with pattern matching", function() {
    $qp = new QueryParser('name:like=*son');

    assertEqual(true, $qp->matches(['name' => 'son']));
    assertEqual(true, $qp->matches(['name' => 'Johnson']));
    assertEqual(true, $qp->matches(['name' => 'Anderson']));
    assertEqual(false, $qp->matches(['name' => 'sons']));
    assertEqual(false, $qp->matches(['name' => 'John']));
});

// Test 4: Exact match (no wildcards)
test("Exact match pattern", function() {
    $qp = new QueryParser('name:like=John');

    assertEqual(true, $qp->matches(['name' => 'John']));
    assertEqual(true, $qp->matches(['name' => 'john'])); // Case insensitive
    assertEqual(false, $qp->matches(['name' => 'Johnny']));
    assertEqual(false, $qp->matches(['name' => 'Mr. John']));
});

// Test 5: Multiple wildcards
test("Multiple wildcards pattern", function() {
    $qp = new QueryParser('email:like=*@*gmail.com');

    assertEqual(true, $qp->matches(['email' => 'john@gmail.com']));
    assertEqual(true, $qp->matches(['email' => 'test.user@gmail.com']));
    assertEqual(true, $qp->matches(['email' => 'a@somegmail.com']));
    assertEqual(false, $qp->matches(['email' => 'john@yahoo.com']));
    assertEqual(false, $qp->matches(['email' => 'gmail.com']));
});

// Test 6: Numeric values with LIKE
test("Numeric values with LIKE", function() {
    $qp = new QueryParser('id:like=*1');

    assertEqual(true, $qp->matches(['id' => 1]));
    assertEqual(true, $qp->matches(['id' => 21]));
    assertEqual(true, $qp->matches(['id' => 131]));
    assertEqual(true, $qp->matches(['id' => '1'])); // String numbers
    assertEqual(true, $qp->matches(['id' => '21']));
    assertEqual(false, $qp->matches(['id' => 2]));
    assertEqual(false, $qp->matches(['id' => 10])); // 10 doesn't end with 1
});

// Test 7: Special characters in pattern
test("Special regex characters in pattern", function() {
    $qp = new QueryParser('text:like=*test.value*');

    assertEqual(true, $qp->matches(['text' => 'my test.value here']));
    assertEqual(true, $qp->matches(['text' => 'test.value']));
    assertEqual(false, $qp->matches(['text' => 'my testXvalue here'])); // . should match literal dot
});

// Test 8: Empty and null values
test("Empty and null values with LIKE", function() {
    $qp = new QueryParser('name:like=*test*');

    assertEqual(false, $qp->matches(['name' => '']));
    assertEqual(false, $qp->matches(['name' => null]));
    assertEqual(false, $qp->matches([])); // Missing field
});

// Test 9: Complex ordinal patterns (future transformations.json use case)
test("Complex ordinal patterns", function() {
    // Test patterns for ordinal transformations
    $qp1 = new QueryParser('num:like=*1');
    assertEqual(true, $qp1->matches(['num' => 1]));
    assertEqual(true, $qp1->matches(['num' => 21]));
    assertEqual(true, $qp1->matches(['num' => 101]));
    assertEqual(true, $qp1->matches(['num' => 11])); // 11 does end with 1 (special teen case needs additional conditions)

    $qp2 = new QueryParser('num:like=*2');
    assertEqual(true, $qp2->matches(['num' => 2]));
    assertEqual(true, $qp2->matches(['num' => 22]));
    assertEqual(true, $qp2->matches(['num' => 12])); // 12 does end with 2 (special teen case needs additional conditions)

    $qp3 = new QueryParser('num:like=*3');
    assertEqual(true, $qp3->matches(['num' => 3]));
    assertEqual(true, $qp3->matches(['num' => 23]));
    assertEqual(true, $qp3->matches(['num' => 13])); // 13 does end with 3 (special teen case needs additional conditions)
});

// Test 10: Multiple conditions with LIKE
test("Multiple conditions with LIKE", function() {
    $qp = new QueryParser('name:like=John*&email:like=*@gmail.com');

    assertEqual(true, $qp->matches(['name' => 'John Doe', 'email' => 'john@gmail.com']));
    assertEqual(true, $qp->matches(['name' => 'Johnny', 'email' => 'test@gmail.com']));
    assertEqual(false, $qp->matches(['name' => 'John Doe', 'email' => 'john@yahoo.com']));
    assertEqual(false, $qp->matches(['name' => 'Jane Doe', 'email' => 'jane@gmail.com']));
});

// Test 11: Query structure for SQL conversion
test("Query structure for SQL conversion", function() {
    $qp = new QueryParser('name:like=John*&age:gte=18');
    $structure = $qp->getQueryStructure();

    assertEqual(true, isset($structure['name']['LIKE']), "LIKE operator should be present in structure");
    assertEqual('John*', $structure['name']['LIKE'], "Pattern should be preserved for SQL conversion");
    assertEqual(true, isset($structure['age']['>=']), "Other operators should work normally");
});

echo "\n✅ All QueryParser LIKE operator tests passed!\n";
echo "🎯 LIKE operator ready for use in transformations.json and database queries\n";