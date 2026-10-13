<?php
/**
 * Tests for CompiledTemplateCache - compiling a template file once, reusing
 * the compiled file while it's fresh, recompiling when the source changes,
 * and invalidating opcache's copy on every recompile.
 *
 * This file declares itself in the mini\Template namespace so it can
 * define its own opcache_invalidate() - PHP resolves an unqualified call
 * to a global function by first looking in the calling code's own
 * namespace, so this shadows the real opcache_invalidate() for
 * CompiledTemplateCache (which lives in the same namespace) without
 * touching the real extension function or needing a mocking library.
 */

namespace mini\Template;

use mini\Test;

/** @var list<array{path: string, force: bool}> */
$GLOBALS['__opcacheInvalidateCalls'] = [];

function opcache_invalidate(string $path, bool $force = false): bool
{
    $GLOBALS['__opcacheInvalidateCalls'][] = ['path' => $path, 'force' => $force];
    return true;
}

require __DIR__ . '/../../ensure-autoloader.php';

$test = new class extends Test {
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mini-compiled-template-cache-test-' . getmypid();
        @mkdir($this->dir, 0777, true);
        $_ENV['MINI_CACHE_ROOT'] = $this->dir . '/cache';
        $GLOBALS['__opcacheInvalidateCalls'] = [];
    }

    private function writeSource(string $content): string
    {
        $path = $this->dir . '/template-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($path, $content);
        return $path;
    }

    public function testCompilesOnFirstCall(): void
    {
        $source = $this->writeSource("Hello <?= \$name ?>");
        $compiledPath = CompiledTemplateCache::getCompiledPath($source);

        self::assertTrue(is_file($compiledPath));
        self::assertEquals(
            TemplateCompiler::compile(file_get_contents($source), 'template.php'),
            file_get_contents($compiledPath)
        );
    }

    public function testInvalidatesOpcacheOnFirstCompile(): void
    {
        $source = $this->writeSource("one");
        $before = count($GLOBALS['__opcacheInvalidateCalls']);
        $compiledPath = CompiledTemplateCache::getCompiledPath($source);
        $calls = array_slice($GLOBALS['__opcacheInvalidateCalls'], $before);

        self::assertCount(1, $calls);
        self::assertEquals($compiledPath, $calls[0]['path']);
        self::assertTrue($calls[0]['force']);
    }

    public function testReusesCompiledFileWhenSourceUnchanged(): void
    {
        $source = $this->writeSource("one");
        $first = CompiledTemplateCache::getCompiledPath($source);
        $before = count($GLOBALS['__opcacheInvalidateCalls']);

        $second = CompiledTemplateCache::getCompiledPath($source);

        self::assertSame($first, $second);
        // No recompilation happened, so opcache was never told to invalidate again.
        self::assertCount($before, $GLOBALS['__opcacheInvalidateCalls']);
    }

    public function testRecompilesWhenSourceIsNewer(): void
    {
        $source = $this->writeSource("<?= 'old' ?>");
        $compiledPath = CompiledTemplateCache::getCompiledPath($source);
        self::assertStringContainsString('old', file_get_contents($compiledPath));
        $before = count($GLOBALS['__opcacheInvalidateCalls']);

        // Make the change unambiguously newer than whatever mtime the
        // first compile produced, regardless of filesystem mtime resolution.
        touch($source, time() + 2);
        file_put_contents($source, "<?= 'new' ?>");
        touch($source, time() + 2);

        $recompiledPath = CompiledTemplateCache::getCompiledPath($source);

        self::assertSame($compiledPath, $recompiledPath);
        self::assertStringContainsString('new', file_get_contents($recompiledPath));
        self::assertStringNotContainsString('old', file_get_contents($recompiledPath));
        self::assertCount($before + 1, $GLOBALS['__opcacheInvalidateCalls']);
    }

    public function testAtomicWriteLeavesNoTemporaryFileBehind(): void
    {
        $source = $this->writeSource("x");
        $compiledPath = CompiledTemplateCache::getCompiledPath($source);

        $leftovers = glob(dirname($compiledPath) . '/*.tmp');
        self::assertCount(0, $leftovers);
    }

    public function testForbiddenConstructThrowsInsteadOfWritingACompiledFile(): void
    {
        $source = $this->writeSource("<?php var_dump(\$x); ?>");

        self::assertThrows(
            fn() => CompiledTemplateCache::getCompiledPath($source),
            TemplateCompileException::class
        );

        $compiledPath = $_ENV['MINI_CACHE_ROOT'] . '/templates' . $source;
        self::assertFalse(is_file($compiledPath));
    }
};

return $test->run();
