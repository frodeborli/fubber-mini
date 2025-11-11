<?php

namespace mini\Template;

/**
 * Template rendering interface
 *
 * Implementations are responsible for locating template files, processing template
 * syntax (inheritance, includes, etc.), and rendering output with provided variables.
 */
interface RendererInterface
{
    /**
     * Render a template with variables
     *
     * @param string $template Template name/path (without extension, e.g., 'user/profile')
     * @param array $vars Variables to make available in the template
     * @return string Rendered template output
     * @throws \Exception If template not found or rendering fails
     */
    public function render(string $template, array $vars = []): string;
}
