<?php
namespace mini\Table;

use Traversable;

/**
 * Filters rows from source table using a column/operator/value condition
 *
 * Unlike AbstractTableWrapper's default pushdown behavior, FilteredTable
 * applies filtering in-memory during materialization. This is used by
 * BarrierTable to filter already-selected rows.
 *
 * ```php
 * new FilteredTable($source, 'status', Operator::Eq, 'active')
 * new FilteredTable($source, 'age', Operator::Gte, 18)
 * new FilteredTable($source, 'id', Operator::In, $setOfIds)
 * ```
 */
class FilteredTable extends AbstractTableWrapper implements PredicateInterface
{
    public function __construct(
        AbstractTable $source,
        private string $column,
        private Operator $operator,
        private mixed $value,
    ) {
        // Absorb source's limit/offset - we apply them after filtering
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
    }

    // -------------------------------------------------------------------------
    // Accessors for predicate inspection
    // -------------------------------------------------------------------------

    public function getFilterColumn(): string
    {
        return $this->column;
    }

    public function getFilterOperator(): Operator
    {
        return $this->operator;
    }

    public function getFilterValue(): mixed
    {
        return $this->value;
    }

    public function test(object $row): bool
    {
        // Check our condition
        if (!$this->matches($row->{$this->column} ?? null)) {
            return false;
        }

        // Delegate to source if it's also a predicate
        $source = $this->getSource();
        if ($source instanceof PredicateInterface) {
            return $source->test($row);
        }

        return true;
    }

