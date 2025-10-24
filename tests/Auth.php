<?php

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

function assertThrows(string $expectedClass, callable $callback, string $message = ''): void
{
    try {
        $callback();
        $prefix = $message ? "$message: " : '';
        throw new Exception("{$prefix}Expected exception $expectedClass was not thrown");
    } catch (Throwable $e) {
        if (!($e instanceof $expectedClass)) {
            $actualClass = get_class($e);
            $prefix = $message ? "$message: " : '';
            throw new Exception("{$prefix}Expected exception $expectedClass, got $actualClass: " . $e->getMessage());
        }
    }
}

// Mock AuthInterface implementation for testing
class TestAuth implements AuthInterface
{
    private bool $authenticated = false;
    private ?string $userId = null;
    private array $claims = [];
    private array $roles = [];
    private array $permissions = [];

    public function setAuthenticated(bool $authenticated, ?string $userId = null): void
    {
        $this->authenticated = $authenticated;
        $this->userId = $userId;
    }

    public function setClaim(string $name, mixed $value): void
    {
        $this->claims[$name] = $value;
    }

    public function setRoles(array $roles): void
    {
        $this->roles = $roles;
    }

    public function setPermissions(array $permissions): void
    {
        $this->permissions = $permissions;
    }

    public function isAuthenticated(): bool
    {
        return $this->authenticated;
    }

    public function getUserId(): mixed
    {
        return $this->authenticated ? $this->userId : null;
    }

    public function getClaim(string $name): mixed
    {
        return $this->authenticated ? ($this->claims[$name] ?? null) : null;
    }

    public function hasRole(string $role): bool
    {
        return $this->authenticated && in_array($role, $this->roles);
    }

    public function hasPermission(string $permission): bool
    {
        return $this->authenticated && in_array($permission, $this->permissions);
    }

}

// Setup test auth
$testAuth = new TestAuth();
Auth::setImplementation($testAuth);

test('hasImplementation returns correct state', function() use ($testAuth) {
    // Should have implementation after setting it
    assertEqual(true, Auth::hasImplementation());

    // Test what happens when we don't have implementation
    // (We can't easily unset it, but we can test the behavior)
});

test('Auth facade delegates to implementation correctly', function() use ($testAuth) {
    // Test unauthenticated state
    $testAuth->setAuthenticated(false);
    assertEqual(false, Auth::isAuthenticated());
    assertEqual(null, Auth::getUserId());
    assertEqual(null, Auth::getClaim('test'));
    assertEqual(false, Auth::hasRole('admin'));
    assertEqual(false, Auth::hasPermission('edit'));

    // Test authenticated state
    $testAuth->setAuthenticated(true, 'user123');
    $testAuth->setClaim('email', 'test@example.com');
    $testAuth->setRoles(['admin', 'editor']);
    $testAuth->setPermissions(['edit', 'delete']);

    assertEqual(true, Auth::isAuthenticated());
    assertEqual('user123', Auth::getUserId());
    assertEqual('test@example.com', Auth::getClaim('email'));
    assertEqual(true, Auth::hasRole('admin'));
    assertEqual(false, Auth::hasRole('viewer'));
    assertEqual(true, Auth::hasPermission('edit'));
    assertEqual(false, Auth::hasPermission('create'));
});

test('requireLogin throws AccessDeniedException when not authenticated', function() use ($testAuth) {
    $testAuth->setAuthenticated(false);

    assertThrows(AccessDeniedException::class, function() {
        Auth::requireLogin();
    });
});

test('requireRole throws AccessDeniedException when user lacks role', function() use ($testAuth) {
    $testAuth->setAuthenticated(true, 'user123');
    $testAuth->setRoles(['editor']);

    assertThrows(AccessDeniedException::class, function() {
        Auth::requireRole('admin');
    });
});

test('requireRole succeeds when user has role', function() use ($testAuth) {
    $testAuth->setAuthenticated(true, 'user123');
    $testAuth->setRoles(['admin', 'editor']);

    // Should not throw
    Auth::requireRole('admin');
    Auth::requireRole('editor');
});

test('requirePermission throws AccessDeniedException when user lacks permission', function() use ($testAuth) {
    $testAuth->setAuthenticated(true, 'user123');
    $testAuth->setPermissions(['read']);

    assertThrows(AccessDeniedException::class, function() {
        Auth::requirePermission('write');
    });
});

test('requirePermission succeeds when user has permission', function() use ($testAuth) {
    $testAuth->setAuthenticated(true, 'user123');
    $testAuth->setPermissions(['read', 'write']);

    // Should not throw
    Auth::requirePermission('read');
    Auth::requirePermission('write');
});

test('Auth facade gracefully handles missing implementation', function() {
    // Test with no implementation
    Auth::setImplementation(new class implements AuthInterface {
        public function isAuthenticated(): bool { throw new RuntimeException('No impl'); }
        public function getUserId(): mixed { throw new RuntimeException('No impl'); }
        public function getClaim(string $name): mixed { throw new RuntimeException('No impl'); }
        public function hasRole(string $role): bool { throw new RuntimeException('No impl'); }
        public function hasPermission(string $permission): bool { throw new RuntimeException('No impl'); }
    });

    // Should return safe defaults instead of throwing
    assertEqual(false, Auth::isAuthenticated());
    assertEqual(null, Auth::getUserId());
    assertEqual(null, Auth::getClaim('test'));
    assertEqual(false, Auth::hasRole('admin'));
    assertEqual(false, Auth::hasPermission('edit'));
});

echo "Auth tests completed.\n";