<?php

namespace mini\Table\Wrappers;

use mini\Table\AbstractTable;
use mini\Table\ColumnDef;
use mini\Table\Contracts\TableInterface;
use mini\Table\Index\TreapIndex;
use mini\Table\InMemoryTable;
use mini\Table\Types\IndexType;
use Traversable;

/**
 * Adaptive optimization wrapper that measures and improves table access patterns
 *
 * Wraps any table and transparently optimizes repeated operations:
 * - Builds TreapIndex for frequently accessed columns
 * - Escalates to SQLite (InMemoryTable) for large datasets
 * - Measures actual performance to make decisions
 *
 * Use hints when caller knows access patterns upfront:
 * ```php
 * $optimized = OptimizingTable::from($table)
 *     ->withExpectedHasCalls(10000)
 *     ->withIndexOn('user_id', 'order_id');
 * ```
 */
class OptimizingTable extends AbstractTableWrapper
{
    // Thresholds
    private const MEASURE_CALLS = 3;           // Minimum calls before deciding
    private const INDEX_BENEFIT_THRESHOLD_MS = 50.0;  // Build index if saves >50ms total
    private const SQLITE_ROW_THRESHOLD = 500_000;
    private const SQLITE_TIME_THRESHOLD = 2.0; // >2s estimated = use SQLite

    // Hints
    private int $expectedHasCalls = 0;
    private int $expectedEqCalls = 0;
    private array $indexColumns = [];

    // Measurement state
    private int $hasCallCount = 0;
    private float $hasTotalTime = 0;
    private array $eqCallCounts = [];   // column => count
    private array $eqTotalTimes = [];   // column => total time

    // Optimization structures
    private ?TreapIndex $hasIndex = null;      // Full-row index for has()
    private array $columnIndexes = [];          // column => TreapIndex
    private ?InMemoryTable $materialized = null;

    // Strategy: 'measure', 'indexed', 'sqlite'
    private string $hasStrategy = 'measure';
    private array $eqStrategies = [];  // column => strategy

    // Cache for index building
    private ?array $rowCache = null;

    /**
     * Wrap a table for optimization, or return if already wrapped
     */
    public static function from(TableInterface $table): self
    {
        if ($table instanceof self) {
            return $table;
        }
        if (!$table instanceof AbstractTable) {
            throw new \InvalidArgumentException(
                'OptimizingTable requires AbstractTable, got ' . get_class($table)
            );
        }
        return new self($table);
    }

    /**
     * Hint: expected number of has() calls
     *
     * Used after measurement to estimate total time and decide on optimization.
     * Does NOT prebuild - always measure first, then decide.
     */
    public function withExpectedHasCalls(int $n): self
    {
        $c = clone $this;
        $c->expectedHasCalls = $n;
        return $c;
    }

    /**
     * Hint: expected number of eq() calls
     *
     * Used after measurement to estimate total time and decide on optimization.
     */
    public function withExpectedEqCalls(int $n): self
    {
        $c = clone $this;
        $c->expectedEqCalls = $n;
        return $c;
    }

    /**
     * Hint: columns that will be used for eq() lookups
     *
     * Noted for potential indexing, but only built after measurement shows need.
     */
    public function withIndexOn(string ...$columns): self
    {
        $c = clone $this;
        $c->indexColumns = array_unique([...$c->indexColumns, ...$columns]);
        return $c;
    }

    // =========================================================================
    // has() optimization
    // =========================================================================

    public function has(object $member): bool
    {
        return match ($this->hasStrategy) {
            'measure' => $this->measureHas($member),
            'indexed' => $this->indexedHas($member),
            'sqlite' => $this->materialized->has($member),
            default => parent::has($member),
        };
    }

