<?php
/**
 * Tests for Renderer - template discovery, viewstart processing, block
 * inheritance, and now also template compilation (TemplateCompiler /
 * CompiledTemplateCache).
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Mini;
use mini\Template\CompiledTemplateCache;
use mini\Template\Renderer;
use mini\Template\TemplateCompileException;
use mini\Test;

$test = new class extends Test {
    private Renderer $renderer;
    private string $cacheRoot;

    protected function setUp(): void
    {
        $this->renderer = new Renderer();

        // Fixture templates live in tests/_templates/, registered as an
        // extra views search path (same mechanism a theme or package uses).
        // Deliberately unnormalized, matching the Template README's own
        // package-bootstrap example (addPath(__DIR__ . '/../_views')) -
        // CompiledTemplateCache must normalize this itself.
        Mini::$mini->paths->views->addPath(__DIR__ . '/../_templates');

        // Compile to a throwaway directory instead of the project's real
        // _cache/, so test runs never leave compiled files behind in the repo.
        $this->cacheRoot = sys_get_temp_dir() . '/mini-template-cache-test-' . getmypid();
        $_ENV['MINI_CACHE_ROOT'] = $this->cacheRoot;
    }

    public function testRendersSimpleTemplateWithoutInheritance(): void
    {
        $output = $this->renderer->render('simple.php', ['name' => 'World']);
        self::assertStringContainsString('<h1>Simple Template</h1>', $output);
        self::assertStringContainsString('Hello, World!', $output);
    }

    public function testEscapesVariablesWithHtmlspecialchars(): void
    {
        $output = $this->renderer->render('simple.php', ['name' => '<script>']);
        self::assertStringContainsString('&lt;script&gt;', $output);
        self::assertStringNotContainsString('<script>', $output);
    }

    public function testTwoLevelInheritanceMergesBlocks(): void
    {
        $output = $this->renderer->render('test-child.php', [
            'user' => ['name' => 'Ada', 'email' => 'ada@example.com'],
        ]);
        self::assertStringContainsString('Welcome, Ada', $output);
        self::assertStringContainsString('Welcome to the site, Ada!', $output);
        self::assertStringContainsString('ada@example.com', $output);
        // The layout wrapper is present too.
        self::assertStringContainsString('<title>', $output);
    }

    public function testThreeLevelInheritanceMergesAllAncestorBlocks(): void
    {
        $output = $this->renderer->render('special-page.php', ['user' => 'Ada']);
        self::assertStringContainsString('Special Page', $output);          // title, from special-page
        self::assertStringContainsString('with-sidebar', $output);           // body-class, from layout-with-sidebar
        self::assertStringContainsString('Section 1', $output);              // sidebar, from special-page
        self::assertStringContainsString('three levels of inheritance', $output); // content, from special-page
        self::assertStringContainsString('<!DOCTYPE html>', $output);        // structure, from base.php
    }

    public function testPartialIsRenderedViaMiniRender(): void
    {
        $output = $this->renderer->render('with-partial.php', [
            'users' => [
                ['name' => 'Ada', 'email' => 'ada@example.com'],
                ['name' => 'Bob', 'email' => 'bob@example.com'],
            ],
        ]);
        self::assertStringContainsString('user-card', $output);
        self::assertStringContainsString('Ada', $output);
        self::assertStringContainsString('Bob', $output);
        self::assertStringContainsString('Total users: 2', $output);
    }

    public function testDualUseBlockInlineAndBuffered(): void
    {
        $output = $this->renderer->render('with-set.php', ['user' => ['name' => 'Ada']]);
        self::assertStringContainsString('Page with dual-use', $output);
        self::assertStringContainsString('Ada', $output);
        self::assertStringContainsString('2025 Example Corp', $output);
    }

    // ── Compiled file path (Interpretable file paths) ──────────────────

    public function testCompiledPathMirrorsTemplatePathUnderCacheTemplates(): void
    {
        $this->renderer->render('simple.php', ['name' => 'x']);

        $sourcePath = Mini::$mini->paths->views->findFirst('simple.php');
        $compiledPath = CompiledTemplateCache::getCompiledPath($sourcePath);

        self::assertTrue(is_file($compiledPath), 'Compiled file must exist on disk');
        self::assertStringStartsWith($this->cacheRoot . '/templates/', $compiledPath);
        // The compiled path is never a hash - the template's own path is
        // legible inside it.
        self::assertStringContainsString('tests/_templates/simple.php', $compiledPath);
    }

    // ── Line numbers ─────────────────────────────────────────────────────

    public function testExceptionLineNumberMatchesTemplateSourceLine(): void
    {
        try {
            $this->renderer->render('throws.php');
            self::fail('Expected the template to throw');
        } catch (\RuntimeException $e) {
            self::assertEquals('boom', $e->getMessage());
            // "throw" is on line 2 of tests/_templates/throws.php - the
            // compiled file must report the very same line.
            self::assertSame(2, $e->getLine());
            self::assertStringContainsString('tests/_templates/throws.php', $e->getFile());
        }
    }

    // ── Forbidden constructs surface through render() ───────────────────

    public function testForbiddenConstructThrowsWithTemplatePathAndLine(): void
    {
        self::assertThrows(
            fn() => $this->renderer->render('forbidden.php', ['x' => 1]),
            TemplateCompileException::class,
            'tests/_templates/forbidden.php on line 1'
        );
    }
};

return $test->run();
