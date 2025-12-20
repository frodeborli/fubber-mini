<?php

/**
 * Template Feature - Public API Functions
 *
 * Provides template rendering with inheritance support.
 */

namespace mini;

use mini\Template\RendererInterface;
use mini\Mini;
use mini\Lifetime;
use mini\Util\PathsRegistry;

// Register views path registry
$primaryViewsPath = $_ENV['MINI_VIEWS_ROOT'] ?? (Mini::$mini->root . '/_views');
Mini::$mini->paths->views = new PathsRegistry($primaryViewsPath);

// Add framework's _views path as fallback
$frameworkViewsPath = \dirname((new \ReflectionClass(Mini::class))->getFileName(), 2) . '/_views';
Mini::$mini->paths->views->addPath($frameworkViewsPath);

// Register Template service
Mini::$mini->addService(RendererInterface::class, Lifetime::Singleton, fn() => Mini::$mini->loadServiceConfig(RendererInterface::class));

/**
 * Render a template with provided variables
 *
 * Supports multi-level template inheritance via $this->extend() and $this->block().
 * Uses path registry to find templates in _views/ directory.
 *
 * Simple templates:
 * ```php
 * echo render('settings.php', ['user' => $user]);
 * ```
 *
 * With layout inheritance:
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
 *
 * Including sub-templates (partials):
 * ```php
 * <?= mini\render('user-card.php', ['user' => $currentUser]) ?>
 * ```
 *
 * @param string $template Template name/path (without extension, e.g., 'user/profile')
 * @param array $vars Variables to make available in the template
 * @return string Rendered template output
 * @throws \Exception If template not found or rendering fails
 */
function render(string $template, array $vars = []): string {
    return Mini::$mini->get(RendererInterface::class)->render($template, $vars);
}

