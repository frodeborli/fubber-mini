<?php

namespace mini\Table;

use Closure;
use stdClass;
use Traversable;

/**
 * Sorted table wrapper
 *
 * Applies ordering to a source table. If source has an index for the order
 * columns, delegates to source. Otherwise, buffers and sorts in-memory.
 *
 * ```php
 * SortedTable::for($table, new OrderDef('name'), new OrderDef('age', false))
 * ```
 */
class SortedTable extends AbstractTableWrapper
{
    /** @var OrderDef[] */
    private array $orderBy = [];

    /**
     * Create sorted view of a table, optimizing where possible
     *
     * Returns source directly if it can handle the ordering natively.
     * Strips any existing SortedTable wrapper before wrapping.
     */
    public static function from(TableInterface $source, OrderDef ...$orders): TableInterface
    {
        if (empty($orders)) {
            return $source->order(null);
        }

        $orderColumns = OrderDef::columns($orders);

        // Check if source can handle this ordering natively
        foreach ($source->getColumns() as $colDef) {
            if ($colDef->canOrder($orderColumns)) {
                return $source->order(OrderDef::toSpec($orders));
            }
        }

        // Strip any existing SortedTable from stack before wrapping
        $base = $source->order(null);

        return new self($base, ...$orders);
    }

    /**
     * @param AbstractTable $source Source table to wrap
     * @param OrderDef ...$orders Order specifications
     */
    public function __construct(
        AbstractTable $source,
        OrderDef ...$orders,
    ) {
        // Absorb source's limit/offset - we apply them after sorting
        $this->limit = $source->getLimit();
        $this->offset = $source->getOffset();

        // Clear source's limit/offset since we handle it
        if ($this->limit !== null) {
            $source = $source->limit(null);
        }
        if ($this->offset !== 0) {
            $source = $source->offset(0);
        }

        parent::__construct($source);
        $this->orderBy = $orders;
    }

    public function order(?string $spec): TableInterface
    {
        return $this->source->order($spec);
    }

    /**
     * Get the order specification for predicate inspection
     */
    public function getOrderSpec(): ?string
    {
        return empty($this->orderBy) ? null : OrderDef::toSpec($this->orderBy);
    }

    protected function materialize(string ...$additionalColumns): Traversable
    {
        $orderColumns = OrderDef::columns($this->orderBy);
        $allAdditional = array_unique([...$additionalColumns, ...$orderColumns]);

        // Buffer and sort in-memory
        $buffer = iterator_to_array(parent::materialize(...$allAdditional));
        uasort($buffer, $this->buildRowComparator());

        // Apply limit/offset after sorting
        $skipped = 0;
        $emitted = 0;
        $limit = $this->getLimit();
        $offset = $this->getOffset();

        foreach ($buffer as $key => $row) {
            if ($skipped < $offset) {
                $skipped++;
                continue;
            }

            yield $key => $row;
            $emitted++;

            if ($limit !== null && $emitted >= $limit) {
                return;
            }
        }
    }

    public function count(): int
    {
        if ($this->cachedCount !== null) {
            return $this->cachedCount;
        }
        $count = 0;
        foreach ($this as $_) {
            $count++;
        }
        return $this->cachedCount = $count;
    }

    // -------------------------------------------------------------------------
    // Limit/offset must be stored locally, not pushed to source
    // -------------------------------------------------------------------------

    public function limit(?int $n): TableInterface
    {
        if ($this->limit === $n) {
            return $this;
        }
        $c = clone $this;
        $c->limit = $n;
        return $c;
    }

    public function offset(int $n): TableInterface
    {
        if ($this->offset === $n) {
            return $this;
        }
        $c = clone $this;
        $c->offset = $n;
        return $c;
    }

    /**
     * Build a row comparator function from OrderDef[]
     */
    private function buildRowComparator(): Closure
    {
        $orders = $this->orderBy;
        $compareFn = $this->getCompareFn();
        $columns = $this->source->getAllColumns();

        return function (stdClass $a, stdClass $b) use ($orders, $compareFn, $columns): int {
            foreach ($orders as $order) {
                $column = $order->column;
                $mult = $order->asc ? 1 : -1;

                $valA = $a->$column ?? null;
                $valB = $b->$column ?? null;

                // Use <=> for null, int, float
                if ($valA === null || $valB === null
                    || is_int($valA) || is_float($valA)
                    || is_int($valB) || is_float($valB)
                ) {
                    $cmp = $valA <=> $valB;
                } elseif (isset($columns[$column]) && $columns[$column]->type->shouldUseCollator()) {
                    // Text columns use locale-aware collator
                    $cmp = $compareFn((string)$valA, (string)$valB);
                } else {
                    // Binary, Date, Time, DateTime use binary comparison
                    $cmp = $valA <=> $valB;
                }

                if ($cmp !== 0) {
                    return $mult * $cmp;
                }
            }

            return 0;
        };
    }
}
