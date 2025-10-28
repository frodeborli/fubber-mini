<?php

namespace mini;

/**
 * Minimal PHP-native template engine with:
 * - Multi-level inheritance ($extend)
 * - Block overrides ($block / $end)
 * - Dual-use $block(): inline or buffered
 *
 * Example:
 *   <?php $extend('layout.php'); ?>
 *   <?php $block('title', 'Home'); ?>
 *   <?php $block('content'); ?><p>Hello!</p><?php $end(); ?>
 */
final class TplCtx
{
    public ?string $layout = null;
    public array $blocks = [];
    private array $stack = [];

    public function extend(string $file): void
    {
        $this->layout = $file;
    }

    /**
     * Dual-use block:
     * - $block('name', 'inline content')
     * - $block('name'); ... $end();
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
