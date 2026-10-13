<?php
/**
 * Tests for TemplateCompiler - translating template source into PHP that
 * writes through TemplateContext instead of producing direct output.
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Template\TemplateCompileException;
use mini\Template\TemplateCompiler;
use mini\Template\TemplateContext;
use mini\Test;

$test = new class extends Test {

    /**
     * Compile $source and run it with $this bound to a fresh
     * TemplateContext, returning the captured output. Mirrors exactly what
     * Renderer/TemplateContext::include() do with a compiled file, without
     * needing a real file on disk.
     */
    private function renderCompiled(string $source, array $vars = []): string
    {
        $compiled = TemplateCompiler::compile($source, 'inline-test.php');
        $ctx = new TemplateContext();
        $sink = $ctx->sink();
        $sink->push();
        (function (string $__php, array $__vars) {
            extract($__vars, EXTR_SKIP);
            eval('?>' . $__php);
        })->call($ctx, $compiled, $vars);
        return $sink->pop();
    }

    private function assertSameLineCount(string $source, string $compiled, string $message = ''): void
    {
        $this->assertSame(
            substr_count($source, "\n"),
            substr_count($compiled, "\n"),
            $message ?: 'Compiled output must have the same number of lines as the source'
        );
    }

    // ── Translations ────────────────────────────────────────────────────

    public function testInlineHtmlIsWritten(): void
    {
        self::assertEquals('Hello <b>world</b>', $this->renderCompiled('Hello <b>world</b>'));
    }

    public function testInlineHtmlEscapesQuotesAndBackslashes(): void
    {
        $source = "it's a \\test\\";
        self::assertEquals($source, $this->renderCompiled($source));
    }

    public function testShortEchoTagWritesExpression(): void
    {
        self::assertEquals('3', $this->renderCompiled('<?= 1 + 2 ?>'));
    }

    public function testShortEchoTagWithMultipleArguments(): void
    {
        self::assertEquals('ab', $this->renderCompiled("<?= 'a', 'b' ?>"));
    }

    public function testEchoStatementWithSingleArgument(): void
    {
        self::assertEquals('hi', $this->renderCompiled("<?php echo 'hi'; ?>"));
    }

    public function testEchoStatementWithSeveralArguments(): void
    {
        self::assertEquals('abc', $this->renderCompiled("<?php echo 'a', 'b', 'c'; ?>"));
    }

    public function testEchoArgumentsEvaluateLeftToRight(): void
    {
        $source = "<?php echo \$log('a'), \$log('b'); ?>";
        $order = [];
        $compiled = TemplateCompiler::compile($source, 'inline-test.php');
        $ctx = new TemplateContext();
        $sink = $ctx->sink();
        $sink->push();
        $log = function (string $x) use (&$order) {
            $order[] = $x;
            return $x;
        };
        (function (string $__php, array $__vars) {
            extract($__vars, EXTR_SKIP);
            eval('?>' . $__php);
        })->call($ctx, $compiled, ['log' => $log]);
        $sink->pop();
        self::assertEquals(['a', 'b'], $order);
    }

    public function testPrintIsAnExpressionReturningOne(): void
    {
        // print writes '3' immediately (as a side effect of evaluating the
        // assignment's right-hand side) and yields int(1), which echo then
        // writes too - exactly what native `$x = print 3; echo $x;` does.
        self::assertEquals('31', $this->renderCompiled('<?php $x = print 3; echo $x; ?>'));
    }

    public function testPrintInsideAnotherExpression(): void
    {
        // $this->echo()'s arguments are evaluated before any of them is
        // written (it's a plain variadic method, not the `echo` language
        // construct), so the nested print's own write happens before
        // echo's first argument is written - unlike native PHP, where
        // `echo 'a', print 'b';` writes 'a' before evaluating print('b').
        // This is the documented limitation: a construct that writes
        // directly while a template argument list is being evaluated
        // writes out of the order a hand-written echo would use. See
        // src/Template/README.md.
        self::assertEquals('ba1', $this->renderCompiled("<?php echo 'a', print 'b'; ?>"));
    }

    public function testPrintfIsRenamedArgumentsUntouched(): void
    {
        self::assertEquals('x=5', $this->renderCompiled("<?php printf('x=%d', 5); ?>"));
    }

    public function testPrintfReturnsLengthLikeRealPrintf(): void
    {
        // Same ordering caveat as print: printf() writes 'x=5' while
        // echo's single argument is being evaluated, then echo writes the
        // returned length - native PHP's `echo printf(...)` does the same.
        self::assertEquals('x=53', $this->renderCompiled("<?php echo printf('x=%d', 5); ?>"));
    }

    public function testVprintfIsRenamedArgumentsUntouched(): void
    {
        self::assertEquals('x=5', $this->renderCompiled("<?php vprintf('x=%d', [5]); ?>"));
    }

    public function testMethodCallNamedPrintfIsNotRewritten(): void
    {
        $compiled = TemplateCompiler::compile("<?php \$obj->printf('x'); ?>", 'inline-test.php');
        self::assertStringContainsString('$obj->printf(', $compiled);
        self::assertStringNotContainsString('$this->printf(', $compiled);
    }

    public function testStaticCallNamedPrintfIsNotRewritten(): void
    {
        $compiled = TemplateCompiler::compile("<?php Foo::printf('x'); ?>", 'inline-test.php');
        self::assertStringContainsString('Foo::printf(', $compiled);
        self::assertStringNotContainsString('$this->printf(', $compiled);
    }

    public function testNullsafeCallNamedPrintfIsNotRewritten(): void
    {
        $compiled = TemplateCompiler::compile("<?php \$obj?->printf('x'); ?>", 'inline-test.php');
        self::assertStringContainsString('$obj?->printf(', $compiled);
        self::assertStringNotContainsString('$this->printf(', $compiled);
    }

    public function testFunctionDeclarationNamedPrintfIsNotRewritten(): void
    {
        $compiled = TemplateCompiler::compile("<?php function printf(\$a) { return \$a; } ?>", 'inline-test.php');
        self::assertStringContainsString('function printf(', $compiled);
    }

    public function testFullyQualifiedPrintfIsRewritten(): void
    {
        $compiled = TemplateCompiler::compile("<?php \\printf('x'); ?>", 'inline-test.php');
        self::assertStringContainsString('$this->printf(', $compiled);
    }

    public function testAlternativeSyntaxPassesThroughUnchanged(): void
    {
        self::assertEquals('yes', $this->renderCompiled("<?php if (true): ?>yes<?php endif; ?>"));
        self::assertEquals('', $this->renderCompiled("<?php if (false): ?>yes<?php endif; ?>"));
    }

    public function testHeredocPassesThroughUnchanged(): void
    {
        $source = "<?php \$x = <<<EOT\nhello\nworld\nEOT;\necho \$x; ?>";
        self::assertEquals("hello\nworld", $this->renderCompiled($source));
    }

    public function testNowdocPassesThroughUnchanged(): void
    {
        $source = "<?php \$x = <<<'EOT'\nraw \$text\nEOT;\necho \$x; ?>";
        self::assertEquals("raw \$text", $this->renderCompiled($source));
    }

    public function testCloseTagSwallowsOneNewline(): void
    {
        // PHP swallows exactly one newline right after a closing tag - the
        // compiled close tag token carries that swallowed newline itself,
        // so this must render with no blank line between "a" and "b".
        $source = "<?php echo 'a'; ?>\nb";
        self::assertEquals('ab', $this->renderCompiled($source));
    }

    public function testShortOpenTagOffTreatsBareTagAsHtml(): void
    {
        $previous = ini_set('short_open_tag', '0');
        try {
            $compiled = TemplateCompiler::compile("<html><? echo 1; ?></html>", 'inline-test.php');
            self::assertStringContainsString('<html><? echo 1; ?></html>', $compiled);
        } finally {
            ini_set('short_open_tag', $previous);
        }
    }

    // ── Line numbers ────────────────────────────────────────────────────

    public function testCompiledOutputHasSameLineCountAsSource(): void
    {
        $source = "Hello\n<?php if (\$a): ?>\n<?= \$a ?>\n<?php endif; ?>\n<?php\necho 'x', 'y';\nprintf('%d', 1);\n?>\nBye\n";
        $compiled = TemplateCompiler::compile($source, 'inline-test.php');
        $this->assertSameLineCount($source, $compiled);
    }

    public function testMultiLineInlineHtmlKeepsItsNewlines(): void
    {
        $source = "<ul>\n  <li>one</li>\n  <li>two</li>\n</ul>\n";
        $compiled = TemplateCompiler::compile($source, 'inline-test.php');
        $this->assertSameLineCount($source, $compiled);
        self::assertEquals($source, $this->renderCompiled($source));
    }

    // ── Forbidden constructs ────────────────────────────────────────────

    public function testVarDumpIsForbidden(): void
    {
        self::assertThrows(
            fn() => TemplateCompiler::compile("<?php var_dump(\$x); ?>", 'page.php'),
            TemplateCompileException::class,
            'var_dump'
        );
    }

    public function testDebugZvalDumpIsForbidden(): void
    {
        self::assertThrows(
            fn() => TemplateCompiler::compile("<?php debug_zval_dump(\$x); ?>", 'page.php'),
            TemplateCompileException::class,
            'debug_zval_dump'
        );
    }

    public function testVarExportWithoutReturnArgumentIsForbidden(): void
    {
        self::assertThrows(
            fn() => TemplateCompiler::compile("<?php var_export(\$x); ?>", 'page.php'),
            TemplateCompileException::class,
            'var_export'
        );
    }

    public function testVarExportWithReturnArgumentIsAllowed(): void
    {
        $compiled = TemplateCompiler::compile("<?php echo var_export(\$x, true); ?>", 'page.php');
        self::assertStringContainsString('var_export(', $compiled);
    }

    public function testPrintRWithoutReturnArgumentIsForbidden(): void
    {
        self::assertThrows(
            fn() => TemplateCompiler::compile("<?php print_r(\$x); ?>", 'page.php'),
            TemplateCompileException::class,
            'print_r'
        );
    }

    public function testPrintRWithReturnArgumentIsAllowed(): void
    {
        $compiled = TemplateCompiler::compile("<?php echo print_r(\$x, true); ?>", 'page.php');
        self::assertStringContainsString('print_r(', $compiled);
    }

    public function testForbiddenErrorNamesFileAndLine(): void
    {
        self::assertThrows(
            fn() => TemplateCompiler::compile("one\ntwo\n<?php var_dump(\$x); ?>", 'blog/post.php'),
            TemplateCompileException::class,
            'blog/post.php on line 3'
        );
    }

    public function testIncludeIsForbidden(): void
    {
        self::assertThrows(
            fn() => TemplateCompiler::compile("<?php include 'other.php'; ?>", 'page.php'),
            TemplateCompileException::class,
            'render()'
        );
    }

    public function testRequireOnceIsForbidden(): void
    {
        self::assertThrows(
            fn() => TemplateCompiler::compile("<?php require_once 'other.php'; ?>", 'page.php'),
            TemplateCompileException::class,
            'render()'
        );
    }
};

return $test->run();
