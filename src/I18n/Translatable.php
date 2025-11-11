<?php

namespace mini\I18n;

use mini\Mini;

/**
 * Translatable string class that implements Stringable
 *
 * Stores the source text, variables, and calling file context for future translation.
 * Currently acts as a pass-through, returning the original text with variable substitution.
 */
class Translatable implements \Stringable
{
    private string $sourceText;
    private array $vars;
    private string $sourceFile;

    public function __construct(string $text, array $vars = [])
    {
        $this->sourceText = $text;
        $this->vars = $vars;
        $this->sourceFile = $this->getCallingFile();
    }

    /**
     * Convert to string using Translator from container
     */
    public function __toString(): string
    {
        // Get translator from container if available, otherwise fallback to direct interpolation
        try {
            $translator = Mini::$mini->get(TranslatorInterface::class);
            return $translator->translate($this);
        } catch (\Exception $e) {
            // Fallback if translator is not available or throws error
        }

        // Fallback: direct interpolation (for backward compatibility)
        return $this->interpolateVariables($this->sourceText, $this->vars);
    }

    /**
     * Get the file that called t() using debug_backtrace()
     */
    private function getCallingFile(): string
    {
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);

        // Get project root from Mini singleton
        $projectRoot = Mini::$mini->root;

        // Find the first call that's not in this file or functions.php
        foreach ($backtrace as $frame) {
            if (isset($frame['file']) &&
                !str_ends_with($frame['file'], 'Translatable.php') &&
                !str_ends_with($frame['file'], 'functions.php')) {

                // Return relative path from project root
                $relativePath = str_replace($projectRoot . '/', '', $frame['file']);
                return $relativePath;
            }
        }

        return 'unknown';
    }

    /**
     * Replace {variable_name} placeholders with values from vars array
     */
    private function interpolateVariables(string $text, array $vars): string
    {
        if (empty($vars)) {
            return $text;
        }

        return preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', function ($matches) use ($vars) {
            $varName = $matches[1];
            return isset($vars[$varName]) ? (string) $vars[$varName] : $matches[0];
        }, $text);
    }

    /**
     * Get source text (for debugging/development)
     */
    public function getSourceText(): string
    {
        return $this->sourceText;
    }

    /**
     * Get source file (for debugging/development)
     */
    public function getSourceFile(): string
    {
        return $this->sourceFile;
    }

    /**
     * Get variables (for debugging/development)
     */
    public function getVars(): array
    {
        return $this->vars;
    }
}