<?php
/**
 * Test Auth facade and AuthInterface delegation
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Test;
use mini\Mini;
use mini\Auth\Auth;
use mini\Auth\AuthInterface;
use mini\Exceptions\AccessDeniedException;

// Mock implementation for testing
class MockAuth implements AuthInterface
{
    public bool $authenticated = false;
    public mixed $userId = null;
    public array $claims = [];
    public array $roles = [];
    public array $permissions = [];

    public function isAuthenticated(): bool { return $this->authenticated; }
    public function getUserId(): mixed { return $this->authenticated ? $this->userId : null; }
    public function getClaim(string $name): mixed { return $this->claims[$name] ?? null; }
    public function hasRole(string $role): bool { return in_array($role, $this->roles); }
    public function hasPermission(string $permission): bool { return in_array($permission, $this->permissions); }
}

$test = new class extends Test {
    private MockAuth $mock;
    private Auth $auth;

    protected function setUp(): void
    {
        $this->mock = new MockAuth();
        // Set mock BEFORE bootstrap, so it's used instead of the default factory
        Mini::$mini->set(AuthInterface::class, $this->mock);
        \mini\bootstrap();
        $this->auth = new Auth();
    }

    public function testUnauthenticatedStateReturnsDefaults(): void
    {
        $this->assertFalse($this->auth->isAuthenticated());
        $this->assertNull($this->auth->getUserId());
        $this->assertFalse($this->auth->hasRole('admin'));
        $this->assertFalse($this->auth->hasPermission('edit'));
    }

    public function testAuthenticatedStateDelegatesCorrectly(): void
    {
        $this->mock->authenticated = true;
        $this->mock->userId = 42;
        $this->mock->claims = ['email' => 'test@example.com'];
        $this->mock->roles = ['admin', 'editor'];
        $this->mock->permissions = ['edit', 'delete'];

        $this->assertTrue($this->auth->isAuthenticated());
        $this->assertSame(42, $this->auth->getUserId());
        $this->assertSame('test@example.com', $this->auth->getClaim('email'));
        $this->assertNull($this->auth->getClaim('nonexistent'));
        $this->assertTrue($this->auth->hasRole('admin'));
        $this->assertFalse($this->auth->hasRole('superuser'));
        $this->assertTrue($this->auth->hasPermission('edit'));
        $this->assertFalse($this->auth->hasPermission('create'));
    }

    public function testRequireLoginPassesWhenAuthenticated(): void
    {
        $this->mock->authenticated = true;
        $this->auth->requireLogin(); // Should not throw
    }

    public function testRequireLoginThrowsWhenNotAuthenticated(): void
    {
        $this->mock->authenticated = false;
        $this->assertThrows(
            fn() => $this->auth->requireLogin(),
            AccessDeniedException::class
        );
    }

    public function testRequireRolePassesWhenUserHasRole(): void
    {
        $this->mock->authenticated = true;
        $this->mock->roles = ['admin', 'editor'];
        $this->auth->requireRole('admin'); // Should not throw
    }

    public function testRequireRoleThrowsWhenUserLacksRole(): void
    {
        $this->mock->authenticated = true;
        $this->mock->roles = ['editor'];
        $this->assertThrows(
            fn() => $this->auth->requireRole('superuser'),
            AccessDeniedException::class
        );
    }

    public function testRequirePermissionPassesWhenUserHasPermission(): void
    {
        $this->mock->authenticated = true;
        $this->mock->permissions = ['edit', 'delete'];
        $this->auth->requirePermission('edit'); // Should not throw
    }

    public function testRequirePermissionThrowsWhenUserLacksPermission(): void
    {
        $this->mock->authenticated = true;
        $this->mock->permissions = ['edit'];
        $this->assertThrows(
            fn() => $this->auth->requirePermission('create'),
            AccessDeniedException::class
        );
    }

    public function testRequireMethodsSupportFluentChaining(): void
    {
        $this->mock->authenticated = true;
        $this->mock->roles = ['admin'];
        $this->mock->permissions = ['edit'];

        $result = $this->auth->requireLogin()->requireRole('admin')->requirePermission('edit');
        $this->assertInstanceOf(Auth::class, $result);
    }

    public function testGetImplementationReturnsRegisteredInstance(): void
    {
        $impl = $this->auth->getImplementation();
        $this->assertSame($this->mock, $impl);
    }
};

exit($test->run());
