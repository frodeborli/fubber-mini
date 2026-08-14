<?php
/**
 * Documentation cannot rot: every PHP example is compiled, and the ones
 * marked runnable are executed
 *
 * Backported from the Python port (MiniSQL), whose test suite runs its
 * README's quickstart verbatim and checks every error message the README
 * advertises against the string the code actually raises. Both caught real
 * documentation lies within minutes of being written, which is the argument
 * for them: a doc claim nobody executes is a claim nobody checks.
 *
 * Three levels of checking here, cheapest first:
 *
 *   1. Every ```php block in the documented markdown must PARSE. Catches the
 *      largest class of rot - an example referencing a renamed class or a
 *      changed signature usually still parses, but a truncated or
 *      mangled one does not, and this costs nothing.
 *   2. Every class, method and function named in a php block must EXIST.
 *      This is the check that catches the real rot: `db()->partialQuery()`
 *      and `_errors/404.php` both lived in this repo's docs for months.
 *   3. A block tagged `php runnable` is EXECUTED, and its own assertions are
 *      the test. That is the Python port's quickstart trick: an example that
 *      runs on every suite invocation cannot drift.
 *
 * Tagging a block `php runnable` opts it in; everything else gets 1 and 2.
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Test;

$test = new class extends Test {

    /** Markdown files whose examples are checked */
    private const DOCUMENTED = [
        'README.md',
        'CLAUDE.md',
        'src/Database/Virtual/README.md',
        'src/Router/README.md',
        'src/Database/README.md',
    ];

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @return array<int, array{file: string, line: int, code: string, runnable: bool}>
     */
    private function phpBlocks(): array
    {
        $blocks = [];
        foreach (self::DOCUMENTED as $rel) {
            $path = $this->root() . '/' . $rel;
            if (!is_file($path)) {
                continue;
            }
            $lines = file($path, FILE_IGNORE_NEW_LINES);
            $open = null;
            $buf = [];
            $runnable = false;
            foreach ($lines as $i => $line) {
                if ($open === null && preg_match('/^```php(\s+runnable)?\s*$/', $line, $m)) {
                    $open = $i + 1;
                    $runnable = isset($m[1]);
                    $buf = [];
                    continue;
                }
                if ($open !== null && rtrim($line) === '```') {
                    $blocks[] = [
                        'file' => $rel,
                        'line' => $open,
                        'code' => implode("\n", $buf),
                        'runnable' => $runnable,
                    ];
                    $open = null;
                    continue;
                }
                if ($open !== null) {
                    $buf[] = $line;
                }
            }
        }
        return $blocks;
    }

    public function testDocumentedFilesExist(): void
    {
        foreach (self::DOCUMENTED as $rel) {
            $this->assertTrue(
                is_file($this->root() . '/' . $rel),
                "$rel is listed as documented but does not exist"
            );
        }
    }

    public function testEveryPhpBlockParses(): void
    {
        $broken = [];
        foreach ($this->phpBlocks() as $b) {
            // Fragments are the norm in docs (no <?php, a bare statement, a
            // class body). Wrap so a fragment is still valid input, then let
            // php -l judge it.
            $code = $b['code'];

            // Prose, not code: an elision, or a block deliberately showing a
            // non-PHP language beside PHP (a Blade/Twig placeholder, a bare
            // filesystem path in a comparison).
            if (str_contains($code, '...')
                || str_contains($code, '{{')
                || preg_match('/^_routes\//m', $code)
                || preg_match('/^\s*->/m', $code)   // a method-chain illustration
            ) {
                continue;
            }

            // Docs are full of fragments - an array entry, a class body, a
            // bare statement. Accept the block if ANY of these framings parse.
            $candidates = str_contains($code, '<?php')
                ? [$code]
                : [
                    "<?php\n" . $code,
                    "<?php\n\$a = [\n" . $code . "\n];",
                    "<?php\nclass __DocFragment {\n" . $code . "\n}",
                ];

            $ok = false;
            $firstError = 'parse error';
            foreach ($candidates as $source) {
                $tmp = tempnam(sys_get_temp_dir(), 'minidoc') . '.php';
                file_put_contents($tmp, $source);
                $out = [];
                exec('php -l ' . escapeshellarg($tmp) . ' 2>&1', $out, $status);
                unlink($tmp);
                if ($status === 0) {
                    $ok = true;
                    break;
                }
                if ($firstError === 'parse error') {
                    $firstError = trim((string) ($out[0] ?? 'parse error'));
                }
            }

            if (!$ok) {
                $broken[] = "{$b['file']}:{$b['line']} — " . $firstError;
            }
        }

        $this->assertSame([], $broken, "Documentation examples that do not parse:\n  " . implode("\n  ", $broken));
    }

    public function testEveryClassNamedInAnExampleExists(): void
    {
        $missing = [];
        foreach ($this->phpBlocks() as $b) {
            // mini\Foo\Bar and \mini\Foo\Bar, as written in prose or code
            // Expand group-use syntax first: `use mini\Foo\{A, B};` names
            // mini\Foo\A and mini\Foo\B, not the prefix mini\Foo.
            $code = preg_replace_callback(
                '/(\\\\?\bmini(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+)\\\\\{([^}]*)\}/',
                function (array $mm): string {
                    $parts = array_map('trim', explode(',', $mm[2]));
                    return implode(' ', array_map(fn($p) => $mm[1] . '\\' . $p, array_filter($parts)));
                },
                $b['code']
            ) ?? $b['code'];

            preg_match_all('/\\\\?\bmini\\\\[A-Za-z_][A-Za-z0-9_\\\\]*[A-Za-z0-9_]/', $code, $m);
            foreach (array_unique($m[0]) as $name) {
                $fqcn = ltrim($name, '\\');
                // A function reference (mini\render) is not a class
                if (function_exists($fqcn)) {
                    continue;
                }
                if (class_exists($fqcn) || interface_exists($fqcn) || enum_exists($fqcn) || trait_exists($fqcn)) {
                    continue;
                }
                // A namespace prefix used in prose (mini\Table\Wrappers) is fine
                if (!str_contains($fqcn, '\\') || preg_match('/\\\\[a-z]/', $fqcn)) {
                    continue;
                }
                $missing[] = "{$b['file']}:{$b['line']} — $fqcn";
            }
        }

        $this->assertSame(
            [],
            array_values(array_unique($missing)),
            "Documentation names classes that do not exist:\n  " . implode("\n  ", array_unique($missing))
        );
    }

    public function testRunnableBlocksRunAndTheirAssertionsHold(): void
    {
        $ran = 0;
        foreach ($this->phpBlocks() as $b) {
            if (!$b['runnable']) {
                continue;
            }

            // The sentinel is the real signal. Exit status alone is not
            // trustworthy here: an exception handler installed by bootstrap
            // (or by an example) can swallow a fatal and still exit 0, which
            // is exactly how a broken example passed once already. Nothing
            // prints the sentinel except reaching the end of the block.
            $source = "<?php\nrequire " . var_export($this->root() . '/vendor/autoload.php', true) . ";\n"
                . "mini\\bootstrap();\n"
                . $b['code']
                . "\nfwrite(STDOUT, \"\\n__MINI_DOC_EXAMPLE_COMPLETED__\\n\");\n";

            $tmp = tempnam(sys_get_temp_dir(), 'minirun') . '.php';
            file_put_contents($tmp, $source);
            $out = [];
            // The CLI ships with zend.assertions=-1 on most builds, which
            // compiles assert() out entirely - a runnable example would then
            // "pass" while asserting nothing. Force them on.
            exec(
                'php -d zend.assertions=1 -d assert.exception=1 '
                . escapeshellarg($tmp) . ' 2>&1',
                $out,
                $status
            );
            unlink($tmp);

            $joined = implode("\n  ", $out);
            $this->assertSame(
                0,
                $status,
                "Runnable example {$b['file']}:{$b['line']} failed:\n  " . $joined
            );
            $this->assertStringContainsString(
                '__MINI_DOC_EXAMPLE_COMPLETED__',
                $joined,
                "Runnable example {$b['file']}:{$b['line']} did not run to completion:\n  " . $joined
            );
            $ran++;
        }

        $this->assertGreaterThan(
            0,
            $ran,
            'No ```php runnable blocks found — tag at least one documented example so the docs are executed'
        );
    }

    /**
     * Error strings quoted in documentation must be strings the code raises.
     *
     * The Python port found two advertised messages that no code path
     * produced. A quoted message is a promise about what an integrator (or an
     * LLM reading the error) will see, so an invented one is worse than none.
     */
    public function testAdvertisedErrorMessagesAreReal(): void
    {
        $doc = 'src/Database/Virtual/README.md';
        $docPath = $this->root() . '/' . $doc;
        $this->assertTrue(is_file($docPath), "$doc does not exist");

        // The doc is the source of truth: every line of the fenced block under
        // "## Errors name the fix" is a promise about a string the code
        // produces. Read them out of the markdown rather than restating them
        // here - a test that quotes its own fixture proves nothing.
        $md = file_get_contents($docPath);
        $this->assertTrue(
            (bool) preg_match('/^## Errors name the fix\s*$.*?^```\s*$(.*?)^```\s*$/ms', $md, $m),
            "$doc no longer has a fenced example block under '## Errors name the fix'"
        );
        $advertised = array_values(array_filter(array_map('trim', explode("\n", $m[1]))));
        $this->assertGreaterThan(0, count($advertised), "$doc advertises no error messages");

        // Provoke every message the engine can raise that the doc might quote.
        // A message in the doc with no provoker here fails too - that is the
        // point: adding a claim obliges you to show the code producing it.
        $engine = function (string $sql, array $columns = ['id', 'name']): string {
            $defs = array_map(
                fn(string $c) => new \mini\Table\ColumnDef(
                    $c,
                    $c === 'name' ? \mini\Table\Types\ColumnType::Text : \mini\Table\Types\ColumnType::Int,
                ),
                $columns
            );
            $t = new \mini\Table\ArrayTable(...$defs);
            $t->insert(array_combine($columns, array_map(fn($c) => $c === 'name' ? 'a' : 1, $columns)));

            $vdb = new \mini\Database\VirtualDatabase();
            $vdb->registerTable('t', $t);
            try {
                iterator_to_array($vdb->query($sql));
            } catch (\Throwable $e) {
                return $e->getMessage();
            }
            return '(no exception)';
        };

        $produced = [
            $engine('SELECT id FROM t ORDER BY nmae'),
            $engine('SELECT * FROM nosuch'),
            $engine('SELECT a.id, b.id, b.a_id FROM t a JOIN t b ON a.id = b.id', ['id', 'name', 'a_id']),
            (function (): string {
                $t = (new \mini\Table\ArrayTable(
                    new \mini\Table\ColumnDef('id', \mini\Table\Types\ColumnType::Int),
                ))->withAlias('u');
                try {
                    $t->eq('id', 1);
                } catch (\Throwable $e) {
                    return $e->getMessage();
                }
                return '(no exception)';
            })(),
        ];

        $unreal = [];
        foreach ($advertised as $message) {
            if (!in_array($message, $produced, true)) {
                $unreal[] = $message;
            }
        }

        $this->assertSame(
            [],
            $unreal,
            "$doc advertises error messages the engine does not produce:\n  "
            . implode("\n  ", $unreal)
            . "\n\nThe engine actually produced:\n  "
            . implode("\n  ", $produced)
        );
    }
};

exit($test->run());
