<?php

$autoloader = realpath(__DIR__ . '/../vendor/autoload.php')
    ?: realpath(__DIR__ . '/../../../vendor/autoload.php')
    ?: realpath(__DIR__ . '/../../vendor/autoload.php');

if (!$autoloader) {
    throw new Exception("Could not find autoloader");
}

require_once $autoloader;

use function mini\{setupAuth, is_logged_in, require_role};
use mini\AuthInterface;

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

// Mock AuthInterface implementation for testing
class TestGlobalAuth implements AuthInterface
{
    private bool $authenticated = false;
    private array $roles = [];

    public function setAuthenticated(bool $authenticated): void
    {
        $this->authenticated = $authenticated;
    }

    public function setRoles(array $roles): void
    {
        $this->roles = $roles;
    }

    public function isAuthenticated(): bool
    {
        return $this->authenticated;
    }

    public function getUserId(): mixed
    {
        return $this->authenticated ? 'test-user' : null;
    }

    public function getClaim(string $name): mixed
    {
        return null;
    }

    public function hasRole(string $role): bool
    {
        return $this->authenticated && in_array($role, $this->roles);
    }

    public function hasPermission(string $permission): bool
    {
        return false;
    }

}

// Setup test auth
$testAuth = new TestGlobalAuth();
setupAuth($testAuth);

test('is_logged_in() function works correctly', function() use ($testAuth) {
    $testAuth->setAuthenticated(false);
    assertEqual(false, is_logged_in());

    $testAuth->setAuthenticated(true);
    assertEqual(true, is_logged_in());
});

test('require_role() function delegates correctly', function() use ($testAuth) {
    $testAuth->setAuthenticated(true);
    $testAuth->setRoles(['admin']);

    // Should not throw
    require_role('admin');

    try {
        require_role('superuser');
        throw new Exception('Expected AccessDeniedException was not thrown');
    } catch (mini\Http\AccessDeniedException $e) {
        // Expected
    }
});

echo "Auth functions tests completed.\n";