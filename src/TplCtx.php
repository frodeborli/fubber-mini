<?php

namespace mini;

/**
 * Template rendering context for layout inheritance and blocks
 *
 * Provides simple Twig-like template inheritance using pure PHP:
 * - extend() - Inherit from parent layout
 * - start() / end() - Define named blocks
 * - block() - Output blocks with optional defaults
 *
 * Fast, opcache-friendly, no compilation needed.
 */
final class TplCtx
{
    /** @var string|null Parent layout file to render */
    public ?string $layout = null;

    /** @var array<string, string> Named content blocks */
    public array $blocks = [];

    /** @var array<string> Stack of currently-open blocks */
    private array $stack = [];

    /**
     * Extend a parent layout
     *
     * @param string $file Template filename (resolved via path registry)
     */
    public function extend(string $file): void
    {
        $this->layout = $file;
    }

    /**
     * Start capturing a named block
     *
     * @param string $name Block name
     */
    public function start(string $name): void
    {
        $this->stack[] = $name;
        ob_start();
    }

    /**
     * End current block capture
     */
    public function end(): void
    {
        $name = array_pop($this->stack);
        $this->blocks[$name] = ob_get_clean();
    }

    /**
     * Output a named block with optional default
     *
     * @param string $name Block name
     * @param string $default Default content if block not defined
     */
    public function block(string $name, string $default = ''): void
    {
        echo $this->blocks[$name] ?? $default;
    }
}