    protected function materialize(string ...$additionalColumns): Traversable
    {
        $col = $this->column;
        $allAdditional = array_unique([...$additionalColumns, $col]);

        $skipped = 0;
        $emitted = 0;
        $limit = $this->getLimit();
        $offset = $this->getOffset();

        foreach (parent::materialize(...$allAdditional) as $id => $row) {
            if (!$this->matches($row->$col ?? null)) {
                continue;
            }

            if ($skipped < $offset) {
                $skipped++;
                continue;
            }

            yield $id => $row;
            $emitted++;

            if ($limit !== null && $emitted >= $limit) {
                return;
            }
        }
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

    private function matches(mixed $rowValue): bool
    {
        return match ($this->operator) {
            Operator::Eq => $this->compare($rowValue, $this->value) === 0,
            Operator::Lt => $this->compare($rowValue, $this->value) < 0,
            Operator::Lte => $this->compare($rowValue, $this->value) <= 0,
            Operator::Gt => $this->compare($rowValue, $this->value) > 0,
            Operator::Gte => $this->compare($rowValue, $this->value) >= 0,
            Operator::In => $this->matchesIn($rowValue),
            Operator::Like => $this->matchesLike($rowValue),
        };
    }

    /**
     * Compare two values using collator for strings, <=> for numbers
     */
    private function compare(mixed $left, mixed $right): int
    {
        if (is_string($left) || is_string($right)) {
            return $this->getCompareFn()((string)$left, (string)$right);
        }
        return $left <=> $right;
    }

    private function matchesIn(mixed $rowValue): bool
    {
        $set = $this->value;
        if (!$set instanceof SetInterface) {
            throw new \LogicException('IN operator requires SetInterface value');
        }
        $member = (object)[$this->column => $rowValue];
        return $set->has($member);
    }

    private function matchesLike(mixed $rowValue): bool
    {
        if ($rowValue === null) {
            return false;
        }
        // Convert SQL LIKE pattern to regex
        $pattern = $this->value;
        $regex = '/^' . str_replace(
            ['%', '_'],
            ['.*', '.'],
            preg_quote($pattern, '/')
        ) . '$/i';
        return preg_match($regex, (string)$rowValue) === 1;
    }

    public function count(): int
    {
        $count = 0;
        foreach ($this as $_) {
            $count++;
        }
        return $count;
    }

    // -------------------------------------------------------------------------
    // Filter methods - optimize same-column filters, wrap otherwise
    // -------------------------------------------------------------------------

    public function eq(string $column, int|float|string|null $value): TableInterface
    {
        if ($column === $this->column) {
            $cmp = $this->compare($value, $this->value);
            if ($this->operator === Operator::Eq) {
                return $cmp === 0 ? $this : EmptyTable::from($this);
            }
            // Check if current filter allows this eq value
            $compatible = match ($this->operator) {
                Operator::Lt => $cmp < 0,
                Operator::Lte => $cmp <= 0,
                Operator::Gt => $cmp > 0,
                Operator::Gte => $cmp >= 0,
                default => null,
            };
            if ($compatible === true) {
                return $this->source->eq($column, $value);
            }
            if ($compatible === false) {
                return EmptyTable::from($this);
            }
        }
        return parent::eq($column, $value);
    }

    public function lt(string $column, int|float|string $value): TableInterface
    {
        if ($column === $this->column) {
            $cmp = $this->compare($value, $this->value);
            if ($this->operator === Operator::Eq) {
                return $this->compare($this->value, $value) < 0 ? $this : EmptyTable::from($this);
            }
            if ($this->operator === Operator::Lt || $this->operator === Operator::Lte) {
                return $cmp < 0 ? $this->source->lt($column, $value) : $this;
            }
            if ($this->operator === Operator::Gt || $this->operator === Operator::Gte) {
                if ($cmp <= 0) {
                    return EmptyTable::from($this);
                }
            }
        }
        return parent::lt($column, $value);
    }

    public function lte(string $column, int|float|string $value): TableInterface
    {
        if ($column === $this->column) {
            $cmp = $this->compare($value, $this->value);
            if ($this->operator === Operator::Eq) {
                return $this->compare($this->value, $value) <= 0 ? $this : EmptyTable::from($this);
            }
            if ($this->operator === Operator::Lt || $this->operator === Operator::Lte) {
                return $cmp < 0 ? $this->source->lte($column, $value) : $this;
            }
            if ($this->operator === Operator::Gt) {
                if ($cmp <= 0) {
                    return EmptyTable::from($this);
                }
            }
            if ($this->operator === Operator::Gte) {
                if ($cmp > 0) {
                    // valid range, fall through
                } elseif ($cmp === 0) {
                    return $this->source->eq($column, $value);
                } else {
                    return EmptyTable::from($this);
                }
            }
        }
        return parent::lte($column, $value);
    }

    public function gt(string $column, int|float|string $value): TableInterface
    {
        if ($column === $this->column) {
            $cmp = $this->compare($value, $this->value);
            if ($this->operator === Operator::Eq) {
                return $this->compare($this->value, $value) > 0 ? $this : EmptyTable::from($this);
            }
            if ($this->operator === Operator::Gt || $this->operator === Operator::Gte) {
                return $cmp > 0 ? $this->source->gt($column, $value) : $this;
            }
            if ($this->operator === Operator::Lt || $this->operator === Operator::Lte) {
                if ($cmp >= 0) {
                    return EmptyTable::from($this);
                }
            }
        }
        return parent::gt($column, $value);
    }

    public function gte(string $column, int|float|string $value): TableInterface
    {
        if ($column === $this->column) {
            $cmp = $this->compare($value, $this->value);
            if ($this->operator === Operator::Eq) {
                return $this->compare($this->value, $value) >= 0 ? $this : EmptyTable::from($this);
            }
            if ($this->operator === Operator::Gt || $this->operator === Operator::Gte) {
                return $cmp > 0 ? $this->source->gte($column, $value) : $this;
            }
            if ($this->operator === Operator::Lt) {
                if ($cmp >= 0) {
                    return EmptyTable::from($this);
                }
            }
            if ($this->operator === Operator::Lte) {
                if ($cmp < 0) {
                    // valid range, fall through
                } elseif ($cmp === 0) {
                    return $this->source->eq($column, $value);
                } else {
                    return EmptyTable::from($this);
                }
            }
        }
        return parent::gte($column, $value);
    }

    public function order(?string $spec): TableInterface
    {
        $orders = $spec ? OrderDef::parse($spec) : [];
        if (empty($orders)) {
            return $this;
        }
        return new SortedTable($this, ...$orders);
    }
}
