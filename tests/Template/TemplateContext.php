<?php
/**
 * Tests for TemplateContext - the $this object in templates
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Template\TemplateContext;
use mini\Test;

$test = new class extends Test {

    public function testBlockInlineSetsValue(): void
    {
        $ctx = new TemplateContext();
        $ctx->block('title', 'Hello');
        self::assertEquals('Hello', $ctx->blocks['title']);
    }

    public function testBlockBufferedCapturesOutput(): void
    {
        $ctx = new TemplateContext();
        $ctx->block('content');
        // A compiled template writes via $this->echo(), not bare echo() -
        // block()/end() capture through the render sink, not an output
        // buffer, so that's what a buffered block actually captures.
        $ctx->echo('Buffered content');
        $ctx->end();
        self::assertEquals('Buffered content', $ctx->blocks['content']);
    }

    public function testEndThrowsIfNoBlockStarted(): void
    {
        $ctx = new TemplateContext();
        self::assertThrows(fn() => $ctx->end(), \LogicException::class, 'No block started');
    }

    public function testExtendSetsLayout(): void
    {
        $ctx = new TemplateContext();
        $ctx->extend('layout.php');
        self::assertEquals('layout.php', $ctx->layout);
    }

    public function testExtendUsesDefaultLayoutWhenNoArgument(): void
    {
        $ctx = new TemplateContext();
        $ctx->setDefaultLayout('default.php');
        $ctx->extend();
        self::assertEquals('default.php', $ctx->layout);
    }

    public function testExtendThrowsIfNoLayoutAndNoDefault(): void
    {
        $ctx = new TemplateContext();
        self::assertThrows(fn() => $ctx->extend(), \LogicException::class, 'No layout specified');
    }

    public function testExtendThrowsIfCalledTwice(): void
    {
        $ctx = new TemplateContext();
        $ctx->extend('layout.php');
        self::assertThrows(
            fn() => $ctx->extend('other.php'),
            \LogicException::class,
            'extend() called twice'
        );
    }

    public function testExtendThrowsIfCalledAfterBlocks(): void
    {
        $ctx = new TemplateContext();
        $ctx->block('title', 'Hello');
        self::assertThrows(
            fn() => $ctx->extend('layout.php'),
            \LogicException::class,
            'extend() must be called before defining blocks'
        );
    }

    public function testAssertNoUnclosedBlocksPassesWhenNoBlocksOpen(): void
    {
        $ctx = new TemplateContext();
        $ctx->block('title', 'Hello');  // inline, not buffered
        $ctx->assertNoUnclosedBlocks(); // should not throw
        self::assertTrue(true);
    }

    public function testAssertNoUnclosedBlocksThrowsForUnclosedBlock(): void
    {
        $ctx = new TemplateContext();
        $ctx->block('content');  // starts buffering
        // no end() call
        self::assertThrows(
            fn() => $ctx->assertNoUnclosedBlocks(),
            \LogicException::class,
            'Unclosed block(s): content'
        );
        // The unclosed capture frame lives on the context's own RenderSink,
        // not in a global output buffer, so there's nothing to clean up.
    }

    public function testAssertNoUnclosedBlocksListsMultipleUnclosedBlocks(): void
    {
        $ctx = new TemplateContext();
        $ctx->block('header');
        $ctx->block('content');
        // no end() calls
        self::assertThrows(
            fn() => $ctx->assertNoUnclosedBlocks(),
            \LogicException::class,
            'header, content'
        );
    }

    public function testShowOutputsBlockContent(): void
    {
        // show() writes to the active sink frame, not to an output
        // buffer, so capture it the way a compiled template would: a
        // surrounding buffered block.
        $ctx = new TemplateContext();
        $ctx->block('title', 'Hello');
        $ctx->block('capture');
        $ctx->show('title');
        $ctx->end();
        self::assertEquals('Hello', $ctx->blocks['capture']);
    }

    public function testShowOutputsDefaultWhenBlockMissing(): void
    {
        $ctx = new TemplateContext();
        $ctx->block('capture');
        $ctx->show('missing', 'Default');
        $ctx->end();
        self::assertEquals('Default', $ctx->blocks['capture']);
    }

    public function testLaterBlockDefinitionOverwritesEarlier(): void
    {
        $ctx = new TemplateContext();
        $ctx->block('title', 'First');
        $ctx->block('title', 'Second');
        self::assertEquals('Second', $ctx->blocks['title']);
    }

    public function testBufferedBlockOverwritesInline(): void
    {
        $ctx = new TemplateContext();
        $ctx->block('title', 'Inline');
        $ctx->block('title');
        $ctx->echo('Buffered');
        $ctx->end();
        self::assertEquals('Buffered', $ctx->blocks['title']);
    }

    public function testInlineBlockOverwritesBuffered(): void
    {
        $ctx = new TemplateContext();
        $ctx->block('title');
        $ctx->echo('Buffered');
        $ctx->end();
        $ctx->block('title', 'Inline');
        self::assertEquals('Inline', $ctx->blocks['title']);
    }

    public function testEchoWritesEachArgumentInOrder(): void
    {
        $ctx = new TemplateContext();
        $ctx->block('content');
        $ctx->echo('a', 'b', 3);
        $ctx->end();
        self::assertEquals('ab3', $ctx->blocks['content']);
    }

    public function testEchoConvertsValuesLikeNativeEcho(): void
    {
        $ctx = new TemplateContext();
        $ctx->block('content');
        $ctx->echo(null, false, true, 1.5);
        $ctx->end();
        self::assertEquals('11.5', $ctx->blocks['content']);
    }

    public function testPrintWritesAndReturnsOne(): void
    {
        $ctx = new TemplateContext();
        $ctx->block('content');
        $result = $ctx->print('Hello');
        $ctx->end();
        self::assertSame(1, $result);
        self::assertEquals('Hello', $ctx->blocks['content']);
    }

    public function testPrintfWritesFormattedStringAndReturnsLength(): void
    {
        $ctx = new TemplateContext();
        $ctx->block('content');
        $result = $ctx->printf('%s is %d', 'x', 5);
        $ctx->end();
        self::assertEquals('x is 5', $ctx->blocks['content']);
        self::assertSame(6, $result);
    }

    public function testVprintfWritesFormattedStringAndReturnsLength(): void
    {
        $ctx = new TemplateContext();
        $ctx->block('content');
        $result = $ctx->vprintf('%s is %d', ['x', 5]);
        $ctx->end();
        self::assertEquals('x is 5', $ctx->blocks['content']);
        self::assertSame(6, $result);
    }
};

return $test->run();