    private function measureHas(object $member): bool
    {
        $this->hasCallCount++;
        $t = microtime(true);
        $result = parent::has($member);
        $elapsed = microtime(true) - $t;
        $this->hasTotalTime += $elapsed;

        // Check if we should build index (after minimum measurements)
        if ($this->hasCallCount >= self::MEASURE_CALLS && $this->expectedHasCalls > 0) {
            $avgTime = $this->hasTotalTime / $this->hasCallCount;
            $remainingCalls = $this->expectedHasCalls - $this->hasCallCount;
            $estimatedRemainingMs = $remainingCalls * $avgTime * 1000;

            // Build index if it would save significant time
            // Index build cost is roughly O(n) iteration, which we estimate from current measurements
            if ($estimatedRemainingMs > self::INDEX_BENEFIT_THRESHOLD_MS) {
                $this->buildHasOptimization();
            }
        }

        return $result;
    }

    private function indexedHas(object $member): bool
    {
        // Build key from all columns
        $key = $this->buildRowKey($member);
        return $this->hasIndex->has($key);
    }

    private function buildHasOptimization(): void
    {
        // For has(), TreapIndex is a hash on all columns - always efficient
        // Only use SQLite if TreapIndex would be too large for PHP memory
        $this->prebuildHasIndex();
    }

    private function prebuildHasIndex(): void
    {
        $columns = array_keys($this->source->getColumns());

        $this->hasIndex = TreapIndex::fromGenerator(function () use ($columns) {
            foreach ($this->getAllRows() as $id => $row) {
                yield [$this->buildRowKey($row), $id];
            }
        });

        // Check if too large for PHP
        if ($this->hasIndex->rowCount() > self::SQLITE_ROW_THRESHOLD) {
            $this->materializeToSqlite();
            return;
        }

        $this->hasStrategy = 'indexed';
    }

    private function buildRowKey(object $row): string
    {
        $parts = [];
        foreach ($this->source->getColumns() as $col => $_) {
            $parts[] = $row->$col ?? "\0";
        }
        return serialize($parts);
    }

    // =========================================================================
    // eq() optimization
    // =========================================================================

    public function eq(string $column, int|float|string|null $value): TableInterface
    {
        $strategy = $this->eqStrategies[$column] ?? 'measure';

        return match ($strategy) {
            'measure' => $this->measureEq($column, $value),
            'indexed' => $this->indexedEq($column, $value),
            'sqlite' => $this->materialized->eq($column, $value),
            default => parent::eq($column, $value),
        };
    }

    private function measureEq(string $column, mixed $value): TableInterface
    {
        $this->eqCallCounts[$column] = ($this->eqCallCounts[$column] ?? 0) + 1;

        $t = microtime(true);
        $result = parent::eq($column, $value);
        // Force iteration to measure actual time
        if ($this->eqCallCounts[$column] <= self::MEASURE_CALLS) {
            iterator_count($result);
            $result = parent::eq($column, $value); // Get fresh iterator
        }
        $elapsed = microtime(true) - $t;
        $this->eqTotalTimes[$column] = ($this->eqTotalTimes[$column] ?? 0) + $elapsed;

        // Check if we should build index (after minimum measurements)
        if ($this->eqCallCounts[$column] >= self::MEASURE_CALLS && $this->expectedEqCalls > 0) {
            $avgTime = $this->eqTotalTimes[$column] / $this->eqCallCounts[$column];
            $remainingCalls = $this->expectedEqCalls - $this->eqCallCounts[$column];
            $estimatedRemainingMs = $remainingCalls * $avgTime * 1000;

            if ($estimatedRemainingMs > self::INDEX_BENEFIT_THRESHOLD_MS) {
                $this->ensureColumnIndex($column);
            }
        }

        return $result;
    }

    private function indexedEq(string $column, mixed $value): TableInterface
    {
        $key = (string)($value ?? "\0");
        $index = $this->columnIndexes[$column];

        $matchingIds = iterator_to_array($index->eq($key));

        if (empty($matchingIds)) {
            return \mini\Table\Utility\EmptyTable::from($this->source);
        }

        // Return filtered view using the matching row IDs
        return new \mini\Table\GeneratorTable(
            function () use ($matchingIds) {
                $rows = $this->getAllRows();
                foreach ($matchingIds as $id) {
                    if (isset($rows[$id])) {
                        yield $id => $rows[$id];
                    }
                }
            },
            ...array_values($this->source->getColumns())
        );
    }

