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
    private array $stack = [];
    private ?string $defaultLayout = null;

    /**
     * Set the default layout (called from viewstart processing)
     */
    public function setDefaultLayout(?string $layout): void
    {
        $this->defaultLayout = $layout;
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
        if ($this->blocks) {
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
        if ($value !== null) {
            $this->blocks[$name] = $value;
            return;
        }
        $this->stack[] = $name;
        ob_start();
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
        $this->blocks[$name] = ob_get_clean();
    }

    /**
     * Output a block in parent templates
     *
     * @param string $name Block name
     * @param string $default Default content if block not defined
     */
    public function show(string $name, string $default = ''): void
    {
        echo $this->blocks[$name] ?? $default;
    }

    /**
     * Include a template file with $this bound to this context
     *
     * @param string $__file Absolute path to template file
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
