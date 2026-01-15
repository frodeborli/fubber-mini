<?php
namespace mini\Table\Wrappers;

use mini\Table\AbstractTable;
use mini\Table\Contracts\SetInterface;
use mini\Table\Contracts\TableInterface;
use mini\Table\Types\Operator;
use mini\Table\OrderDef;
use mini\Table\Predicate;
use mini\Table\Utility\EmptyTable;
use mini\Table\Utility\TablePropertiesTrait;

/**
 * Barrier that prevents filter/order pushdown to preserve result set membership
 *
 * When a table has LIMIT/OFFSET applied, further filtering should operate
 * on those specific rows, not modify the underlying query. BarrierTable
 * acts as a boundary that prevents filter pushdown.
 *
 * ```php
 * // Without barrier: eq() modifies query, still returns 10 rows
 * $users->order('age DESC')->limit(10)->eq('gender', 'male');  // 10 males
 *
 * // With barrier: eq() filters the 10 rows we already selected
 * BarrierTable::from($users->order('age DESC')->limit(10))
 *     ->eq('gender', 'male');  // ~5 males from the original 10
 * ```
 */
final class BarrierTable extends AbstractTableWrapper
{
    /**
     * Freeze a table if it has pagination, otherwise return as-is
     */
    public static function from(AbstractTable $source): AbstractTable
    {
        if ($source->getLimit() === null && $source->getOffset() === 0) {
            return $source;
        }
        return new self($source);
    }

    private function __construct(AbstractTable $source)
    {
        parent::__construct($source);
    }

    protected function materialize(string ...$additionalColumns): \Traversable
    {
        $offset = $this->offset;
        $limit = $this->limit;
        $count = 0;
        $skipped = 0;

        foreach ($this->source->materialize(...$additionalColumns) as $key => $row) {
            if ($skipped < $offset) {
                $skipped++;
                continue;
            }
            if ($limit !== null && $count >= $limit) {
                break;
            }
            yield $key => $row;
            $count++;
        }
    }

    public function count(): int
    {
        $sourceCount = $this->source->count();
        $afterOffset = max(0, $sourceCount - $this->offset);
        return $this->limit === null ? $afterOffset : min($afterOffset, $this->limit);
    }

    public function getLimit(): ?int
    {
        return null;
    }

    public function getOffset(): int
    {
        return 0;
    }

    // -------------------------------------------------------------------------
    // Filter methods - return wrappers around $this, never push down
    // -------------------------------------------------------------------------

    public function eq(string $column, int|float|string|null $value): TableInterface
    {
        return new FilteredTable($this, $column, Operator::Eq, $value);
    }

    public function lt(string $column, int|float|string $value): TableInterface
    {
        return new FilteredTable($this, $column, Operator::Lt, $value);
    }

    public function lte(string $column, int|float|string $value): TableInterface
    {
        return new FilteredTable($this, $column, Operator::Lte, $value);
    }

    public function gt(string $column, int|float|string $value): TableInterface
    {
        return new FilteredTable($this, $column, Operator::Gt, $value);
    }

    public function gte(string $column, int|float|string $value): TableInterface
    {
        return new FilteredTable($this, $column, Operator::Gte, $value);
    }

    public function in(string $column, SetInterface $values): TableInterface
    {
        return new FilteredTable($this, $column, Operator::In, $values);
    }

    public function like(string $column, string $pattern): TableInterface
    {
        return new FilteredTable($this, $column, Operator::Like, $pattern);
    }

    public function or(Predicate $a, Predicate $b, Predicate ...$more): TableInterface
    {
        // Filter out empty predicates (match nothing)
        $predicates = array_values(array_filter(
            [$a, $b, ...$more],
            fn($p) => !$p->isEmpty()
        ));

        // No predicates → nothing matches
        if (empty($predicates)) {
            return EmptyTable::from($this);
        }

        // Don't push down - use OrTable for single-pass evaluation
        return new OrTable($this, ...$predicates);
    }

    // -------------------------------------------------------------------------
    // Order/limit/offset - return wrappers, don't modify source
    // -------------------------------------------------------------------------

    public function order(?string $spec): TableInterface
    {
        $orders = $spec ? OrderDef::parse($spec) : [];
        if (empty($orders)) {
            return $this;
        }
        return new SortedTable($this, ...$orders);
    }

    public function limit(?int $n): TableInterface
    {
        // Apply limit on top of frozen rows
        $c = clone $this;
        $c->limit = $n;
        return $c;
    }

    public function offset(int $n): TableInterface
    {
        // Apply offset on top of frozen rows
        $c = clone $this;
        $c->offset = $n;
        return $c;
    }
}
