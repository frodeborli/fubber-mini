<?php
/**
 * Test Mini configuration loading
 *
 * Tests: loadConfig(), loadServiceConfig() methods
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Mini;
use mini\Test;

$test = new class extends Test {

    public function testLoadConfigReturnsDefaultForMissingFile(): void
    {
        $this->assertSame('default-value', Mini::$mini->loadConfig('nonexistent-file.php', 'default-value'));
    }

    public function testLoadConfigReturnsNullDefaultForMissingFile(): void
    {
        $this->assertNull(Mini::$mini->loadConfig('nonexistent-file.php', null));
    }

    public function testLoadConfigThrowsForMissingFileWithoutDefault(): void
    {
        $this->assertThrows(
            fn() => Mini::$mini->loadConfig('nonexistent-file.php'),
            \Exception::class
        );
    }

    public function testLoadServiceConfigReturnsDefaultForMissingServiceConfig(): void
    {
        $this->assertSame('default', Mini::$mini->loadServiceConfig('NonExistent\\Service\\Class', 'default'));
    }

    public function testLoadServiceConfigThrowsForMissingConfigWithoutDefault(): void
    {
        $this->assertThrows(
            fn() => Mini::$mini->loadServiceConfig('NonExistent\\Service\\Class'),
            \Exception::class
        );
    }

    public function testPathsRegistryIsAccessible(): void
    {
        $this->assertNotNull(Mini::$mini->paths);
        $this->assertNotNull(Mini::$mini->paths->config);
    }
};

exit($test->run());
