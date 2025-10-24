<?php

namespace mini\Util;

/**
 * StringInterpolator - Advanced string interpolation with filter support
 *
 * Supports variable replacement with chained filters:
 * - {variable} - Simple replacement
 * - {variable:filter} - Apply single filter
 * - {variable:filter1:filter2} - Chain multiple filters
 *
 * Filter handlers can be registered to process specific filter names.
 * Handlers return the processed value or null to pass to the next handler.
 *
 * Usage:
 * $si = new StringInterpolator();
 * $si->addFilterHandler(function($value, $filterName) {
 *     if ($filterName === 'upper') return strtoupper($value);
 *     return null; // Pass to next handler
 * });
 * $result = $si->interpolate("Hello {name:upper}!", ['name' => 'world']);
 */
class StringInterpolator
{
    /**
     * @var callable[] Registered filter handlers
     */
    private array $filterHandlers = [];

    /**
     * Add a filter handler function
     *
     * @param callable $handler Function with signature: (mixed $value, string $filterName): ?string
     */
    public function addFilterHandler(callable $handler): void
    {
        $this->filterHandlers[] = $handler;
    }

    /**
     * Interpolate variables and apply filters in a string
     *
     * Supports escape sequences for literal braces:
     * - {{variable}} outputs {variable} (double braces)
     * - \{variable} outputs {variable} (backslash escape)
     *
     * @param string $string Template string with {variable} or {variable:filter} syntax
     * @param array $values Key-value pairs for variable replacement
     * @return string Interpolated string with variables replaced and filters applied
     */
    public function interpolate(string $string, array $values): string
    {
        // Match all potential brace patterns and handle them programmatically
        return preg_replace_callback('/[\{\\\\]?\{[^}]*\}?\}?/', function ($matches) use ($values) {
            return $this->processBraceMatch($matches[0], $values);
        }, $string);
    }

    /**
     * Process a single variable with optional filter chain
     *
     * @param string $variableSpec Variable specification (e.g., "name" or "count:ordinal:upper")
     * @param array $values Available values for interpolation
     * @return string Processed value or error message
     */
    private function processVariable(string $variableSpec, array $values): string
    {
        $parts = explode(':', $variableSpec);
        $variableName = array_shift($parts);
        $filters = $parts;

        // Check if variable exists
        if (!array_key_exists($variableName, $values)) {
            return "[missing variable '$variableName']";
        }

        $value = $values[$variableName];

        // Apply filters sequentially
        foreach ($filters as $filterName) {
            $value = $this->applyFilter($value, $filterName);
        }

        return (string) $value;
    }

    /**
     * Apply a single filter to a value
     *
     * @param mixed $value Value to filter
     * @param string $filterName Name of the filter to apply
     * @return mixed Filtered value or error message
     */
    private function applyFilter($value, string $filterName)
    {
        foreach ($this->filterHandlers as $handler) {
            $result = $handler($value, $filterName);

            // If handler processed the filter (returned non-null), use its result
            if ($result !== null) {
                return $result;
            }
        }

        // No handler could process this filter
        return "[unknown filter '$filterName']";
    }

    /**
     * Process a matched brace pattern programmatically
     *
     * @param string $match The matched brace pattern
     * @param array $values Available values for interpolation
     * @return string Processed result
     */
    private function processBraceMatch(string $match, array $values): string
    {
        // Handle double braces: {{anything}} -> {anything}
        if (preg_match('/^\{\{([^{}]*)\}\}$/', $match, $matches)) {
            return '{' . $matches[1] . '}';
        }

        // Handle backslash escapes: \{anything} -> {anything}
        if (preg_match('/^\\\\(\{[^}]*\})$/', $match, $matches)) {
            return $matches[1];
        }

        // Handle normal variables: {variable} or {variable:filter}
        if (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*(?::[a-zA-Z_][a-zA-Z0-9_]*)*)\}$/', $match, $matches)) {
            return $this->processVariable($matches[1], $values);
        }

        // If it doesn't match any pattern, return as-is
        return $match;
    }
}