<?php

namespace mini\Template;

/**
 * Template context - the $this object available in templates
 *
 * Provides template inheritance via:
 * - $this->extend() - extend a parent layout
 * - $this->block() / $this->end() - define blocks
 * - $this->show() - output blocks in parent templates
 *
 * A compiled template also calls $this->echo(), $this->print(),
 * $this->printf() and $this->vprintf() - these are what `echo`, `print`,
 * `printf()` and `vprintf()` are translated into by TemplateCompiler, and
 * are not meant to be called directly from hand-written template code.
 * They write to this context's RenderSink instead of producing direct
 * output, which is what lets two renders - even interleaved across Fibers
 * - never share output state.
 *
 * Example usage within templates:
 * ```php
 * <?php $this->extend('layout.php'); ?>
 * <?php $this->block('title', 'Home'); ?>
 * <?php $this->block('content'); ?><p>Hello!</p><?php $this->end(); ?>
 * ```
 */
final class TemplateContext
{
    public ?string $layout = null;
    public array $blocks = [];
    private array $inherited = [];
    private array $stack = [];
    private ?string $defaultLayout = null;
    private bool $hasOwnBlocks = false;
    private readonly RenderSink $sink;

    public function __construct(?RenderSink $sink = null)
    {
        $this->sink = $sink ?? new RenderSink();
    }

    /**
     * The render sink backing this context - used by Renderer to capture
     * a template's top-level output. Not part of the template-authoring API.
     *
     * @internal
     */
    public function sink(): RenderSink
    {
        return $this->sink;
    }

    /**
     * Set the default layout (called from viewstart processing)
     */
    public function setDefaultLayout(?string $layout): void
    {
        $this->defaultLayout = $layout;
    }

    /**
     * Import blocks from a child template (called by Renderer before include)
     */
    public function inheritBlocks(array $blocks): void
    {
        $this->inherited = $blocks;
    }

    /**
     * Mark this template as extending a parent layout
     *
     * Must be called once, before defining any blocks.
     *
     * @param string|null $file Parent template filename (uses default if null)
     * @throws \LogicException If called twice, after blocks, or no layout available
     */
    public function extend(?string $file = null): void
    {
        if ($this->layout !== null) {
            throw new \LogicException('extend() called twice - only one parent layout allowed');
        }
        if ($this->hasOwnBlocks) {
            throw new \LogicException('extend() must be called before defining blocks');
        }
        $this->layout = $file ?? $this->defaultLayout ?? throw new \LogicException(
            'No layout specified and no $layout default set'
        );
    }

    /**
     * Define a block (dual-use: inline or buffered)
     *
     * Inline mode: $block('name', 'inline content')
     * Buffered mode: $block('name'); ... $end();
     *
     * @param string $name Block name
     * @param string|null $value Optional inline value
     */
    public function block(string $name, ?string $value = null): void
    {
        $this->hasOwnBlocks = true;
        if ($value !== null) {
            $this->blocks[$name] = $value;
            return;
        }
        $this->stack[] = $name;
        $this->sink->push();
    }

    /**
     * End a buffered block started with block()
     *
     * @throws \LogicException If no block was started
     */
    public function end(): void
    {
        if (!$this->stack) {
            throw new \LogicException('No block started');
        }
        $name = array_pop($this->stack);
        $this->blocks[$name] = $this->sink->pop();
    }

    /**
     * Output a block in parent templates
     *
     * @param string $name Block name
     * @param string $default Default content if block not defined
     */
    public function show(string $name, string $default = ''): void
    {
        $this->echo($this->blocks[$name] ?? $this->inherited[$name] ?? $default);
    }

    /**
     * Get all blocks (own + inherited) for passing up to the next parent
     */
    public function allBlocks(): array
    {
        return $this->blocks + $this->inherited;
    }

    /**
     * Write each argument to the active render sink, in order, applying
     * the same string conversion `echo` applies to each of its arguments.
     *
     * This is what a compiled template's `echo` and `<?= ?>` call - not
     * meant to be called from hand-written template code.
     *
     * @internal
     */
    public function echo(mixed ...$values): void
    {
        foreach ($values as $value) {
            $this->sink->write($value);
        }
    }

    /**
     * Write $value to the active render sink and return int(1), exactly
     * like PHP's `print` expression.
     *
     * This is what a compiled template's `print` calls - not meant to be
     * called from hand-written template code.
     *
     * @internal
     */
    public function print(mixed $value): int
    {
        return $this->sink->write($value);
    }

    /**
     * sprintf() the arguments, write the result, and return its length -
     * exactly like PHP's `printf()`.
     *
     * This is what a compiled template's `printf()` call is renamed to -
     * not meant to be called from hand-written template code.
     *
     * @internal
     */
    public function printf(string $format, mixed ...$values): int
    {
        $string = sprintf($format, ...$values);
        $this->sink->write($string);
        return strlen($string);
    }

    /**
     * vsprintf() the arguments, write the result, and return its length -
     * exactly like PHP's `vprintf()`.
     *
     * This is what a compiled template's `vprintf()` call is renamed to -
     * not meant to be called from hand-written template code.
     *
     * @internal
     */
    public function vprintf(string $format, array $values): int
    {
        $string = vsprintf($format, $values);
        $this->sink->write($string);
        return strlen($string);
    }

    /**
     * Include a compiled template file with $this bound to this context
     *
     * @param string $__file Absolute path to the compiled template file
     * @param array $__vars Variables to extract into template scope
     */
    public function include(string $__file, array $__vars): void
    {
        extract($__vars, EXTR_SKIP);
        require $__file;
    }

    /**
     * Check for unclosed blocks after template execution
     *
     * @throws \LogicException If any blocks were started but not closed
     */
    public function assertNoUnclosedBlocks(): void
    {
        if ($this->stack) {
            $unclosed = implode(', ', $this->stack);
            throw new \LogicException("Unclosed block(s): $unclosed - missing \$this->end() call(s)");
        }
    }
}
