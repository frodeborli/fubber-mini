<?php

namespace mini\CLI;

/**
 * Terminal output helper
 *
 * Provides utilities for structured terminal output including
 * markdown-style tables and formatted text.
 */
class TTY
{
    /**
     * Render data as a markdown-style table
     *
     * @param iterable $rows Rows of data (arrays or objects)
     * @param array|null $columns Column names (auto-detected from first row if null)
     * @return string Rendered table
     */
    public static function table(iterable $rows, ?array $columns = null): string
    {
        // Materialize if needed
        if (!is_array($rows)) {
            $rows = iterator_to_array($rows);
        }

        if (empty($rows)) {
            return "(empty result set)\n";
        }

        // Get columns from first row if not specified
        $first = $rows[0];
        $columns ??= array_keys((array)$first);

        // Calculate column widths
        $widths = [];
        foreach ($columns as $col) {
            $widths[$col] = strlen($col);
        }
        foreach ($rows as $row) {
            $row = (array)$row;
            foreach ($columns as $col) {
                $val = self::formatValue($row[$col] ?? null);
                $widths[$col] = max($widths[$col], strlen($val));
            }
        }

        // Build output
        $out = '';

        // Header
        $out .= '|';
        foreach ($columns as $col) {
            $out .= ' ' . str_pad($col, $widths[$col]) . ' |';
        }
        $out .= "\n";

        // Separator
        $out .= '|';
        foreach ($columns as $col) {
            $out .= str_repeat('-', $widths[$col] + 2) . '|';
        }
        $out .= "\n";

        // Rows
        foreach ($rows as $row) {
            $row = (array)$row;
            $out .= '|';
            foreach ($columns as $col) {
                $val = self::formatValue($row[$col] ?? null);
                $out .= ' ' . str_pad($val, $widths[$col]) . ' |';
            }
            $out .= "\n";
        }

        $out .= "\n" . count($rows) . " row(s)\n";

        return $out;
    }

    /**
     * Render data as CSV
     *
     * @param iterable $rows Rows of data (arrays or objects)
     * @param array|null $columns Column names (auto-detected from first row if null)
     * @param bool $header Include header row
     * @return string CSV output
     */
    public static function csv(iterable $rows, ?array $columns = null, bool $header = true): string
    {
        // Materialize if needed
        if (!is_array($rows)) {
            $rows = iterator_to_array($rows);
        }

        if (empty($rows)) {
            return "";
        }

        // Get columns from first row if not specified
        $first = $rows[0];
        $columns ??= array_keys((array)$first);

        $out = fopen('php://memory', 'r+');

        // Header
        if ($header) {
            fputcsv($out, $columns);
        }

        // Rows
        foreach ($rows as $row) {
            $row = (array)$row;
            $values = [];
            foreach ($columns as $col) {
                $values[] = self::formatValueRaw($row[$col] ?? null);
            }
            fputcsv($out, $values);
        }

        rewind($out);
        $result = stream_get_contents($out);
        fclose($out);

        return $result;
    }

    /**
     * Render data as JSON
     *
     * @param iterable $rows Rows of data (arrays or objects)
     * @param bool $pretty Pretty-print with indentation
     * @return string JSON output
     */
    public static function json(iterable $rows, bool $pretty = false): string
    {
        // Materialize if needed
        if (!is_array($rows)) {
            $rows = iterator_to_array($rows);
        }

        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        return json_encode(array_values($rows), $flags) . "\n";
    }

    /**
     * Render data as a line-based format (one value per line, for single column results)
     *
     * @param iterable $rows Rows of data
     * @return string Line output
     */
    public static function line(iterable $rows): string
    {
        $out = '';
        foreach ($rows as $row) {
            $row = (array)$row;
            $values = array_values($row);
            $out .= self::formatValueRaw($values[0] ?? null) . "\n";
        }
        return $out;
    }

    /**
     * Format a value for display (with NULL indicator)
     */
    public static function formatValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value) || is_object($value)) {
            return json_encode($value);
        }
        return (string)$value;
    }

    /**
     * Format a value for raw output (empty string for null)
     */
    public static function formatValueRaw(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_array($value) || is_object($value)) {
            return json_encode($value);
        }
        return (string)$value;
    }

    /**
     * Check if stdout is a TTY (interactive terminal)
     */
    public static function isInteractive(): bool
    {
        return function_exists('posix_isatty') && posix_isatty(STDOUT);
    }

    /**
     * Get terminal width
     */
    public static function width(): int
    {
        if (getenv('COLUMNS')) {
            return (int)getenv('COLUMNS');
        }
        if (function_exists('exec')) {
            $output = [];
            @exec('tput cols 2>/dev/null', $output);
            if (!empty($output[0]) && is_numeric($output[0])) {
                return (int)$output[0];
            }
        }
        return 80;
    }
}
