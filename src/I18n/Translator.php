<?php

namespace mini\I18n;

use mini\Mini;
use mini\Util\QueryParser;
use MessageFormatter;

/**
 * Translator class responsible for loading and managing translations
 *
 * Handles translation file loading, caching, and fallback logic.
 * Automatically creates missing translation entries in the default language files.
 */
class Translator
{
    private string $translationsPath;
    private string $defaultLanguage = 'default';
    private array $loadedTranslations = [];
    private bool $autoCreateDefaults;
    private array $namedScopes = [];

    public function __construct(string $translationsPath, bool $autoCreateDefaults = true)
    {
        $this->translationsPath = rtrim($translationsPath, '/');
        $this->autoCreateDefaults = $autoCreateDefaults;
    }

    /**
     * Get current language code from global locale
     */
    private function getCurrentLanguageCode(): string
    {
        return \Locale::getPrimaryLanguage(\Locale::getDefault());
    }


    /**
     * Check if a language is supported (available in filesystem)
     */
    private function isLanguageSupported(string $languageCode): bool
    {
        return in_array($languageCode, $this->getAvailableLanguages());
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

        // Determine scope for this source file
        $scope = $this->getScopeForSourceFile($sourceFile);

        // Try each language in the fallback chain
        foreach ($fallbackChain as $langCode) {
            $translation = $this->loadTranslationFromFile($langCode, $sourceFile, $sourceText, $vars, $scope);

            if ($translation !== null) {
                return $translation;
            }
        }

        // Auto-create entry in default language file if enabled
        if ($this->autoCreateDefaults) {
            $this->createDefaultTranslation($sourceFile, $sourceText, $scope);
        }

        // Final fallback: return original text
        return $sourceText;
    }

