<?php

namespace mini\Template;

use mini\Mini;

/**
 * Default template renderer with inheritance support
 *
 * Supports multi-level template inheritance via extend() and block() helpers.
 * Uses the views path registry to locate template files.
 *
 * Template inheritance example:
 * ```php
 * // child.php
 * <?php $extend('layout.php'); ?>
 * <?php $block('title', 'My Page'); ?>
 * <?php $block('content'); ?><p>Content here</p><?php $end(); ?>
 *
 * // layout.php
 * <html><head><title><?php $show('title', 'Untitled'); ?></title></head>
 * <body><?php $show('content'); ?></body></html>
 * ```
 */
class Renderer implements RendererInterface
{
    public function render(string $template, array $vars = []): string
    {
        // Use path registry to find template
        $templatePath = Mini::$mini->paths->views->findFirst($template);

        if (!$templatePath) {
            $searchedPaths = implode(', ', Mini::$mini->paths->views->getPaths());
            throw new \Exception("Template not found: $template (searched in: $searchedPaths)");
        }

        $ctx = new TemplateContext();

        // Make helper closures available in template scope
        $extend = fn(string $file) => $ctx->extend($file);
        $block  = fn(string $name, ?string $value = null) => $ctx->block($name, $value);
        $end    = fn() => $ctx->end();
        $show   = fn(string $name, string $default = '') => $ctx->show($name, $default);

        // Merge blocks from lower-level (child) into current context BEFORE rendering
        // This ensures $show() calls in parent templates can access child blocks
        if (isset($vars['__blocks'])) {
            $ctx->blocks = $vars['__blocks'] + $ctx->blocks;
        }

        // Isolated scope render function
        $renderOnce = function(string $__file, array $__vars) use ($extend, $block, $end, $show) {
            extract($__vars, EXTR_SKIP);
            require $__file;
        };

        // Render template
        ob_start();
        try {
            $renderOnce($templatePath, $vars);
        } catch (\Throwable $e) {
            ob_end_clean();
            return (string) $e;
        }
        $output = ob_get_clean();

        // Capture any raw output as "content" ONLY if no child provided content
        // and we didn't receive blocks from a child (meaning we're not a parent template)
        if (!isset($ctx->blocks['content']) && $output !== '' && !isset($vars['__blocks'])) {
            $ctx->blocks['content'] = $output;
        }

        // If this template extends another, recurse upward
        if ($ctx->layout) {
            $newVars = $vars;
            $newVars['__blocks'] = $ctx->blocks;
            return $this->render($ctx->layout, $newVars);
        }

        // Otherwise, this is the topmost template — render final output
        // If we have output (from a layout), return it; otherwise return content block
        return $output !== '' ? $output : ($ctx->blocks['content'] ?? '');
    }
}
