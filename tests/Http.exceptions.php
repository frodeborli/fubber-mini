<?php

// Find composer autoloader using the standard pattern
$autoloader = realpath(__DIR__ . '/../vendor/autoload.php')
    ?: realpath(__DIR__ . '/../../../vendor/autoload.php')
    ?: realpath(__DIR__ . '/../../vendor/autoload.php');

if (!require $autoloader) {
    fwrite(STDERR, "Error: Could not find composer autoloader\n");
    exit(1);
}

// Simple test helpers
function test(string $description, callable $test): void
{
    try {
        $test();
        echo "✓ $description\n";
    } catch (Exception $e) {
        fwrite(STDERR, "✗ $description\n");
        fwrite(STDERR, "  " . $e->getMessage() . "\n");
        exit(1);
    }
}

function assertEqual($expected, $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        $expectedStr = var_export($expected, true);
        $actualStr = var_export($actual, true);
        throw new Exception("$message\nExpected: $expectedStr\nActual: $actualStr");
    }
}

use mini\Http\BadRequestException;
use mini\Http\AccessDeniedException;
use mini\Http\NotFoundException;
use mini\Http\HttpException;

// Test HTTP exception classes
test('BadRequestException has correct status code', function() {
    $exception = new BadRequestException('Invalid input');
    assertEqual(400, $exception->getStatusCode());
    assertEqual('Bad Request', $exception->getStatusMessage());
    assertEqual('Invalid input', $exception->getMessage());
});

test('AccessDeniedException has correct status code', function() {
    $exception = new AccessDeniedException('Login required');
    assertEqual(401, $exception->getStatusCode());
    assertEqual('Unauthorized', $exception->getStatusMessage());
    assertEqual('Login required', $exception->getMessage());
});

test('NotFoundException has correct status code', function() {
    $exception = new NotFoundException('Page not found');
    assertEqual(404, $exception->getStatusCode());
    assertEqual('Not Found', $exception->getStatusMessage());
    assertEqual('Page not found', $exception->getMessage());
});

test('Direct exception throwing works correctly', function() {
    try {
        throw new BadRequestException('Invalid data');
    } catch (BadRequestException $e) {
        assertEqual(400, $e->getStatusCode());
        assertEqual('Invalid data', $e->getMessage());
    }

    try {
        throw new AccessDeniedException('Not logged in');
    } catch (AccessDeniedException $e) {
        assertEqual(401, $e->getStatusCode());
        assertEqual('Not logged in', $e->getMessage());
    }

    try {
        throw new NotFoundException('Missing resource');
    } catch (NotFoundException $e) {
        assertEqual(404, $e->getStatusCode());
        assertEqual('Missing resource', $e->getMessage());
    }
});

echo "All HTTP exception tests passed!\n";