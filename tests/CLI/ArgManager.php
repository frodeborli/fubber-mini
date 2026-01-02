<?php
/**
 * Test ArgManager CLI argument parser
 *
 * Tests mock $_SERVER['argv'] to simulate command-line input.
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Test;
use mini\CLI\ArgManager;

$test = new class extends Test {

    /**
     * Helper to set up argv for testing
     */
    private function argv(array $args): void
    {
        $_SERVER['argv'] = $args;
    }

    /**
     * Create fresh ArgManager (always starts at index 0)
     */
    private function args(): ArgManager
    {
        return new ArgManager(0);
    }

    // ========================================
    // getCommand()
    // ========================================

    public function testGetCommandReturnsFirstArgument(): void
    {
        $this->argv(['myapp', 'subcommand', 'arg1']);
        $args = $this->args();
        $this->assertSame('myapp', $args->getCommand());
    }

    // ========================================
    // Short flags (no value)
    // ========================================

    public function testShortFlagNotPresent(): void
    {
        $this->argv(['myapp']);
        $args = $this->args()->withFlag('v', 'verbose');
        $this->assertSame(0, $args->getFlag('v'));
        $this->assertSame(0, $args->getFlag('verbose'));
    }

    public function testShortFlagPresent(): void
    {
        $this->argv(['myapp', '-v']);
        $args = $this->args()->withFlag('v', 'verbose');
        $this->assertSame(1, $args->getFlag('v'));
        $this->assertSame(1, $args->getFlag('verbose'));
    }

    public function testShortFlagRepeated(): void
    {
        $this->argv(['myapp', '-vvv']);
        $args = $this->args()->withFlag('v', 'verbose');
        $this->assertSame(3, $args->getFlag('verbose'));
    }

    public function testShortFlagRepeatedSeparate(): void
    {
        $this->argv(['myapp', '-v', '-v', '-v']);
        $args = $this->args()->withFlag('v', 'verbose');
        $this->assertSame(3, $args->getFlag('verbose'));
    }

    public function testMultipleShortFlagsCombined(): void
    {
        $this->argv(['myapp', '-abc']);
        $args = $this->args()
            ->withFlag('a', null)
            ->withFlag('b', null)
            ->withFlag('c', null);
        $this->assertSame(1, $args->getFlag('a'));
        $this->assertSame(1, $args->getFlag('b'));
        $this->assertSame(1, $args->getFlag('c'));
    }

    // ========================================
    // Long flags (no value)
    // ========================================

    public function testLongFlagNotPresent(): void
    {
        $this->argv(['myapp']);
        $args = $this->args()->withFlag(null, 'verbose');
        $this->assertSame(0, $args->getFlag('verbose'));
    }

    public function testLongFlagPresent(): void
    {
        $this->argv(['myapp', '--verbose']);
        $args = $this->args()->withFlag(null, 'verbose');
        $this->assertSame(1, $args->getFlag('verbose'));
    }

    public function testLongFlagRepeated(): void
    {
        $this->argv(['myapp', '--verbose', '--verbose']);
        $args = $this->args()->withFlag(null, 'verbose');
        $this->assertSame(2, $args->getFlag('verbose'));
    }

    // ========================================
    // Short options with required value
    // ========================================

    public function testShortOptionRequiredValueSeparate(): void
    {
        $this->argv(['myapp', '-i', 'file.txt']);
        $args = $this->args()->withRequiredValue('i', 'input');
        $this->assertSame('file.txt', $args->getOption('input'));
        $this->assertSame('file.txt', $args->getOption('i'));
    }

    public function testShortOptionRequiredValueAttached(): void
    {
        $this->argv(['myapp', '-ifile.txt']);
        $args = $this->args()->withRequiredValue('i', 'input');
        $this->assertSame('file.txt', $args->getOption('input'));
    }

    public function testShortOptionRequiredValueMultiple(): void
    {
        $this->argv(['myapp', '-e', 'error', '-e', 'warning']);
        $args = $this->args()->withRequiredValue('e', 'exclude');
        $result = $args->getOption('exclude');
        $this->assertSame(['error', 'warning'], $result);
    }

    // ========================================
    // Long options with required value
    // ========================================

    public function testLongOptionRequiredValueEquals(): void
    {
        $this->argv(['myapp', '--input=file.txt']);
        $args = $this->args()->withRequiredValue('i', 'input');
        $this->assertSame('file.txt', $args->getOption('input'));
    }

    public function testLongOptionRequiredValueSeparate(): void
    {
        $this->argv(['myapp', '--input', 'file.txt']);
        $args = $this->args()->withRequiredValue('i', 'input');
        $this->assertSame('file.txt', $args->getOption('input'));
    }

    // ========================================
    // Optional value options
    // ========================================

    public function testOptionalValueNotPresent(): void
    {
        $this->argv(['myapp']);
        $args = $this->args()->withOptionalValue('o', 'output');
        $this->assertNull($args->getOption('output'));
    }

    public function testOptionalValuePresentWithoutValue(): void
    {
        $this->argv(['myapp', '-o']);
        $args = $this->args()->withOptionalValue('o', 'output');
        $this->assertSame(false, $args->getOption('output'));
    }

    public function testOptionalValuePresentWithValue(): void
    {
        $this->argv(['myapp', '-o', 'file.txt']);
        $args = $this->args()->withOptionalValue('o', 'output');
        $this->assertSame('file.txt', $args->getOption('output'));
    }

    public function testOptionalValueLongWithEquals(): void
    {
        $this->argv(['myapp', '--output=file.txt']);
        $args = $this->args()->withOptionalValue('o', 'output');
        $this->assertSame('file.txt', $args->getOption('output'));
    }

    public function testOptionalValueWithDefault(): void
    {
        $args = $this->args()->withOptionalValue('l', 'log', '/var/log/app.log');

        // Not present - returns null
        $this->argv(['myapp']);
        $this->assertNull($args->getOption('log'));

        // Present without value - returns default
        $this->argv(['myapp', '--log']);
        $args2 = $this->args()->withOptionalValue('l', 'log', '/var/log/app.log');
        $this->assertSame('/var/log/app.log', $args2->getOption('log'));

        // Present with value - returns provided value
        $this->argv(['myapp', '--log=/custom/path.log']);
        $args3 = $this->args()->withOptionalValue('l', 'log', '/var/log/app.log');
        $this->assertSame('/custom/path.log', $args3->getOption('log'));
    }

    public function testOptionalValueWithoutDefaultReturnsFalse(): void
    {
        // Without default, present-without-value still returns false
        $this->argv(['myapp', '--log']);
        $args = $this->args()->withOptionalValue('l', 'log');
        $this->assertSame(false, $args->getOption('log'));
    }

    public function testHasOption(): void
    {
        $this->argv(['myapp', '--log']);
        $args = $this->args()->withOptionalValue('l', 'log');
        $this->assertTrue($args->hasOption('log'));
        $this->assertTrue($args->hasOption('l'));

        $this->argv(['myapp']);
        $args = $this->args()->withOptionalValue('l', 'log');
        $this->assertFalse($args->hasOption('log'));
    }

    // ========================================
    // Unparsed arguments
    // ========================================

    public function testUnparsedArgsEmpty(): void
    {
        $this->argv(['myapp', '-v']);
        $args = $this->args()->withFlag('v', 'verbose');
        $this->assertSame([], $args->getUnparsedArgs());
    }

    public function testUnknownOptionGoesToUnparsed(): void
    {
        $this->argv(['myapp', '-v', '--unknown', '-x']);
        $args = $this->args()->withFlag('v', 'verbose');
        $this->assertSame(['--unknown', '-x'], $args->getUnparsedArgs());
    }

    public function testUnknownPositionalGoesToUnparsed(): void
    {
        $this->argv(['myapp', '-v', 'somefile.txt']);
        $args = $this->args()->withFlag('v', 'verbose');
        $this->assertSame(['somefile.txt'], $args->getUnparsedArgs());
    }

    // ========================================
    // Double-dash (--) stops option parsing
    // ========================================

    public function testDoubleDashStopsOptionParsing(): void
    {
        $this->argv(['myapp', '-v', '--', '--not-an-option', 'file.txt']);
        $args = $this->args()->withFlag('v', 'verbose');
        $this->assertSame(1, $args->getFlag('v'));
        $this->assertSame(['--not-an-option', 'file.txt'], $args->getUnparsedArgs());
    }

    // ========================================
    // Subcommands with withSubcommand()
    // ========================================

    public function testDeclaredSubcommandMatchesAndReturnsNextCommand(): void
    {
        $this->argv(['myapp', '-v', 'run', '--fast']);
        $args = $this->args()
            ->withFlag('v', 'verbose')
            ->withSubcommand('run', 'build');

        $this->assertSame(1, $args->getFlag('v'));
        $this->assertSame([], $args->getUnparsedArgs());

        $sub = $args->nextCommand();
        $this->assertNotNull($sub);
        $this->assertSame('run', $sub->getCommand());
    }

    public function testUndeclaredSubcommandGoesToUnparsed(): void
    {
        $this->argv(['myapp', '-v', 'unknown-cmd']);
        $args = $this->args()
            ->withFlag('v', 'verbose')
            ->withSubcommand('run', 'build');

        $this->assertSame(['unknown-cmd'], $args->getUnparsedArgs());
        $this->assertNull($args->nextCommand());
    }

    public function testNoSubcommandReturnsNull(): void
    {
        $this->argv(['myapp', '-v']);
        $args = $this->args()
            ->withFlag('v', 'verbose')
            ->withSubcommand('run', 'build');

        $this->assertSame([], $args->getUnparsedArgs());
        $this->assertNull($args->nextCommand());
    }

    public function testSubcommandGetsItsOwnArgs(): void
    {
        $this->argv(['myapp', '-v', 'run', '--fast', 'target']);
        $args = $this->args()
            ->withFlag('v', 'verbose')
            ->withSubcommand('run');

        $sub = $args->nextCommand();
        $this->assertNotNull($sub);

        $sub = $sub->withFlag(null, 'fast');
        $this->assertSame(1, $sub->getFlag('fast'));
        $this->assertSame(['target'], $sub->getUnparsedArgs());
    }

    public function testSimpleCommandDetectsUnexpectedArgs(): void
    {
        // Pattern: simple command with no subcommands
        $this->argv(['myapp', '-v', 'unexpected']);
        $args = $this->args()->withFlag('v', 'verbose');

        // Developer can check for unexpected input
        $this->assertTrue(count($args->getUnparsedArgs()) > 0);
        $this->assertSame(['unexpected'], $args->getUnparsedArgs());
    }

    public function testComplexCommandWithSubcommands(): void
    {
        // Pattern: command with declared subcommands
        $this->argv(['myapp', '-v', 'run', '--fast']);
        $args = $this->args()
            ->withFlag('v', 'verbose')
            ->withSubcommand('run', 'build', 'test');

        // No unexpected args at root level
        $this->assertSame([], $args->getUnparsedArgs());

        // Subcommand is accessible
        $sub = $args->nextCommand();
        $this->assertSame('run', $sub->getCommand());
    }

    // ========================================
    // Legacy subcommand tests (without withSubcommand)
    // ========================================

    public function testNextCommandWithoutDeclaredSubcommands(): void
    {
        // Without withSubcommand(), positional args go to unparsed
        // and nextCommand() returns null
        $this->argv(['git', 'commit', '-m', 'message']);
        $root = $this->args();

        // 'commit' goes to unparsed since no subcommands declared
        $this->assertSame(['commit', '-m', 'message'], $root->getUnparsedArgs());
        $this->assertNull($root->nextCommand());
    }

    public function testThreeLevelSubcommands(): void
    {
        // myapp config set key value
        $this->argv(['myapp', 'config', 'set', 'key', 'value']);

        $root = $this->args()->withSubcommand('config');
        $this->assertSame([], $root->getUnparsedArgs());

        $config = $root->nextCommand();
        $this->assertSame('config', $config->getCommand());

        $config = $config->withSubcommand('set', 'get');
        $set = $config->nextCommand();
        $this->assertSame('set', $set->getCommand());

        // 'key' and 'value' are unparsed at 'set' level
        $this->assertSame(['key', 'value'], $set->getUnparsedArgs());
    }

    // ========================================
    // getRemainingArgs()
    // ========================================

    public function testGetRemainingArgsReturnsUnparsedArgs(): void
    {
        $this->argv(['myapp', 'proxy', '--flag', 'arg1', 'arg2']);
        $root = $this->args()->withSubcommand('proxy');
        $proxy = $root->nextCommand();
        $this->assertNotNull($proxy);
        $this->assertSame('proxy', $proxy->getCommand());
        // getRemainingArgs returns everything after the command itself
        $remaining = $proxy->getRemainingArgs();
        $this->assertSame(['--flag', 'arg1', 'arg2'], $remaining);
    }

    public function testGetRemainingArgsStripsLeadingDoubleDash(): void
    {
        // User types: myapp proxy -- --external-flag file.txt
        // The -- tells our parser to stop, but shouldn't be forwarded
        $this->argv(['myapp', 'proxy', '--', '--external-flag', 'file.txt']);
        $root = $this->args()->withSubcommand('proxy');
        $proxy = $root->nextCommand();
        $remaining = $proxy->getRemainingArgs();
        // -- is stripped, external command gets clean args
        $this->assertSame(['--external-flag', 'file.txt'], $remaining);
    }

    // ========================================
    // Fluent API validation
    // ========================================

    public function testCannotDeclareShortOptionTwice(): void
    {
        $this->assertThrows(
            fn() => $this->args()->withFlag('v', null)->withFlag('v', null),
            \InvalidArgumentException::class
        );
    }

    public function testCannotDeclareLongOptionTwice(): void
    {
        $this->assertThrows(
            fn() => $this->args()->withFlag(null, 'verbose')->withFlag(null, 'verbose'),
            \InvalidArgumentException::class
        );
    }

    public function testMustProvideAtLeastOneOptionName(): void
    {
        $this->assertThrows(
            fn() => $this->args()->withFlag(null, null),
            \InvalidArgumentException::class
        );
    }

    public function testGetOptionThrowsForUndeclaredOption(): void
    {
        $this->argv(['myapp']);
        $args = $this->args();
        $this->assertThrows(
            fn() => $args->getOption('undeclared'),
            \RuntimeException::class
        );
    }

    public function testGetFlagThrowsForUndeclaredOption(): void
    {
        $this->argv(['myapp']);
        $args = $this->args();
        $this->assertThrows(
            fn() => $args->getFlag('undeclared'),
            \RuntimeException::class
        );
    }

    // ========================================
    // Immutability
    // ========================================

    public function testWithFlagReturnsNewInstance(): void
    {
        $args1 = $this->args();
        $args2 = $args1->withFlag('v', 'verbose');
        $this->assertFalse($args1 === $args2);
    }

    public function testWithSubcommandReturnsNewInstance(): void
    {
        $args1 = $this->args();
        $args2 = $args1->withSubcommand('run');
        $this->assertFalse($args1 === $args2);
    }

    // ========================================
    // Edge cases
    // ========================================

    public function testEmptyArgv(): void
    {
        $this->argv([]);
        $args = $this->args();
        $this->assertNull($args->getCommand());
    }

    public function testOptionValueLooksLikeOption(): void
    {
        // Value that starts with dash should NOT be consumed
        $this->argv(['myapp', '--input', '-not-a-flag']);
        $args = $this->args()->withRequiredValue('i', 'input');
        // The implementation stops at - prefix, throws because no value
        $this->assertThrows(
            fn() => $args->getOption('input'),
            \RuntimeException::class
        );
    }

    public function testLongOptionWithEmptyValue(): void
    {
        $this->argv(['myapp', '--input=']);
        $args = $this->args()->withRequiredValue('i', 'input');
        $this->assertSame('', $args->getOption('input'));
    }

    public function testUnifiedShortLongAccess(): void
    {
        $this->argv(['myapp', '-v', '--config=test.cfg']);
        $args = $this->args()
            ->withFlag('v', 'verbose')
            ->withRequiredValue('c', 'config');

        // Can query by either name
        $this->assertSame(1, $args->getFlag('v'));
        $this->assertSame(1, $args->getFlag('verbose'));
        $this->assertSame('test.cfg', $args->getOption('c'));
        $this->assertSame('test.cfg', $args->getOption('config'));
    }
};

exit($test->run());
