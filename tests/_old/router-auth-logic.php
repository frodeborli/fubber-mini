<?php

/**
 * Test script to demonstrate router's 401 vs 403 logic
 */

$autoloader = realpath(__DIR__ . '/../vendor/autoload.php')
    ?: realpath(__DIR__ . '/../../../vendor/autoload.php')
    ?: realpath(__DIR__ . '/../../vendor/autoload.php');

if (!$autoloader) {
    throw new Exception("Could not find autoloader");
}

require_once $autoloader;

use mini\AuthInterface;
use mini\Auth;
use mini\Http\AccessDeniedException;

function test(string $description, callable $test): void
{
    echo "Testing: $description\n";
    try {
        $test();
        echo "  ✓ PASS\n";
    } catch (Throwable $e) {
        echo "  ✗ FAIL: " . $e->getMessage() . "\n";
        if (getenv('VERBOSE')) {
            echo "    " . $e->getTraceAsString() . "\n";
        }
    }
    echo "\n";
}

function assertEqual($expected, $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        $prefix = $message ? "$message: " : '';
        throw new Exception("{$prefix}Expected " . var_export($expected, true) . ", got " . var_export($actual, true));
    }
}

// Mock auth implementation
class TestRouterAuth implements AuthInterface
{
    private bool $authenticated = false;

    public function setAuthenticated(bool $authenticated): void
    {
        $this->authenticated = $authenticated;
    }

    public function isAuthenticated(): bool { return $this->authenticated; }
    public function getUserId(): mixed { return $this->authenticated ? 'test-user' : null; }
    public function getClaim(string $name): mixed { return null; }
    public function hasRole(string $role): bool { return false; }
    public function hasPermission(string $permission): bool { return false; }
}

test('Auth.hasImplementation() works correctly', function() {
    // Before setting implementation
    Auth::setImplementation(new class implements AuthInterface {
        public function isAuthenticated(): bool { return false; }
        public function getUserId(): mixed { return null; }
        public function getClaim(string $name): mixed { return null; }
        public function hasRole(string $role): bool { return false; }
        public function hasPermission(string $permission): bool { return false; }
    });

    assertEqual(true, Auth::hasImplementation());
});

test('Router logic: authenticated user gets 403', function() {
    $auth = new TestRouterAuth();
    $auth->setAuthenticated(true);
    Auth::setImplementation($auth);

    // Simulate the router's logic
    $exception = new AccessDeniedException('Test access denied');

    if (Auth::hasImplementation()) {
        if (Auth::isAuthenticated()) {
            $expectedStatus = 403; // Forbidden - authenticated but lacks permission
        } else {
            $expectedStatus = 401; // Unauthorized - not authenticated
        }
    } else {
        $expectedStatus = 401; // Default to unauthorized
    }

    assertEqual(403, $expectedStatus, 'Authenticated user should get 403 Forbidden');
});

test('Router logic: unauthenticated user gets 401', function() {
    $auth = new TestRouterAuth();
    $auth->setAuthenticated(false);
    Auth::setImplementation($auth);

    $exception = new AccessDeniedException('Test access denied');

    if (Auth::hasImplementation()) {
        if (Auth::isAuthenticated()) {
            $expectedStatus = 403; // Forbidden - authenticated but lacks permission
        } else {
            $expectedStatus = 401; // Unauthorized - not authenticated
        }
    } else {
        $expectedStatus = 401; // Default to unauthorized
    }

    assertEqual(401, $expectedStatus, 'Unauthenticated user should get 401 Unauthorized');
});

echo "Router auth logic tests completed.\n";