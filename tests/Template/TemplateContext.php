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
        echo 'Buffered content';
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
        ob_end_clean(); // clean up the output buffer
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
        ob_end_clean();
        ob_end_clean();
    }

    public function testShowOutputsBlockContent(): void
    {
        $ctx = new TemplateContext();
        $ctx->block('title', 'Hello');
        ob_start();
        $ctx->show('title');
        $output = ob_get_clean();
        self::assertEquals('Hello', $output);
    }

    public function testShowOutputsDefaultWhenBlockMissing(): void
    {
        $ctx = new TemplateContext();
        ob_start();
        $ctx->show('missing', 'Default');
        $output = ob_get_clean();
        self::assertEquals('Default', $output);
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
        echo 'Buffered';
        $ctx->end();
        self::assertEquals('Buffered', $ctx->blocks['title']);
    }

    public function testInlineBlockOverwritesBuffered(): void
    {
        $ctx = new TemplateContext();
        $ctx->block('title');
        echo 'Buffered';
        $ctx->end();
        $ctx->block('title', 'Inline');
        self::assertEquals('Inline', $ctx->blocks['title']);
    }
};

return $test->run();
