<?php

namespace mini\I18n;

use mini\Mini;
use mini\Util\QueryParser;
use mini\Util\PathsRegistry;
use MessageFormatter;

/**
 * Translator class responsible for loading and managing translations
 *
 * Handles translation file loading, caching, and fallback logic.
 * Automatically creates missing translation entries in the default language files.
 *
 * Implements TranslatorInterface to allow custom translator implementations.
 */
class Translator implements TranslatorInterface
{
    private PathsRegistry $translationsPaths;
    private string $defaultLanguage = 'default';
    private array $loadedTranslations = [];
    private bool $autoCreateDefaults;
    private array $pathAliases = [];

    public function __construct(PathsRegistry $translationsPaths, bool $autoCreateDefaults = true)
    {
        $this->translationsPaths = $translationsPaths;
        $this->autoCreateDefaults = $autoCreateDefaults;
    }

    /**
     * Add a path alias for translation file resolution
     *
     * Maps an absolute source path to an alias prefix. When a t() call originates from
     * a file under the aliased path, translations are searched under {alias}/ prefix.
     *
     * Example:
     *   $translator->addPathAlias('/var/www/vendor/fubber/mini', 'MINI');
     *
     *   A t() call from /var/www/vendor/fubber/mini/src/Invalid.php will search for:
     *   - _translations/default/MINI/src/Invalid.php.json (application override)
     *   - vendor/fubber/mini/translations/default/MINI/src/Invalid.php.json (framework)
     *
     * @param string $absolutePath Absolute path to the source directory
     * @param string $alias Alias prefix for translations (e.g., 'MINI', 'MY-PLUGIN')
     */
    public function addPathAlias(string $absolutePath, string $alias): void
    {
        $this->pathAliases[rtrim($absolutePath, '/')] = $alias;
    }

    /**
     * Get current language code from global locale
     */
    private function getCurrentLanguageCode(): string
    {
        return \Locale::getPrimaryLanguage(\Locale::getDefault());
    }



    /**
     * Translate a Translatable instance using ICU MessageFormatter
     */
    public function translate(Translatable $translatable): string
    {
        $sourceText = $translatable->getSourceText();
        $sourceFile = $translatable->getSourceFile();
        $vars = $translatable->getVars();

        // Get translation from files (with conditional support)
        $translatedText = $this->getTranslation($sourceFile, $sourceText, $vars);

        // Use ICU MessageFormatter for all translations
        return $this->formatMessage($translatedText, $vars);
    }

    /**
     * Get translation for a specific source text from a specific file
     */
    private function getTranslation(string $sourceFile, string $sourceText, array $vars = []): string
    {
        // Get default language from Mini singleton
        $defaultLanguage = Mini::$mini->defaultLanguage;

        // Create simple fallback chain: current -> default -> 'default'
        $currentLanguage = $this->getCurrentLanguageCode();
        $fallbackChain = [$currentLanguage];
        if ($currentLanguage !== $defaultLanguage) {
            $fallbackChain[] = $defaultLanguage;
        }
        if (!in_array('default', $fallbackChain)) {
            $fallbackChain[] = 'default';
        }

        // Determine alias prefix for this source file
        $aliasPrefix = $this->getAliasPrefix($sourceFile);

        // Try each language in the fallback chain
        foreach ($fallbackChain as $langCode) {
            $translation = $this->loadTranslationFromFile($langCode, $sourceFile, $sourceText, $vars, $aliasPrefix);

            if ($translation !== null) {
                return $translation;
            }
        }

        // Auto-create entry in default language file if enabled
        if ($this->autoCreateDefaults) {
            $this->createDefaultTranslation($sourceFile, $sourceText, $aliasPrefix);
        }

        // Final fallback: return original text
        return $sourceText;
    }

    /**
     * Load translation from a specific language file with alias prefix support
     */
    private function loadTranslationFromFile(string $languageCode, string $sourceFile, string $sourceText, array $vars = [], ?string $aliasPrefix = null): ?string
    {
        // Build translation file path with alias prefix if present
        $translationPath = $aliasPrefix ? "{$aliasPrefix}/{$sourceFile}" : $sourceFile;

        $translations = $this->getFileTranslations($languageCode, $translationPath, $aliasPrefix);
        $translation = $this->extractTranslation($translations, $sourceText, $vars);

        return $translation;
    }

    /**
     * Extract translation from loaded translations array with conditional support
     */
    private function extractTranslation(array $translations, string $sourceText, array $vars = []): ?string
    {
        if (!array_key_exists($sourceText, $translations)) {
            return null;
        }

        $translation = $translations[$sourceText];

        // Treat null and empty string as "not translated" - fall back to default
        if ($translation === null || $translation === '') {
            return null;
        }

        // Handle conditional translations (arrays)
        if (is_array($translation)) {
            return $this->resolveConditionalTranslation($translation, $vars);
        }

        // Simple string translation
        return $translation;
    }


    /**
     * Resolve conditional translation based on variable values using QueryParser
     */
    private function resolveConditionalTranslation(array $conditionalTranslation, array $vars): ?string
    {
        // Try each condition in order
        foreach ($conditionalTranslation as $condition => $translationText) {
            // Check if condition matches the variables (before checking default)
            if ($condition !== '' && $this->evaluateCondition($condition, $vars)) {
                return $translationText;
            }
        }

        // If no specific conditions matched, use default fallback
        if (array_key_exists('', $conditionalTranslation)) {
            return $conditionalTranslation[''];
        }

        // If no conditions matched and no default ("") provided, return null
        return null;
    }

