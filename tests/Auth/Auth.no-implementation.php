<?php
/**
 * Test Auth facade behavior when no AuthInterface implementation is registered
 *
 * This test must run in isolation (separate process) to test the "no implementation" path.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use mini\Test;
use mini\Mini;
use mini\Auth\Auth;
use mini\Auth\AuthInterface;
use mini\Exceptions\AccessDeniedException;

$test = new class extends Test {
    private Auth $auth;

    protected function setUp(): void
    {
        // Set AuthInterface to null BEFORE bootstrap to simulate "not configured"
        Mini::$mini->set(AuthInterface::class, null);
        \mini\bootstrap();
        $this->auth = new Auth();
    }

    public function testIsAuthenticatedReturnsFalseWhenNoImplementation(): void
    {
        $this->assertFalse($this->auth->isAuthenticated());
    }

    public function testGetUserIdReturnsNullWhenNoImplementation(): void
    {
        $this->assertNull($this->auth->getUserId());
    }

    public function testGetClaimReturnsNullWhenNoImplementation(): void
    {
        $this->assertNull($this->auth->getClaim('anything'));
    }

    public function testHasRoleReturnsFalseWhenNoImplementation(): void
    {
        $this->assertFalse($this->auth->hasRole('admin'));
    }

    public function testHasPermissionReturnsFalseWhenNoImplementation(): void
    {
        $this->assertFalse($this->auth->hasPermission('edit'));
    }

    public function testRequireLoginThrowsWhenNoImplementation(): void
    {
        $this->assertThrows(
            fn() => $this->auth->requireLogin(),
            AccessDeniedException::class
        );
    }

    public function testGetImplementationThrowsWhenNoImplementation(): void
    {
        $this->assertThrows(
            fn() => $this->auth->getImplementation(),
            \RuntimeException::class
        );
    }
};

exit($test->run());
