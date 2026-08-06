<?php
/**
 * Test Scoped service lifecycle and access control
 *
 * Tests: getRequestScope() behavior, scoped service access before/after bootstrap
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Mini;
use mini\Lifetime;
use mini\Test;

$test = new class extends Test {

    /** Exception from resolving a Scoped service while still in the Bootstrap phase */
    private ?\Throwable $scopedBeforeBootstrapError = null;

    /** Exception from getRequestScope() while still in the Bootstrap phase (not in fiber) */
    private ?\Throwable $requestScopeBeforeBootstrapError = null;

    protected function setUp(): void
    {
        $mini = Mini::$mini;

        // Register a scoped service before bootstrap
        $mini->addService('test.scoped', Lifetime::Scoped, fn() => new \stdClass());

        // These two must be exercised before bootstrap(); the harness bootstraps
        // after setUp() so the outcome is captured here and asserted below.
        try {
            $mini->get('test.scoped');
        } catch (\Throwable $e) {
            $this->scopedBeforeBootstrapError = $e;
        }

        try {
            $mini->getRequestScope();
        } catch (\Throwable $e) {
            $this->requestScopeBeforeBootstrapError = $e;
        }
    }

    public function testScopedServiceAccessThrowsBeforeBootstrap(): void
    {
        $this->assertNotNull(
            $this->scopedBeforeBootstrapError,
            'Resolving a Scoped service before bootstrap should throw'
        );
        $this->assertInstanceOf(\LogicException::class, $this->scopedBeforeBootstrapError);
    }

    public function testGetRequestScopeThrowsBeforeBootstrap(): void
    {
        $this->assertNotNull(
            $this->requestScopeBeforeBootstrapError,
            'getRequestScope() before bootstrap should throw'
        );
        $this->assertInstanceOf(\LogicException::class, $this->requestScopeBeforeBootstrapError);
    }

    public function testGetRequestScopeReturnsObjectAfterBootstrap(): void
    {
        $scope = Mini::$mini->getRequestScope();
        $this->assertNotNull($scope);
        $this->assertTrue(is_object($scope));
    }

    public function testScopedServiceAccessibleAfterBootstrap(): void
    {
        $this->assertNotNull(Mini::$mini->get('test.scoped'));
    }

    public function testGetRequestScopeReturnsSameObjectWithinRequest(): void
    {
        $scope1 = Mini::$mini->getRequestScope();
        $scope2 = Mini::$mini->getRequestScope();
        $this->assertTrue($scope1 === $scope2, 'Should return same scope object');
    }
};

exit($test->run());
