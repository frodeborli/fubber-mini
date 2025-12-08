<?php
/**
 * Test mini\args() global function
 *
 * Tests the setter/getter pattern for the ArgManager instance.
 * Each test runs in isolation via separate PHP processes.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use mini\Test;
use mini\CLI\ArgManager;

// Absolute path to vendor autoload for subprocess tests
define('VENDOR_AUTOLOAD', realpath(__DIR__ . '/../../vendor/autoload.php'));

$test = new class extends Test {

    // ========================================
    // Basic behavior
    // ========================================

    public function testArgsReturnsUnconfiguredByDefault(): void
    {
        $autoload = VENDOR_AUTOLOAD;
        $code = <<<PHP
<?php
require '$autoload';
\$_SERVER['argv'] = ['myapp', '-v'];

\$args = mini\\args();
echo \$args instanceof mini\\CLI\\ArgManager ? 'OK' : 'FAIL';
PHP;

        $result = $this->runPhpCode($code);
        $this->assertSame('OK', $result);
    }

    public function testArgsReturnsConfiguredInstance(): void
    {
        $autoload = VENDOR_AUTOLOAD;
        $code = <<<PHP
<?php
require '$autoload';
\$_SERVER['argv'] = ['myapp', '-v'];

\$configured = mini\\args()->withFlag('v', 'verbose');
\$returned = mini\\args(\$configured);

echo \$returned === \$configured ? 'SAME' : 'DIFFERENT';
PHP;

        $result = $this->runPhpCode($code);
        $this->assertSame('SAME', $result);
    }

    public function testArgsReturnsPreviouslyConfiguredInstance(): void
    {
        $autoload = VENDOR_AUTOLOAD;
        $code = <<<PHP
<?php
require '$autoload';
\$_SERVER['argv'] = ['myapp', '-v'];

\$configured = mini\\args()->withFlag('v', 'verbose');
mini\\args(\$configured);

// Second call without argument should return same instance
\$retrieved = mini\\args();
echo \$retrieved === \$configured ? 'SAME' : 'DIFFERENT';
PHP;

        $result = $this->runPhpCode($code);
        $this->assertSame('SAME', $result);
    }

    public function testArgsCanBeReconfigured(): void
    {
        $autoload = VENDOR_AUTOLOAD;
        $code = <<<PHP
<?php
require '$autoload';
\$_SERVER['argv'] = ['app1'];

\$first = mini\\args()->withFlag('a', null);
mini\\args(\$first);

\$_SERVER['argv'] = ['app2'];
\$second = mini\\args()->withFlag('b', null);
\$returned = mini\\args(\$second);

echo \$returned === \$second ? 'SECOND' : 'FIRST';
PHP;

        $result = $this->runPhpCode($code);
        $this->assertSame('SECOND', $result);
    }

    // ========================================
    // The Pattern: args(args()->with...())
    // ========================================

    public function testThePattern(): void
    {
        $autoload = VENDOR_AUTOLOAD;
        $code = <<<PHP
<?php
require '$autoload';
\$_SERVER['argv'] = ['myapp', '-v', '--config=test.cfg'];

// The pattern: args(args()->with...())
mini\\args(mini\\args()
    ->withFlag('v', 'verbose')
    ->withRequiredValue('c', 'config')
);

\$result = [
    'verbose' => mini\\args()->getFlag('verbose'),
    'config' => mini\\args()->getOption('config'),
    'unparsed' => mini\\args()->getUnparsedArgs(),
];
echo json_encode(\$result);
PHP;

        $result = json_decode($this->runPhpCode($code), true);
        $this->assertSame(1, $result['verbose']);
        $this->assertSame('test.cfg', $result['config']);
        $this->assertSame([], $result['unparsed']);
    }

    // ========================================
    // Subcommand workflow
    // ========================================

    public function testSubcommandWorkflow(): void
    {
        $autoload = VENDOR_AUTOLOAD;
        $code = <<<PHP
<?php
require '$autoload';
\$_SERVER['argv'] = ['myapp', '-v', 'run', '--fast', 'target'];

// Root command
mini\\args(mini\\args()
    ->withFlag('v', 'verbose')
    ->withSubcommand('run')
);

\$rootVerbose = mini\\args()->getFlag('verbose');
\$rootUnparsed = mini\\args()->getUnparsedArgs();

// Hand off to subcommand
\$sub = mini\\args()->nextCommand();
mini\\args(\$sub);

// Subcommand configures itself (same pattern)
mini\\args(mini\\args()
    ->withFlag(null, 'fast')
);

\$result = [
    'root_verbose' => \$rootVerbose,
    'root_unparsed' => \$rootUnparsed,
    'sub_cmd' => mini\\args()->getCommand(),
    'sub_fast' => mini\\args()->getFlag('fast'),
    'sub_unparsed' => mini\\args()->getUnparsedArgs(),
];
echo json_encode(\$result);
PHP;

        $result = json_decode($this->runPhpCode($code), true);
        $this->assertSame(1, $result['root_verbose']);
        $this->assertSame([], $result['root_unparsed']);
        $this->assertSame('run', $result['sub_cmd']);
        $this->assertSame(1, $result['sub_fast']);
        $this->assertSame(['target'], $result['sub_unparsed']);
    }

    /**
     * Helper to run PHP code in subprocess for isolation
     */
    private function runPhpCode(string $code): string
    {
        $tmpFile = sys_get_temp_dir() . '/mini-test-' . uniqid() . '.php';
        file_put_contents($tmpFile, $code);

        $output = shell_exec('php ' . escapeshellarg($tmpFile) . ' 2>&1');
        unlink($tmpFile);

        return trim($output);
    }
};

exit($test->run());
