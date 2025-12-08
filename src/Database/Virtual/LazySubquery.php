<?php

namespace mini\Database\Virtual;

use mini\Parsing\SQL\AST\SelectStatement;

/**
 * A lazy-evaluated subquery implementing ValueInterface
 *
 * Holds an unmaterialized subquery and a reference to execute it.
 * The subquery is only executed when values are actually needed.
 * Results are cached after first materialization.
 *
 * This enables future optimizations:
 * - Index-based containment checks (without full materialization)
 * - Streaming evaluation for large result sets
 * - Query pushdown to underlying databases
 */
class LazySubquery implements ValueInterface
{
    private ?array $materialized = null;

    /**
     * @param SelectStatement $query The parsed subquery AST
     * @param \Closure $executor Function to execute the query: fn(SelectStatement) => iterable
     * @param string $column The column name to extract from results
     */
    public function __construct(
        private SelectStatement $query,
        private \Closure $executor,
        private string $column
    ) {}

    public function contains(mixed $value): bool
    {
        // Future: could check indexes before materializing
        $values = $this->materialize();

        // Loose comparison to match SQL semantics
        return in_array($value, $values, false);
    }

    public function compareTo(mixed $value): int
    {
        $scalar = $this->getValue();
        return $scalar <=> $value;
    }

    public function getValue(): mixed
    {
        $values = $this->materialize();

        if (count($values) === 0) {
            throw new \RuntimeException("Subquery returned no rows (expected exactly 1 for scalar context)");
        }

        if (count($values) > 1) {
            throw new \RuntimeException("Subquery returned " . count($values) . " rows (expected exactly 1 for scalar context)");
        }

        return $values[0];
    }

    public function toArray(): array
    {
        return $this->materialize();
    }

    public function isScalar(): bool
    {
        return false;
    }

    /**
     * Materialize the subquery results (cached)
     */
    private function materialize(): array
    {
        if ($this->materialized !== null) {
            return $this->materialized;
        }

        $results = ($this->executor)($this->query);

        $this->materialized = [];
        foreach ($results as $row) {
            // Extract the specified column value
            if (array_key_exists($this->column, $row)) {
                $this->materialized[] = $row[$this->column];
            } else {
                // Fall back to first column if specific column not found
                $this->materialized[] = reset($row);
            }
        }

        return $this->materialized;
    }
}
