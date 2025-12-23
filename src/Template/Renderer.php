<?php

namespace mini\Template;

use mini\Mini;

/**
 * Default template renderer with inheritance support
 *
 * Supports multi-level template inheritance via $this->extend() and $this->block().
 * Uses the views path registry to locate template files.
 *
 * Template inheritance example:
 * ```php
 * // child.php
 * <?php $this->extend('layout.php'); ?>
 * <?php $this->block('title', 'My Page'); ?>
 * <?php $this->block('content'); ?><p>Content here</p><?php $this->end(); ?>
 *
 * // layout.php
 * <html><head><title><?php $this->show('title', 'Untitled'); ?></title></head>
 * <body><?php $this->show('content'); ?></body></html>
 * ```
 */
class Renderer implements RendererInterface
{
    /**
     * Find and include stacked _viewstart.php files for a template path
     *
     * Searches from root to the template's directory, including each _viewstart.php found.
     * Variables set in _viewstart.php files (like $layout) are captured and returned.
     * Later files override earlier ones (most specific wins).
     *
     * @param string $template Template path (e.g., 'admin/users/list.php')
     * @param array $vars Initial variables to make available
     * @return array{layout: ?string, vars: array} Captured $layout and merged vars
     */
    private function includeViewstarts(string $template, array $vars): array
    {
        $pathsRegistry = Mini::$mini->paths->views;
        $parts = explode('/', trim($template, '/'));
        array_pop($parts); // Remove the template filename

        // Build list of directories to check (root first, most specific last)
        $dirsToCheck = [''];
        $current = '';
        foreach ($parts as $part) {
            $current .= ($current ? '/' : '') . $part;
            $dirsToCheck[] = $current;
        }

        // Collect all _viewstart.php paths that exist
        $viewstartPaths = [];
        foreach ($dirsToCheck as $dir) {
            $viewstartPath = $dir ? "$dir/_viewstart.php" : '_viewstart.php';
            $fullPath = $pathsRegistry->findFirst($viewstartPath);
            if ($fullPath) {
                $viewstartPaths[] = $fullPath;
            }
        }

        // Include all in same scope (root first), allowing later files to override
        $include = function(array $__viewstartPaths, array $__vars): array {
            extract($__vars);
            $layout = null;
            foreach ($__viewstartPaths as $__path) {
                include $__path;
            }
            $defined = get_defined_vars();
            unset($defined['__viewstartPaths'], $defined['__vars'], $defined['__path']);
            return ['layout' => $layout ?? null, 'vars' => $defined];
        };

        return $include($viewstartPaths, $vars);
    }

    public function render(string $template, array $vars = []): string
    {
        // Use path registry to find template
        $templatePath = Mini::$mini->paths->views->findFirst($template);

        if (!$templatePath) {
            $searchedPaths = implode(', ', Mini::$mini->paths->views->getPaths());
            throw new \Exception("Template not found: $template (searched in: $searchedPaths)");
        }

        $ctx = new TemplateContext();

        // Find and include _viewstart.php files (stacked, root first)
        // Only for initial render, not when extending parent layouts
        if (!isset($vars['__blocks'])) {
            $viewstartVars = $this->includeViewstarts($template, $vars);
            $ctx->setDefaultLayout($viewstartVars['layout'] ?? null);
            $vars = $viewstartVars['vars'];
        }

        // Merge blocks from lower-level (child) into current context BEFORE rendering
        // This ensures $this->show() calls in parent templates can access child blocks
        if (isset($vars['__blocks'])) {
            $ctx->blocks = $vars['__blocks'] + $ctx->blocks;
        }

        // Render template with $this bound to context
        ob_start();
        try {
            $ctx->include($templatePath, $vars);
            $ctx->assertNoUnclosedBlocks();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        $output = ob_get_clean();

        // Capture any raw output as "content" ONLY if no child provided content
        // and we didn't receive blocks from a child (meaning we're not a parent template)
        if ($output !== '' && !isset($vars['__blocks'])) {
            if (isset($ctx->blocks['content'])) {
                throw new \LogicException('Template has output outside of blocks which will be discarded. Wrap all output in $this->block()/$this->end().');
            }
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
