<?php
/**
 * Default TranslatorInterface configuration for Mini framework
 *
 * Applications can override by creating _config/mini/I18n/TranslatorInterface.php
 * to provide a custom translator implementation.
 *
 * Default implementation uses PathsRegistry to support multiple translation locations:
 * - Application translations (_translations/) take priority
 * - Framework translations (vendor/fubber/mini/translations/) as fallback
 *
 * Uses path aliases to organize translations by package:
 * - Framework translations are under MINI/ prefix
 * - Application translations have no prefix
 *
 * Example custom implementation:
 * ```php
 * class DatabaseTranslator implements TranslatorInterface {
 *     public function translate(Translatable $t): string {
 *         // Load from database instead of files
 *         return db()->queryField(...) ?? $t->getSourceText();
 *     }
 *     // ... implement other methods
 * }
 * return new DatabaseTranslator();
 * ```
 */

use mini\I18n\Translator;
use mini\Mini;

// Get translations PathsRegistry from Mini (configured in Mini::bootstrap())
$translationsPaths = Mini::$mini->paths->translations;

$translator = new Translator($translationsPaths);

// Register path alias for Mini framework
// This maps vendor/fubber/mini/* files to translations/default/MINI/*
$miniFrameworkPath = dirname(__FILE__, 4);
$translator->addPathAlias($miniFrameworkPath, 'MINI');

return $translator;
