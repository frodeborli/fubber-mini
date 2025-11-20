#!/usr/bin/env php
<?php

/**
 * Translation Management Tool
 *
 * Manages translation files for Mini applications:
 * - Scans PHP files for t() function calls
 * - Validates translation files
 * - Adds missing translations
 * - Removes orphaned translations
 * - Manages language files
 */

class TranslationManager
{
    private array $results = [];
    private array $stats = [];
    private array $excludedDirs = ['vendor', 'node_modules', '.git', '.svn'];

    public function searchDirectory(string $directory): array
    {
        $this->results = [];
        $this->stats = [
            'files_scanned' => 0,
            'files_with_t_calls' => 0,
            'total_t_calls' => 0,
            'unique_strings' => 0
        ];

        $this->scanDirectory($directory);
        $this->stats['unique_strings'] = count($this->getUniqueStrings());

        return $this->results;
    }

    public function setExcludedDirectories(array $excludedDirs): void
    {
        $this->excludedDirs = $excludedDirs;
    }

    private function scanDirectory(string $directory): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
                function($file, $key, $iterator) {
                    // Skip excluded directories
                    if ($file->isDir()) {
                        $dirname = $file->getFilename();
                        return !in_array($dirname, $this->excludedDirs);
                    }
                    return true;
                }
            )
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $this->scanFile($file->getRealPath());
            }
        }
    }

    private function scanFile(string $filePath): void
    {
        $this->stats['files_scanned']++;

        $content = file_get_contents($filePath);
        if ($content === false) {
            return;
        }

        $tokens = token_get_all($content);
        $tCalls = $this->findTCalls($tokens, $filePath);

        if (!empty($tCalls)) {
            $this->stats['files_with_t_calls']++;
            $this->stats['total_t_calls'] += count($tCalls);

            // Convert to relative path and store just the string literals
            $relativePath = $this->getRelativePath($filePath);
            $this->results[$relativePath] = array_map(function($call) {
                return $call['string_literal'];
            }, array_filter($tCalls, function($call) {
                return $call['string_literal'] !== null;
            }));
        }
    }

    private function getRelativePath(string $filePath): string
    {
        $cwd = getcwd();
        if (str_starts_with($filePath, $cwd . '/')) {
            return substr($filePath, strlen($cwd) + 1);
        }
        return $filePath;
    }

    private function findTCalls(array $tokens, string $filePath): array
    {
        $calls = [];
        $tokenCount = count($tokens);

        for ($i = 0; $i < $tokenCount; $i++) {
            $token = $tokens[$i];

            // Look for function calls to 't'
            if (is_array($token) && $token[0] === T_STRING && $token[1] === 't') {
                // Check if next non-whitespace token is opening parenthesis
                $nextToken = $this->getNextNonWhitespaceToken($tokens, $i);
                if ($nextToken && $nextToken === '(') {
                    // Found t() call, now extract the arguments
                    $call = $this->extractTCallArguments($tokens, $i, $filePath);
                    if ($call) {
                        $calls[] = $call;
                    }
                }
            }
        }

        return $calls;
    }

    private function getNextNonWhitespaceToken(array $tokens, int $startIndex): mixed
    {
        for ($i = $startIndex + 1; $i < count($tokens); $i++) {
            $token = $tokens[$i];
            if (is_array($token)) {
                if ($token[0] !== T_WHITESPACE && $token[0] !== T_COMMENT && $token[0] !== T_DOC_COMMENT) {
                    return $token;
                }
            } else {
                return $token;
            }
        }
        return null;
    }

    private function extractTCallArguments(array $tokens, int $startIndex, string $filePath): ?array
    {
        $line = is_array($tokens[$startIndex]) ? $tokens[$startIndex][2] : 1;

        // Find the opening parenthesis
        $openParenIndex = null;
        for ($i = $startIndex + 1; $i < count($tokens); $i++) {
            if ($tokens[$i] === '(') {
                $openParenIndex = $i;
                break;
            }
            if (is_array($tokens[$i]) && !in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                break; // Not a function call
            }
        }

        if ($openParenIndex === null) {
            return null;
        }

        // Extract arguments until closing parenthesis
        $parenLevel = 1;
        $argTokens = [];

        for ($i = $openParenIndex + 1; $i < count($tokens) && $parenLevel > 0; $i++) {
            $token = $tokens[$i];

            if ($token === '(') {
                $parenLevel++;
                $argTokens[] = $token;
            } elseif ($token === ')') {
                $parenLevel--;
                if ($parenLevel > 0) {
                    $argTokens[] = $token;
                }
            } else {
                $argTokens[] = $token;
            }
        }

        // Parse the first argument (the string literal)
        $stringLiteral = $this->extractStringLiteral($argTokens);

        return [
            'line' => $line,
            'string_literal' => $stringLiteral,
            'raw_args' => $this->tokensToString($argTokens),
            'context' => $this->getContextAroundLine($filePath, $line)
        ];
    }

    private function extractStringLiteral(array $tokens): ?string
    {
        foreach ($tokens as $token) {
            if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE])) {
                $string = $token[1];

                // Double-quoted string - process escape sequences
                if (str_starts_with($string, '"') && str_ends_with($string, '"')) {
                    $inner = substr($string, 1, -1);
                    // Decode common escape sequences
                    return str_replace(
                        ['\\\\', '\\"', '\\n', '\\r', '\\t', '\\$'],
                        ['\\', '"', "\n", "\r", "\t", '$'],
                        $inner
                    );
                }

                // Single-quoted string - only \\ and \' are escape sequences
                if (str_starts_with($string, "'") && str_ends_with($string, "'")) {
                    $inner = substr($string, 1, -1);
                    return str_replace(['\\\\', "\\'"], ['\\', "'"], $inner);
                }

                return $string;
            }
        }
        return null;
    }

    private function tokensToString(array $tokens): string
    {
        $result = '';
        foreach ($tokens as $token) {
            if (is_array($token)) {
                $result .= $token[1];
            } else {
                $result .= $token;
            }
        }
        return trim($result);
    }

    private function getContextAroundLine(string $filePath, int $lineNumber, int $contextLines = 2): array
    {
        $lines = file($filePath, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return [];
        }

        $start = max(0, $lineNumber - $contextLines - 1);
        $end = min(count($lines), $lineNumber + $contextLines);

        $context = [];
        for ($i = $start; $i < $end; $i++) {
            $context[$i + 1] = $lines[$i];
        }

        return $context;
    }

    public function getUniqueStrings(): array
    {
        $strings = [];
        foreach ($this->results as $fileStrings) {
            foreach ($fileStrings as $string) {
                $strings[$string] = true;
            }
        }
        return array_keys($strings);
    }

    public function outputResults(array $data, string $format = 'text'): void
    {
        switch ($format) {
            case 'json':
                echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
                break;
            case 'csv':
                $this->outputCsv($data);
                break;
            case 'validate':
                $this->validateTranslations($data, './_translations');
                break;
            default:
                $this->outputText($data);
                break;
        }
    }

    public function validateTranslations(array $sourceData, string $translationsDir = './_translations'): void
    {
        $defaultDir = $translationsDir . '/default';
        $issues = [];

        foreach ($sourceData as $filePath => $strings) {
            $translationFile = $defaultDir . '/' . $filePath . '.json';

            // Check if translation file exists
            if (!file_exists($translationFile)) {
                echo "$filePath: no translation file found\n";
                continue;
            }

            // Load translation file
            $translationContent = file_get_contents($translationFile);
            if ($translationContent === false) {
                echo "$filePath: could not read translation file\n";
                continue;
            }

            $translations = json_decode($translationContent, true);
            if ($translations === null) {
                echo "$filePath: invalid JSON in translation file\n";
                continue;
            }

            // Check for missing strings
            foreach ($strings as $string) {
                if (!array_key_exists($string, $translations)) {
                    echo "$filePath: missing string: \"$string\"\n";
                }
            }

            // Check for orphaned strings
            foreach (array_keys($translations) as $translatedString) {
                if (!in_array($translatedString, $strings)) {
                    echo "$filePath: orphaned string: \"$translatedString\"\n";
                }
            }
        }

        // Check for orphaned translation files (files that don't have corresponding source files)
        if (is_dir($defaultDir)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($defaultDir, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->getExtension() === 'json') {
                    $fullPath = $file->getRealPath();
                    $defaultDirReal = realpath($defaultDir);

                    // Get path relative to _translations/default/
                    $relativePath = str_replace($defaultDirReal . '/', '', $fullPath);
                    $sourceFile = str_replace('.json', '', $relativePath);

                    if (!array_key_exists($sourceFile, $sourceData)) {
                        echo "$sourceFile: orphaned translation file (no source file found)\n";
                    }
                }
            }
        }
    }

    public function addMissingTranslations(array $sourceData, string $translationsDir = './_translations'): void
    {
        $defaultDir = $translationsDir . '/default';
        $added = 0;

        foreach ($sourceData as $filePath => $strings) {
            $translationFile = $defaultDir . '/' . $filePath . '.json';

            if (!file_exists($translationFile)) {
                // Create new translation file
                if (!is_dir(dirname($translationFile))) {
                    mkdir(dirname($translationFile), 0755, true);
                }

                $translations = [];
                foreach ($strings as $string) {
                    $translations[$string] = $string;
                }

                file_put_contents($translationFile, json_encode($translations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
                echo "Created: $filePath.json (" . count($strings) . " strings)\n";
                $added += count($strings);
                continue;
            }

            // Load existing translation file
            $translationContent = file_get_contents($translationFile);
            if ($translationContent === false) {
                echo "Error: Could not read $translationFile\n";
                continue;
            }

            $translations = json_decode($translationContent, true);
            if ($translations === null) {
                echo "Error: Invalid JSON in $translationFile\n";
                continue;
            }

            // Add missing strings
            $addedToFile = 0;
            foreach ($strings as $string) {
                if (!array_key_exists($string, $translations)) {
                    $translations[$string] = $string;
                    $addedToFile++;
                }
            }

            if ($addedToFile > 0) {
                file_put_contents($translationFile, json_encode($translations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
                echo "Updated: $filePath.json (+$addedToFile strings)\n";
                $added += $addedToFile;
            }
        }

        if ($added > 0) {
            echo "\nTotal: Added $added translation strings\n";
        } else {
            echo "No missing translations found\n";
        }
    }

    public function removeOrphanedTranslations(array $sourceData, string $translationsDir = './_translations'): void
    {
        $defaultDir = $translationsDir . '/default';
        $removed = 0;

        foreach ($sourceData as $filePath => $strings) {
            $translationFile = $defaultDir . '/' . $filePath . '.json';

            if (!file_exists($translationFile)) {
                continue;
            }

            // Load translation file
            $translationContent = file_get_contents($translationFile);
            if ($translationContent === false) {
                continue;
            }

            $translations = json_decode($translationContent, true);
            if ($translations === null) {
                continue;
            }

            // Remove orphaned strings
            $removedFromFile = 0;
            foreach (array_keys($translations) as $translatedString) {
                if (!in_array($translatedString, $strings)) {
                    unset($translations[$translatedString]);
                    $removedFromFile++;
                }
            }

            if ($removedFromFile > 0) {
                file_put_contents($translationFile, json_encode($translations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
                echo "Updated: $filePath.json (-$removedFromFile strings)\n";
                $removed += $removedFromFile;
            }
        }

        // Remove orphaned translation files
        if (is_dir($defaultDir)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($defaultDir, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->getExtension() === 'json') {
                    $fullPath = $file->getRealPath();
                    $defaultDirReal = realpath($defaultDir);

                    $relativePath = str_replace($defaultDirReal . '/', '', $fullPath);
                    $sourceFile = str_replace('.json', '', $relativePath);

                    if (!array_key_exists($sourceFile, $sourceData)) {
                        unlink($fullPath);
                        echo "Removed: $sourceFile.json (orphaned file)\n";
                        $removed++;
                    }
                }
            }
        }

        if ($removed > 0) {
            echo "\nTotal: Removed $removed items\n";
        } else {
            echo "No orphaned translations found\n";
        }
    }

    public function addLanguage(string $language, array $sourceData, string $translationsDir = './_translations'): void
    {
        $defaultDir = $translationsDir . '/default';
        $langDir = "$translationsDir/$language";

        if (!is_dir($defaultDir)) {
            echo "Error: Default translations directory not found\n";
            exit(1);
        }

        if (is_dir($langDir)) {
            echo "Error: Language directory '$langDir' already exists\n";
            exit(1);
        }

        mkdir($langDir, 0755, true);
        $created = 0;

        foreach ($sourceData as $filePath => $strings) {
            $defaultFile = $defaultDir . '/' . $filePath . '.json';
            $langFile = $langDir . '/' . $filePath . '.json';

            if (!file_exists($defaultFile)) {
                continue;
            }

            // Create directory if needed
            if (!is_dir(dirname($langFile))) {
                mkdir(dirname($langFile), 0755, true);
            }

            // Load default translations
            $defaultContent = file_get_contents($defaultFile);
            $defaultTranslations = json_decode($defaultContent, true);

            if ($defaultTranslations === null) {
                continue;
            }

            // Create language file with same keys but default values for fallback
            $langTranslations = [];
            foreach ($defaultTranslations as $key => $value) {
                $langTranslations[$key] = null; // null means fall back to default
            }

            file_put_contents($langFile, json_encode($langTranslations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
            echo "Created: $language/$filePath.json (" . count($langTranslations) . " strings)\n";
            $created++;
        }

        echo "\nTotal: Created $created translation files for language '$language'\n";
    }

    public function updateLanguage(string $language, array $sourceData, string $translationsDir = './_translations'): void
    {
        $defaultDir = $translationsDir . '/default';
        $langDir = "$translationsDir/$language";

        if (!is_dir($defaultDir)) {
            echo "Error: Default translations directory not found\n";
            exit(1);
        }

        if (!is_dir($langDir)) {
            echo "Error: Language directory '$langDir' does not exist\n";
            exit(1);
        }

        $updated = 0;
        $totalAdded = 0;
        $totalRemoved = 0;

        foreach ($sourceData as $filePath => $strings) {
            $defaultFile = $defaultDir . '/' . $filePath . '.json';
            $langFile = $langDir . '/' . $filePath . '.json';

            if (!file_exists($defaultFile)) {
                continue;
            }

            // Load default translations
            $defaultContent = file_get_contents($defaultFile);
            $defaultTranslations = json_decode($defaultContent, true);

            if ($defaultTranslations === null) {
                continue;
            }

            // Load or create language translations
            $langTranslations = [];
            if (file_exists($langFile)) {
                $langContent = file_get_contents($langFile);
                $langTranslations = json_decode($langContent, true) ?: [];
            } else {
                // Create directory if needed
                if (!is_dir(dirname($langFile))) {
                    mkdir(dirname($langFile), 0755, true);
                }
            }

            $added = 0;
            $removed = 0;
            $changed = false;

            // Add missing translations
            foreach ($defaultTranslations as $key => $value) {
                if (!array_key_exists($key, $langTranslations)) {
                    $langTranslations[$key] = null; // null means fall back to default
                    $added++;
                    $changed = true;
                }
            }

            // Remove orphaned translations
            foreach (array_keys($langTranslations) as $key) {
                if (!array_key_exists($key, $defaultTranslations)) {
                    unset($langTranslations[$key]);
                    $removed++;
                    $changed = true;
                }
            }

            if ($changed) {
                file_put_contents($langFile, json_encode($langTranslations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
                $status = [];
                if ($added > 0) $status[] = "+$added";
                if ($removed > 0) $status[] = "-$removed";
                echo "Updated: $language/$filePath.json (" . implode(', ', $status) . " strings)\n";
                $updated++;
                $totalAdded += $added;
                $totalRemoved += $removed;
            }
        }

        if ($updated > 0) {
            echo "\nTotal: Updated $updated files (+$totalAdded, -$totalRemoved strings)\n";
        } else {
            echo "No updates needed for language '$language'\n";
        }
    }

    private function outputText(array $results): void
    {
        echo var_export($results, true) . "\n";
    }

    private function outputCsv(array $results): void
    {
        echo "File,String\n";

        foreach ($results as $filePath => $strings) {
            foreach ($strings as $string) {
                echo sprintf(
                    "%s,\"%s\"\n",
                    $filePath,
                    addslashes($string)
                );
            }
        }
    }
}

// CLI usage
function showUsage(): void
{
    echo "Translation Management Tool\n";
    echo "\n";
    echo "Usage: vendor/bin/mini translations [action] [options]\n";
    echo "\n";
    echo "Actions:\n";
    echo "  (none)                    Show current translation status (default)\n";
    echo "  add-missing              Add missing translation strings to default files\n";
    echo "  remove-orphans           Remove orphaned strings from default files\n";
    echo "  add-language LANG        Create new language translation files\n";
    echo "  update-language LANG     Update language files with missing/orphaned strings\n";
    echo "\n";
    echo "Options:\n";
    echo "  --format=FORMAT          Output format: text, json, csv (for status)\n";
    echo "  --exclude=DIRS           Comma-separated list of directories to exclude\n";
    echo "                           (default: vendor,node_modules,.git,.svn)\n";
    echo "  --dir=PATH               Translations directory (default: ./_translations)\n";
    echo "                           Use 'translations' for framework translations\n";
    echo "  --help                   Show this help message\n";
    echo "\n";
    echo "Examples:\n";
    echo "  vendor/bin/mini translations                         # Show translation status\n";
    echo "  vendor/bin/mini translations add-missing             # Add missing strings\n";
    echo "  vendor/bin/mini translations remove-orphans          # Remove orphaned strings\n";
    echo "  vendor/bin/mini translations --exclude=vendor,tests  # Custom exclusions\n";
    echo "  vendor/bin/mini translations add-language es         # Create Spanish translations\n";
    echo "  vendor/bin/mini translations update-language nb      # Update Norwegian translations\n";
}

// Parse command line arguments
$action = null;
$language = null;
$format = 'validate';
$directory = '.';
$translationsDir = './_translations';
$excludedDirs = ['vendor', 'node_modules', '.git', '.svn']; // Default exclusions

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];

    if ($arg === '--help') {
        showUsage();
        exit(0);
    } elseif (str_starts_with($arg, '--format=')) {
        $format = substr($arg, 9);
    } elseif (str_starts_with($arg, '--exclude=')) {
        $excludedDirs = explode(',', substr($arg, 10));
    } elseif (str_starts_with($arg, '--dir=')) {
        $translationsDir = substr($arg, 6);
    } elseif ($action === null && !str_starts_with($arg, '--')) {
        $action = $arg;
    } elseif ($action === 'add-language' || $action === 'update-language') {
        $language = $arg;
    }
}

// Validate format
if (!in_array($format, ['text', 'json', 'csv', 'validate'])) {
    echo "Error: Invalid format '$format'. Valid formats: text, json, csv, validate\n";
    exit(1);
}

// Validate directory
if (!is_dir($directory)) {
    echo "Error: Directory '$directory' does not exist\n";
    exit(1);
}

// Validate language actions
if (($action === 'add-language' || $action === 'update-language') && !$language) {
    echo "Error: Language code required for action '$action'\n";
    showUsage();
    exit(1);
}

// Run the manager
$manager = new TranslationManager();
$manager->setExcludedDirectories($excludedDirs);
$data = $manager->searchDirectory($directory);

switch ($action) {
    case 'add-missing':
        $manager->addMissingTranslations($data, $translationsDir);
        break;
    case 'remove-orphans':
        $manager->removeOrphanedTranslations($data, $translationsDir);
        break;
    case 'add-language':
        $manager->addLanguage($language, $data, $translationsDir);
        break;
    case 'update-language':
        $manager->updateLanguage($language, $data, $translationsDir);
        break;
    default:
        // Default action: show status (with validation)
        if ($format === 'validate') {
            $manager->validateTranslations($data, $translationsDir);
        } else {
            $manager->outputResults($data, $format);
        }
        break;
}