    /**
     * Evaluate a condition string against variable values using QueryParser
     */
    private function evaluateCondition(string $condition, array $vars): bool
    {
        try {
            $queryParser = new QueryParser($condition);
            return $queryParser->matches($vars);
        } catch (\Exception $e) {
            // If parsing fails, treat as no match
            return false;
        }
    }

    /**
     * Get all translations for a specific language and file with alias prefix support
     */
    private function getFileTranslations(string $languageCode, string $sourceFile, ?string $aliasPrefix = null): array
    {
        $cacheKey = $aliasPrefix ? "{$aliasPrefix}:{$languageCode}:{$sourceFile}" : "{$languageCode}:{$sourceFile}";

        if (isset($this->loadedTranslations[$cacheKey])) {
            return $this->loadedTranslations[$cacheKey];
        }

        $filePath = $this->getTranslationFilePath($languageCode, $sourceFile, $aliasPrefix);

        if (!file_exists($filePath)) {
            $this->loadedTranslations[$cacheKey] = [];
            return [];
        }

        $jsonContent = file_get_contents($filePath);

        if ($jsonContent === false) {
            // File read error - cache empty array to avoid repeated attempts
            $this->loadedTranslations[$cacheKey] = [];
            return [];
        }

        $translations = json_decode($jsonContent, true);

        if (!is_array($translations)) {
            // JSON decode error or invalid format - cache empty array
            $this->loadedTranslations[$cacheKey] = [];
            return [];
        }

        $this->loadedTranslations[$cacheKey] = $translations;
        return $translations;
    }

    /**
     * Ensure default translation exists for a source text with alias prefix support
     * Creates file if missing, adds string if not present in existing file
     */
    private function createDefaultTranslation(string $sourceFile, string $sourceText, ?string $aliasPrefix = null): void
    {
        $filePath = $this->getTranslationFilePath($this->defaultLanguage, $sourceFile, $aliasPrefix);

        // Ensure directory exists
        $directory = dirname($filePath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        // Check if file exists, if not create it
        $fileExists = file_exists($filePath);

        // Load existing translations (will be empty array if file doesn't exist)
        $translations = $this->getFileTranslations($this->defaultLanguage, $sourceFile, $aliasPrefix);

        // Track if we need to update the file
        $needsUpdate = false;

        if (!$fileExists) {
            // File doesn't exist, we'll need to create it
            $needsUpdate = true;
        } elseif (!isset($translations[$sourceText])) {
            // File exists but this string is missing
            $needsUpdate = true;
        }

        if ($needsUpdate) {
            // Add new entry
            $translations[$sourceText] = $sourceText;

            // Write back to file with pretty printing
            $jsonContent = json_encode($translations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

            if (file_put_contents($filePath, $jsonContent) !== false) {
                // Update cache
                $cacheKey = $aliasPrefix ? "{$aliasPrefix}:{$this->defaultLanguage}:{$sourceFile}" : "{$this->defaultLanguage}:{$sourceFile}";
                $this->loadedTranslations[$cacheKey] = $translations;
            }
        }
    }

    /**
     * Get the full path to a translation file with alias prefix support
     *
     * Uses PathsRegistry to find the first existing translation file, searching in priority order:
     * 1. Application translations (_translations/)
     * 2. Framework translations (vendor/fubber/mini/translations/)
     */
    private function getTranslationFilePath(string $languageCode, string $sourceFile, ?string $aliasPrefix = null): string
    {
        // Map 'en' (default language) to 'default' folder for backward compatibility
        $folderName = ($languageCode === 'en') ? 'default' : $languageCode;

        if ($aliasPrefix !== null) {
            // Aliased translation files go under {translations}/{lang}/{ALIAS}/{file}.json
            $relativePath = "{$folderName}/{$aliasPrefix}/{$sourceFile}.json";
        } else {
            // Regular app translations go under {translations}/{lang}/{file}.json
            $relativePath = "{$folderName}/{$sourceFile}.json";
        }

        // Try to find the file in registered paths
        $foundPath = $this->translationsPaths->findFirst($relativePath);

        // If not found, return the path where we would create it (first path in registry)
        if ($foundPath === null) {
            $paths = $this->translationsPaths->getPaths();
            return $paths[0] . '/' . $relativePath;
        }

        return $foundPath;
    }

    /**
     * Format message using ICU MessageFormatter
     */
    private function formatMessage(string $pattern, array $vars): string
    {
        try {
            $formatter = new \MessageFormatter(\Locale::getDefault(), $pattern);
            $result = $formatter->format($vars);

            if ($result === false) {
                return $pattern;
            }

            return $result;
        } catch (\Exception) {
            return $pattern;
        }
    }



    /**
     * Determine the alias prefix for a source file based on registered path aliases
     *
     * @param string $sourceFile Relative path from project root
     * @return string|null Alias prefix or null for application files
     */
    private function getAliasPrefix(string $sourceFile): ?string
    {
        $projectRoot = Mini::$mini->root;

        // Convert relative path to absolute for comparison
        $absoluteSourcePath = $projectRoot . '/' . ltrim($sourceFile, '/');

        // Check each path alias to see if the source file falls under it
        foreach ($this->pathAliases as $basePath => $alias) {
            if (str_starts_with($absoluteSourcePath, $basePath . '/')) {
                return $alias;
            }
        }

        return null; // No alias (application files)
    }

}