<?php
/**
 * An uncaught exception under CLI must exit non-zero
 *
 * Mini's fallback exception handler used to render a 500 page and return, so
 * PHP exited 0. Under a web SAPI nobody notices; under CLI it means a cron
 * job, a CI step or a shell pipeline reports success for a script that
 * crashed. Found by the documentation harness in tests/Docs/Examples.php,
 * which could not tell a passing example from a fatal one.
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Test;

$test = new class extends Test {

    /** @return array{0: int, 1: string} exit status and combined output */
    private function runScript(string $body): array
    {
        $source = "<?php\nrequire " . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ";\n"
            . "mini\\bootstrap();\n" . $body;

        $tmp = tempnam(sys_get_temp_dir(), 'miniexit') . '.php';
        file_put_contents($tmp, $source);
        $out = [];
        exec('php ' . escapeshellarg($tmp) . ' 2>&1', $out, $status);
        unlink($tmp);

        return [$status, implode("\n", $out)];
    }

    public function testUncaughtExceptionExitsNonZero(): void
    {
        [$status, $output] = $this->runScript('throw new \RuntimeException("boom");');

        $this->assertNotSame(0, $status, "A crashed CLI script reported success. Output:\n$output");
        $this->assertStringContainsString('boom', $output, 'The exception message should reach the operator');
    }

    public function testUncaughtErrorExitsNonZero(): void
    {
        [$status, $output] = $this->runScript('$x = null; $x->nope();');

        $this->assertNotSame(0, $status, "A fatal Error reported success. Output:\n$output");
    }

    public function testFailingAssertionExitsNonZero(): void
    {
        $source = "<?php\nrequire " . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ";\n"
            . "mini\\bootstrap();\nassert(1 === 2);\n";
        $tmp = tempnam(sys_get_temp_dir(), 'miniexit') . '.php';
        file_put_contents($tmp, $source);
        $out = [];
        exec('php -d zend.assertions=1 -d assert.exception=1 ' . escapeshellarg($tmp) . ' 2>&1', $out, $status);
        unlink($tmp);

        $this->assertNotSame(0, $status, "A failed assert() reported success. Output:\n" . implode("\n", $out));
    }

    public function testSuccessfulScriptStillExitsZero(): void
    {
        [$status, $output] = $this->runScript('echo "fine";');

        $this->assertSame(0, $status, "A healthy script must still exit 0. Output:\n$output");
        $this->assertStringContainsString('fine', $output);
    }

    public function testApplicationExceptionHandlerIsNotOverridden(): void
    {
        // Mini only installs its fallback when the application has none; an
        // application handler keeps full control, including the exit code.
        $source = "<?php\nrequire " . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ";\n"
            . "set_exception_handler(function (\\Throwable \$e) { echo 'mine: ', \$e->getMessage(); exit(7); });\n"
            . "mini\\bootstrap();\nthrow new \\RuntimeException('boom');\n";
        $tmp = tempnam(sys_get_temp_dir(), 'miniexit') . '.php';
        file_put_contents($tmp, $source);
        $out = [];
        exec('php ' . escapeshellarg($tmp) . ' 2>&1', $out, $status);
        unlink($tmp);

        $this->assertSame(7, $status, 'The application handler should own the exit code');
        $this->assertStringContainsString('mine: boom', implode("\n", $out));
    }
};

exit($test->run());