    /**
     * Load translation from a specific language file with scope support
     */
    private function loadTranslationFromFile(string $languageCode, string $sourceFile, string $sourceText, array $vars = [], ?string $scope = null): ?string
    {
        // For scoped files, try app-scoped translations first (allows app to override framework)
        if ($scope !== null) {
            $scopedTranslations = $this->getFileTranslations($languageCode, $sourceFile, $scope);
            $translation = $this->extractTranslation($scopedTranslations, $sourceText, $vars);
            if ($translation !== null) {
                return $translation;
            }

            // Fall back to package's own translations
            $packageTranslations = $this->getPackageTranslations($languageCode, $sourceFile, $scope);
            $translation = $this->extractTranslation($packageTranslations, $sourceText, $vars);
            if ($translation !== null) {
                return $translation;
            }
        } else {
            // Regular app files - use standard translation path
            $translations = $this->getFileTranslations($languageCode, $sourceFile);
            $translation = $this->extractTranslation($translations, $sourceText, $vars);
            if ($translation !== null) {
                return $translation;
            }
        }

        return null;
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
     * Get package's own translations (from the package's translation directory)
     */
    private function getPackageTranslations(string $languageCode, string $sourceFile, string $scope): array
    {
        $packagePath = $this->namedScopes[$scope] ?? null;
        if ($packagePath === null) {
            return [];
        }

        // Convert source file from project-relative to package-relative
        $projectRoot = Mini::$mini->root;
        $absoluteSourcePath = $projectRoot . '/' . ltrim($sourceFile, '/');

        if (!str_starts_with($absoluteSourcePath, $packagePath . '/')) {
            return [];
        }

        $relativeToPackage = substr($absoluteSourcePath, strlen($packagePath) + 1);
        $packageTranslationsPath = $packagePath . '/translations';

        // Map 'en' to 'default' for backward compatibility
        $folderName = ($languageCode === 'en') ? 'default' : $languageCode;
        $packageTranslationFile = $packageTranslationsPath . '/' . $folderName . '/' . $relativeToPackage . '.json';

        $cacheKey = "package:{$scope}:{$languageCode}:{$relativeToPackage}";

        if (isset($this->loadedTranslations[$cacheKey])) {
            return $this->loadedTranslations[$cacheKey];
        }

        if (!file_exists($packageTranslationFile)) {
            $this->loadedTranslations[$cacheKey] = [];
            return [];
        }

        $jsonContent = file_get_contents($packageTranslationFile);
        if ($jsonContent === false) {
            $this->loadedTranslations[$cacheKey] = [];
            return [];
        }

        $translations = json_decode($jsonContent, true);
        if (!is_array($translations)) {
            $this->loadedTranslations[$cacheKey] = [];
            return [];
        }

        $this->loadedTranslations[$cacheKey] = $translations;
        return $translations;
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
     * Get all translations for a specific language and file with scope support
     */
    private function getFileTranslations(string $languageCode, string $sourceFile, ?string $scope = null): array
    {
        $cacheKey = $scope ? "{$scope}:{$languageCode}:{$sourceFile}" : "{$languageCode}:{$sourceFile}";

        if (isset($this->loadedTranslations[$cacheKey])) {
            return $this->loadedTranslations[$cacheKey];
        }

        $filePath = $this->getTranslationFilePath($languageCode, $sourceFile, $scope);

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
     * Ensure default translation exists for a source text with scope support
     * Creates file if missing, adds string if not present in existing file
     */
    private function createDefaultTranslation(string $sourceFile, string $sourceText, ?string $scope = null): void
    {
        $filePath = $this->getTranslationFilePath($this->defaultLanguage, $sourceFile, $scope);

        // Ensure directory exists
        $directory = dirname($filePath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        // Check if file exists, if not create it
        $fileExists = file_exists($filePath);

        // Load existing translations (will be empty array if file doesn't exist)
        $translations = $this->getFileTranslations($this->defaultLanguage, $sourceFile, $scope);

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
                $cacheKey = $scope ? "{$scope}:{$this->defaultLanguage}:{$sourceFile}" : "{$this->defaultLanguage}:{$sourceFile}";
                $this->loadedTranslations[$cacheKey] = $translations;
            }
        }
    }

    /**
     * Get the full path to a translation file with scope support
     */
    private function getTranslationFilePath(string $languageCode, string $sourceFile, ?string $scope = null): string
    {
        // Map 'en' (default language) to 'default' folder for backward compatibility
        $folderName = ($languageCode === 'en') ? 'default' : $languageCode;

        if ($scope !== null) {
            // Scoped translation files go under {app}/translations/{SCOPE}/{lang}/{file}.json
            return "{$this->translationsPath}/{$scope}/{$folderName}/{$sourceFile}.json";
        } else {
            // Regular app translations go under {app}/translations/{lang}/{file}.json
            return "{$this->translationsPath}/{$folderName}/{$sourceFile}.json";
        }
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
     * Get current language code
     */
    public function getLanguageCode(): string
    {
        return $this->getCurrentLanguageCode();
    }

    /**
     * Set language code (useful for switching languages)
     * Updates the global locale via Locale::setDefault()
     */
    public function setLanguageCode(string $languageCode): void
    {
        // Construct full locale from language code (e.g., 'en' -> 'en_US', 'nb' -> 'nb_NO')
        $locale = \Locale::composeLocale(['language' => $languageCode]);
        \Locale::setDefault($locale);
    }

    /**
     * Try to set language code only if it's supported
     * Returns true if language was changed, false if not supported
     * Automatically persists successful language changes to session
     */
    public function trySetLanguageCode(string $languageCode): bool
    {
        if ($this->isLanguageSupported($languageCode)) {
            $this->setLanguageCode($languageCode);
            // No need to clear cache - translation files are language-agnostic keys

            // Start session and persist the language choice
            session();
            $_SESSION['language'] = $languageCode;

            return true;
        }

        return false;
    }

    /**
     * Clear translation cache (useful after language change)
     */
    public function clearCache(): void
    {
        $this->loadedTranslations = [];
    }

    /**
     * Add a named scope for translation file resolution
     *
     * @param string $scopeName Name of the scope (e.g., 'MINI-FRAMEWORK')
     * @param string $basePath Base path where the scoped source files are located
     */
    public function addNamedScope(string $scopeName, string $basePath): void
    {
        $this->namedScopes[$scopeName] = rtrim($basePath, '/');
    }

    /**
     * Determine which scope a source file belongs to based on its path
     */
    private function getScopeForSourceFile(string $sourceFile): ?string
    {
        $projectRoot = Mini::$mini->root;

        // Convert relative path to absolute for comparison
        $absoluteSourcePath = $projectRoot . '/' . ltrim($sourceFile, '/');

        // Check each named scope to see if the source file falls under it
        foreach ($this->namedScopes as $scopeName => $basePath) {
            if (str_starts_with($absoluteSourcePath, $basePath . '/')) {
                return $scopeName;
            }
        }

        return null; // Default scope (application files)
    }

    /**
     * Get translation statistics for a language
     */
    public function getTranslationStats(string $languageCode): array
    {
        $defaultStats = $this->getLanguageFileStats($this->defaultLanguage);
        $targetStats = $this->getLanguageFileStats($languageCode);

        $stats = [];
        foreach ($defaultStats as $file => $defaultCount) {
            $translatedCount = $targetStats[$file] ?? 0;
            $stats[$file] = [
                'total' => $defaultCount,
                'translated' => $translatedCount,
                'percentage' => $defaultCount > 0 ? round(($translatedCount / $defaultCount) * 100, 1) : 0
            ];
        }

        return $stats;
    }

    /**
     * Get all available languages (directories in translations folder)
     */
    public function getAvailableLanguages(): array
    {
        $languages = ['default']; // Always include default

        if (!is_dir($this->translationsPath)) {
            return $languages;
        }

        $iterator = new \DirectoryIterator($this->translationsPath);
        foreach ($iterator as $item) {
            if ($item->isDot() || !$item->isDir()) {
                continue;
            }

            $langCode = $item->getFilename();
            if ($langCode !== 'default') {
                $languages[] = $langCode;
            }
        }

        sort($languages);
        return $languages;
    }

    /**
     * Check if translation system is working correctly
     */
    public function healthCheck(): array
    {
        $health = [
            'translations_path_exists' => is_dir($this->translationsPath),
            'translations_path_writable' => is_writable($this->translationsPath),
            'default_language_path_exists' => is_dir($this->translationsPath . '/' . $this->defaultLanguage),
            'current_language_path_exists' => is_dir($this->translationsPath . '/' . $this->getCurrentLanguageCode()),
            'auto_create_enabled' => $this->autoCreateDefaults,
            'cache_entries' => count($this->loadedTranslations)
        ];

        $health['status'] = $health['translations_path_exists'] && $health['translations_path_writable'] ? 'healthy' : 'error';

        return $health;
    }

    /**
     * Get file statistics for a specific language
     */
    private function getLanguageFileStats(string $languageCode): array
    {
        $stats = [];
        $languagePath = "{$this->translationsPath}/{$languageCode}";

        if (!is_dir($languagePath)) {
            return $stats;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($languagePath)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'json') {
                $relativePath = str_replace($languagePath . '/', '', $file->getPathname());
                $relativePath = str_replace('.json', '', $relativePath);

                $translations = $this->getFileTranslations($languageCode, $relativePath);
                $stats[$relativePath] = count($translations);
            }
        }

        return $stats;
    }
}