<?php

namespace mini\Table\Wrappers;

use mini\Table\AbstractTable;
use mini\Table\Contracts\TableInterface;
use mini\Table\Predicate;
use mini\Table\Utility\TablePropertiesTrait;
use Traversable;

/**
 * Applies OR predicates to source table in-memory
 *
 * Materializes source and yields rows matching any of the predicates.
 *
 * ```php
 * new OrTable($source, Predicate::eq('x', 1), Predicate::eq('y', 2))
 * ```
 */
class OrTable extends AbstractTableWrapper
{
    /** @var Predicate[] */
    private array $predicates;

    public function __construct(
        AbstractTable $source,
        Predicate ...$predicates,
    ) {
        // Filter out empty predicates
        $this->predicates = array_values(array_filter(
            $predicates,
            fn($p) => !$p->isEmpty()
        ));

        // Absorb source's limit/offset - we apply them after filtering
        $this->limit = $source->getLimit();
        $this->offset = $source->getOffset();

        if ($this->limit !== null) {
            $source = $source->limit(null);
        }
        if ($this->offset !== 0) {
            $source = $source->offset(0);
        }

        parent::__construct($source);
    }

    /**
     * Test if a row matches any predicate
     */
    public function test(object $row): bool
    {
        foreach ($this->predicates as $predicate) {
            if ($predicate->test($row)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get all predicates
     *
     * @return Predicate[]
     */
    public function getPredicates(): array
    {
        return $this->predicates;
    }

    protected function materialize(string ...$additionalColumns): Traversable
    {
        // If no predicates, yield nothing
        if (empty($this->predicates)) {
            return;
        }

        $skipped = 0;
        $emitted = 0;
        $limit = $this->getLimit();
        $offset = $this->getOffset();

        foreach (parent::materialize(...$additionalColumns) as $id => $row) {
            if (!$this->test($row)) {
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

    public function has(object $member): bool
    {
        // Short-circuit: if member doesn't match any OR predicate, it's not in result
        if (!$this->test($member)) {
            return false;
        }
        return parent::has($member);
    }

    // -------------------------------------------------------------------------
    // Limit/offset stored locally, not pushed to source
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

    // -------------------------------------------------------------------------
    // Don't push or() down - wrap in another OrTable or handle locally
    // -------------------------------------------------------------------------

    /**
     * Restore absorbed pagination to a replacement table
     */
    private function withPagination(TableInterface $table): TableInterface
    {
        if ($this->limit !== null) {
            $table = $table->limit($this->limit);
        }
        if ($this->offset !== 0) {
            $table = $table->offset($this->offset);
        }
        return $table;
    }

    public function or(Predicate ...$predicates): TableInterface
    {
        // Merge predicates into a new OrTable, restoring absorbed pagination
        $allPredicates = [...$this->predicates, ...$predicates];
        return $this->withPagination(new self($this->source, ...$allPredicates));
    }
}
