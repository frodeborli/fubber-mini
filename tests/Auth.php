<?php

$autoloader = realpath(__DIR__ . '/../vendor/autoload.php')
    ?: realpath(__DIR__ . '/../../../vendor/autoload.php')
    ?: realpath(__DIR__ . '/../../vendor/autoload.php');

if (!$autoloader) {
    throw new Exception("Could not find autoloader");
}

require_once $autoloader;

use mini\Auth\AuthInterface;
use mini\Http\AccessDeniedException;
use function mini\{setupAuth, auth, is_logged_in, require_login, require_role};

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

// Setup test auth with factory closure (must be BEFORE bootstrap)
// The factory captures $testAuth so tests can manipulate state
$testAuth = new TestAuth();
setupAuth(fn() => $testAuth);

// Enter request context (transitions to Request phase)
mini\bootstrap();

test('auth() returns correct instance', function() use ($testAuth) {
    // Should have auth instance after setting it
    $authInstance = auth();
    assertEqual(true, $authInstance !== null, 'auth() returns instance');
    assertEqual(true, $authInstance === $testAuth, 'auth() returns registered instance');
});

test('Auth delegates to implementation correctly', function() use ($testAuth) {
    // Test unauthenticated state
    $testAuth->setAuthenticated(false);
    $authInstance = auth();
    assertEqual(false, $authInstance->isAuthenticated());
    assertEqual(null, $authInstance->getUserId());
    assertEqual(null, $authInstance->getClaim('test'));
    assertEqual(false, $authInstance->hasRole('admin'));
    assertEqual(false, $authInstance->hasPermission('edit'));

    // Test authenticated state
    $testAuth->setAuthenticated(true, 'user123');
    $testAuth->setClaim('email', 'test@example.com');
    $testAuth->setRoles(['admin', 'editor']);
    $testAuth->setPermissions(['edit', 'delete']);

    assertEqual(true, $authInstance->isAuthenticated());
    assertEqual('user123', $authInstance->getUserId());
    assertEqual('test@example.com', $authInstance->getClaim('email'));
    assertEqual(true, $authInstance->hasRole('admin'));
    assertEqual(false, $authInstance->hasRole('viewer'));
    assertEqual(true, $authInstance->hasPermission('edit'));
    assertEqual(false, $authInstance->hasPermission('create'));
});

test('requireLogin throws AccessDeniedException when not authenticated', function() use ($testAuth) {
    $testAuth->setAuthenticated(false);

    assertThrows(AccessDeniedException::class, function() {
        require_login();
    });
});

test('requireRole throws AccessDeniedException when user lacks role', function() use ($testAuth) {
    $testAuth->setAuthenticated(true, 'user123');
    $testAuth->setRoles(['editor']);

    assertThrows(AccessDeniedException::class, function() {
        require_role('admin');
    });
});

test('requireRole succeeds when user has role', function() use ($testAuth) {
    $testAuth->setAuthenticated(true, 'user123');
    $testAuth->setRoles(['admin', 'editor']);

    // Should not throw
    require_role('admin');
    require_role('editor');
});

test('Helper function require_permission works', function() use ($testAuth) {
    $testAuth->setAuthenticated(true, 'user123');
    $testAuth->setPermissions(['read', 'write']);

    // Test via direct auth() access since there's no require_permission() helper yet
    $authInstance = auth();
    assertEqual(true, $authInstance->hasPermission('read'));
    assertEqual(true, $authInstance->hasPermission('write'));
    assertEqual(false, $authInstance->hasPermission('delete'));
});

test('is_logged_in helper function works correctly', function() use ($testAuth) {
    $testAuth->setAuthenticated(false);
    assertEqual(false, is_logged_in(), 'Not logged in when unauthenticated');

    $testAuth->setAuthenticated(true, 'user123');
    assertEqual(true, is_logged_in(), 'Logged in when authenticated');
});

echo "Auth tests completed.\n";