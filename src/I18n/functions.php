<?php

/**
 * I18n Feature - Public API Functions
 *
 * Only commonly-used developer-facing functions in mini\ namespace.
 * Internal framework functions are in mini\I18n\ namespace.
 */

namespace mini;

use mini\I18n\Fmt;
use mini\I18n\Translatable;

/**
 * Translation function - creates a Translatable instance
 *
 * This is the primary function developers use for translations.
 *
 * @param string $text The text to translate
 * @param array $vars Variables for interpolation (e.g., ['name' => 'John'])
 * @return Translatable
 */
function t(string $text, array $vars = []): Translatable {
    return new Translatable($text, $vars);
}

/**
 * Get a formatter instance for convenience
 *
 * Provides shortcuts for common formatting tasks.
 * Note: Fmt methods are static, so you can also call Fmt::currency() directly
 *
 * @return Fmt Stateless formatter instance (singleton)
 */
function fmt(): Fmt {
    return Mini::$mini->get(Fmt::class);
}

/**
 * ============================================================================
 * I18n Service Registration
 * ============================================================================
 */

namespace mini\I18n;

use mini\Mini;
use mini\Lifetime;

// Register I18n services
Mini::$mini->addService(Translator::class, Lifetime::Singleton, fn() => Mini::$mini->loadServiceConfig(Translator::class));
Mini::$mini->addService(Fmt::class, Lifetime::Singleton, fn() => Mini::$mini->loadServiceConfig(Fmt::class));
