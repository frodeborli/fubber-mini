<?php

namespace mini\Logger;

use mini\Mini;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * Built-in logger implementation that logs to PHP's error_log
 *
 * Uses MessageFormatter for string interpolation with the application's default locale.
 * Supports all PSR-3 log levels.
 */
class Logger extends AbstractLogger
{
    /**
     * Logs with an arbitrary level.
     *
     * @param mixed $level
     * @param string|\Stringable $message
     * @param array $context
     * @return void
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $message = (string) $message;

        // Interpolate message with context using MessageFormatter
        if (!empty($context)) {
            $interpolated = $this->interpolate($message, $context);
        } else {
            $interpolated = $message;
        }

        // Format log entry
        $formattedMessage = $this->formatLogEntry($level, $interpolated, $context);

        // Write to PHP error log
        error_log($formattedMessage);
    }

    /**
     * Interpolate message with context values using MessageFormatter
     *
     * @param string $message
     * @param array $context
     * @return string
     */
    private function interpolate(string $message, array $context): string
    {
        // Preprocess context values for MessageFormatter compatibility
        $processedContext = $this->preprocessContext($context);

        // Use application's default locale (not per-user locale)
        $locale = Mini::$mini->locale;

        // Try MessageFormatter, fall back to simple replacement on failure
        $result = msgfmt_format_message($locale, $message, $processedContext);

        return $result !== false
            ? $result
            : $this->simplePlaceholderReplacement($message, $context);
    }

    /**
     * Preprocess context values for MessageFormatter compatibility
     *
     * Converts non-scalar values to string representations with markdown-style formatting:
     * - null → `null`
     * - true/false → `true`/`false`
     * - arrays → JSON
     * - exceptions → skipped (handled separately)
     * - objects with __toString → string value
     * - other objects → `ClassName`
     *
     * @param array $context
     * @return array
     */
    private function preprocessContext(array $context): array
    {
        $processed = [];
        foreach ($context as $key => $value) {
            // Skip exception key - it's handled separately in formatLogEntry
            if ($key === 'exception' && $value instanceof \Throwable) {
                continue;
            }

            if (is_null($value)) {
                $processed[$key] = '`null`';
            } elseif (is_bool($value)) {
                $processed[$key] = $value ? '`true`' : '`false`';
            } elseif (is_scalar($value)) {
                $processed[$key] = $value;
            } elseif (is_array($value)) {
                $processed[$key] = '`' . json_encode($value) . '`';
            } elseif (is_object($value) && method_exists($value, '__toString')) {
                $processed[$key] = (string) $value;
            } elseif (is_object($value)) {
                $processed[$key] = '`' . get_class($value) . '`';
            } else {
                $processed[$key] = '`' . gettype($value) . '`';
            }
        }
        return $processed;
    }

    /**
     * Simple placeholder replacement for basic {key} patterns
     *
     * Uses preprocessContext for consistent value formatting.
     *
     * @param string $message
     * @param array $context
     * @return string
     */
    private function simplePlaceholderReplacement(string $message, array $context): string
    {
        $processed = $this->preprocessContext($context);
        $replace = [];
        foreach ($processed as $key => $value) {
            $replace['{' . $key . '}'] = (string) $value;
        }

        return strtr($message, $replace);
    }

    /**
     * Format a log entry with timestamp and level
     *
     * @param mixed $level
     * @param string $message
     * @param array $context
     * @return string
     */
    private function formatLogEntry(mixed $level, string $message, array $context): string
    {
        $timestamp = date('Y-m-d H:i:s');
        $levelStr = strtoupper((string) $level);

        $formatted = "[{$timestamp}] [{$levelStr}] {$message}";

        // Append exception details if present
        if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
            $exception = $context['exception'];
            $formatted .= "\n" . $this->formatException($exception);
        }

        return $formatted;
    }

    /**
     * Format exception for logging
     *
     * @param \Throwable $exception
     * @return string
     */
    private function formatException(\Throwable $exception): string
    {
        return sprintf(
            "Exception: %s\nMessage: %s\nFile: %s:%d\nTrace:\n%s",
            get_class($exception),
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            $exception->getTraceAsString()
        );
    }
}