    private function ensureColumnIndex(string $column): void
    {
        if (isset($this->columnIndexes[$column])) {
            return;
        }

        $this->columnIndexes[$column] = TreapIndex::fromGenerator(function () use ($column) {
            foreach ($this->getAllRows() as $id => $row) {
                $key = (string)($row->$column ?? "\0");
                yield [$key, $id];
            }
        });

        // Check if too large
        if ($this->columnIndexes[$column]->rowCount() > self::SQLITE_ROW_THRESHOLD) {
            $this->materializeToSqlite();
            return;
        }

        $this->eqStrategies[$column] = 'indexed';
    }

    // =========================================================================
    // SQLite materialization (nuclear option)
    // =========================================================================

    private function materializeToSqlite(): void
    {
        $sourceColumns = $this->source->getColumns();

        // Build columns, adding indexes only for columns we know are being queried
        $indexedColumns = array_unique([
            ...$this->indexColumns,                    // Explicit hints
            ...array_keys($this->eqCallCounts),        // Observed eq() usage
        ]);

        $columns = [];
        foreach ($sourceColumns as $name => $col) {
            if ($col->index !== IndexType::None) {
                // Keep existing indexes
                $columns[] = $col;
            } elseif (in_array($name, $indexedColumns, true)) {
                // Add index for queried columns
                $columns[] = new ColumnDef($name, $col->type, IndexType::Unique);
            } else {
                $columns[] = $col;
            }
        }

        $this->materialized = new InMemoryTable(...$columns);

        foreach ($this->getAllRows() as $row) {
            $this->materialized->insert((array)$row);
        }

        $this->hasStrategy = 'sqlite';
        foreach (array_keys($this->eqStrategies) as $col) {
            $this->eqStrategies[$col] = 'sqlite';
        }

        // Clear PHP indexes to free memory
        $this->hasIndex = null;
        $this->columnIndexes = [];
        $this->rowCache = null;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function sourceHasUsefulIndex(): bool
    {
        foreach ($this->source->getColumns() as $col) {
            if ($col->index !== IndexType::None) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get all rows, caching for reuse during index building
     */
    private function getAllRows(): array
    {
        if ($this->rowCache === null) {
            $this->rowCache = [];
            foreach ($this->source as $id => $row) {
                $this->rowCache[$id] = $row;
            }
        }
        return $this->rowCache;
    }

    // =========================================================================
    // Forward other filter methods (they benefit from our indexes too)
    // =========================================================================

    public function lt(string $column, int|float|string $value): TableInterface
    {
        if ($this->materialized) {
            return $this->materialized->lt($column, $value);
        }
        return parent::lt($column, $value);
    }

    public function lte(string $column, int|float|string $value): TableInterface
    {
        if ($this->materialized) {
            return $this->materialized->lte($column, $value);
        }
        return parent::lte($column, $value);
    }

    public function gt(string $column, int|float|string $value): TableInterface
    {
        if ($this->materialized) {
            return $this->materialized->gt($column, $value);
        }
        return parent::gt($column, $value);
    }

    public function gte(string $column, int|float|string $value): TableInterface
    {
        if ($this->materialized) {
            return $this->materialized->gte($column, $value);
        }
        return parent::gte($column, $value);
    }

    // =========================================================================
    // Diagnostics
    // =========================================================================

    /**
     * Get current optimization state for debugging
     */
    public function getOptimizationState(): array
    {
        return [
            'hasStrategy' => $this->hasStrategy,
            'eqStrategies' => $this->eqStrategies,
            'hasCallCount' => $this->hasCallCount,
            'eqCallCounts' => $this->eqCallCounts,
            'hasIndexSize' => $this->hasIndex?->rowCount(),
            'columnIndexSizes' => array_map(
                fn($idx) => $idx->rowCount(),
                $this->columnIndexes
            ),
            'materialized' => $this->materialized !== null,
        ];
    }
}
