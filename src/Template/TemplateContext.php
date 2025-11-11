<?php

namespace mini\Template;

/**
 * Template context for managing inheritance and blocks
 *
 * Internal implementation detail used by Renderer for:
 * - Multi-level inheritance ($extend)
 * - Block overrides ($block / $end)
 * - Dual-use $block(): inline or buffered
 *
 * This class is not intended for direct use by developers - it's managed
 * internally by the template renderer.
 *
 * Example usage within templates:
 * ```php
 * <?php $extend('layout.php'); ?>
 * <?php $block('title', 'Home'); ?>
 * <?php $block('content'); ?><p>Hello!</p><?php $end(); ?>
 * ```
 */
final class TemplateContext
{
    public ?string $layout = null;
    public array $blocks = [];
    private array $stack = [];

    /**
     * Mark this template as extending a parent layout
     *
     * @param string $file Parent template filename
     */
    public function extend(string $file): void
    {
        $this->layout = $file;
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
}
