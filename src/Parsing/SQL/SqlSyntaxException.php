<?php

namespace mini\Parsing\SQL;

/**
 * SQL Syntax Exception with rich error reporting
 *
 * Provides line numbers and visual pointers to syntax errors
 */
class SqlSyntaxException extends \LogicException
{
    public function __construct(string $message, string $sql, int $pos)
    {
        [$lineNum, $colNum, $lineSnippet, $pointer] = $this->getDetailedContext($sql, $pos);

        $output = sprintf(
            "%s at line %d, column %d\n\n%s\n%s",
            $message,
            $lineNum,
            $colNum,
            $lineSnippet,
            $pointer
        );

        parent::__construct($output);
    }

    private function getDetailedContext(string $sql, int $pos): array
    {
        // Calculate line number by counting newlines up to $pos
        $prefix = substr($sql, 0, $pos);
        $lineNum = substr_count($prefix, "\n") + 1;

        // Calculate column number: position - last newline position
        $lastNewlinePos = strrpos($prefix, "\n");
        if ($lastNewlinePos === false) {
            $colNum = $pos + 1;
            $lineStart = 0;
        } else {
            $colNum = $pos - $lastNewlinePos;
            $lineStart = $lastNewlinePos + 1;
        }

        // Extract the specific line for display
        $lineEnd = strpos($sql, "\n", $lineStart);
        if ($lineEnd === false) {
            $lineEnd = strlen($sql);
        }

        // Trim \r if present (for Windows line endings)
        $lineSnippet = rtrim(substr($sql, $lineStart, $lineEnd - $lineStart), "\r");

        // Create the pointer string (e.g., "      ^")
        $colIndex = max(0, $colNum - 1);
        $pointer = str_repeat(' ', $colIndex) . '^';

        return [$lineNum, $colNum, $lineSnippet, $pointer];
    }
}
