<?php

namespace mini\I18n;

/**
 * Minimal interface for translation services
 *
 * Defines the contract for translation implementations, allowing applications
 * to replace the default file-based translator with custom implementations
 * (e.g., database-backed, API-based, cached translations).
 *
 * The interface is intentionally minimal - it only requires the translate() method.
 * Custom implementations can determine the target language however they want:
 * - Read from \Locale::getDefault()
 * - Read from session/cookie
 * - Use domain-based detection
 * - Use a fixed language
 *
 * Example database-backed implementation:
 *
 * ```php
 * class DatabaseTranslator implements TranslatorInterface {
 *     private array $pathAliases = [];
 *
 *     public function addPathAlias(string $absolutePath, string $alias): void {
 *         // Store for building translation keys with prefixes
 *         $this->pathAliases[$absolutePath] = $alias;
 *     }
 *
 *     public function translate(Translatable $t): string {
 *         $lang = \Locale::getPrimaryLanguage(\Locale::getDefault());
 *         $key = $this->buildKey($t->getSourceFile(), $t->getSourceText());
 *         $translation = db()->queryField(
 *             "SELECT text FROM translations WHERE key = ? AND lang = ?",
 *             [$key, $lang]
 *         );
 *         return $translation ?? $t->getSourceText();
 *     }
 * }
 * ```
 *
 * Example simple implementation (ignoring path aliases):
 *
 * ```php
 * class SimpleTranslator implements TranslatorInterface {
 *     public function __construct(private array $translations) {}
 *
 *     public function addPathAlias(string $absolutePath, string $alias): void {
 *         // No-op: This implementation doesn't use path aliases
 *     }
 *
 *     public function translate(Translatable $t): string {
 *         return $this->translations[$t->getSourceText()] ?? $t->getSourceText();
 *     }
 * }
 * ```
 */
interface TranslatorInterface
{
    /**
     * Register a path alias for organizing translations by package
     *
     * Maps an absolute source path to an alias prefix. This helps organize translations
     * from different packages/vendors in a structured way.
     *
     * Example: Files under /var/www/vendor/acme/blog/ get translations under ACME-BLOG/ prefix
     *
     * Implementations that don't use path aliases can make this a no-op.
     *
     * @param string $absolutePath Absolute path to the source directory
     * @param string $alias Alias prefix for translations (e.g., 'MINI', 'ACME-BLOG')
     * @return void
     */
    public function addPathAlias(string $absolutePath, string $alias): void;

    /**
     * Translate a Translatable instance to the appropriate language
     *
     * The implementation determines the target language and returns the translated text.
     * Should handle ICU MessageFormat patterns and variable substitution.
     *
     * @param Translatable $translatable The translatable object containing source text and variables
     * @return string The translated and formatted text
     */
    public function translate(Translatable $translatable): string;
}
