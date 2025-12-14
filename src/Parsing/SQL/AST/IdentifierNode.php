<?php

namespace mini\Parsing\SQL\AST;

/**
 * Identifier node (table names, column names)
 *
 * Supports qualified names like schema.table.column or `quoted.name`.col
 * Parts are stored separately to preserve quoting semantics.
 */
class IdentifierNode extends ASTNode
{
    public string $type = 'IDENTIFIER';

    /** @var string[] Individual identifier parts (e.g., ['schema', 'table', 'column']) */
    public array $parts;

    /**
     * @param string|string[] $name Single name, dotted string, or array of parts
     */
    public function __construct(string|array $name)
    {
        if (is_array($name)) {
            $this->parts = $name;
        } else {
            // Simple case: treat as single part (no splitting on dots)
            // Dotted strings from legacy code are preserved as-is
            $this->parts = [$name];
        }
    }

    /**
     * Get the full qualified name (for display/debugging)
     */
    public function getFullName(): string
    {
        return implode('.', $this->parts);
    }

    /**
     * Get just the final identifier (column name in table.column)
     */
    public function getName(): string
    {
        return end($this->parts);
    }

    /**
     * Get the qualifier parts (everything except the last)
     * @return string[]
     */
    public function getQualifier(): array
    {
        return array_slice($this->parts, 0, -1);
    }

    /**
     * Check if this is a qualified name (has multiple parts)
     */
    public function isQualified(): bool
    {
        return count($this->parts) > 1;
    }

    /**
     * Check if this represents a wildcard (*)
     */
    public function isWildcard(): bool
    {
        return $this->getName() === '*';
    }
}
