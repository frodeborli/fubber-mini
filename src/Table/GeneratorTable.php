<?php
namespace mini\Table;

use Closure;
use mini\Table\Contracts\SetInterface;
use mini\Table\Contracts\TableInterface;
use mini\Table\Types\ColumnType;
use mini\Table\Types\IndexType;
use mini\Table\Types\Operator;
use mini\Table\Utility\EmptyTable;
use mini\Table\Wrappers\FilteredTable;
use mini\Table\Wrappers\OrTable;
use mini\Table\Wrappers\SortedTable;
use Traversable;

/**
 * Simple table backed by a generator/closure
 *
 * The closure must return a generator that yields key => stdClass pairs.
 * Column definitions must be provided explicitly.
 *
 * ```php
 * $table = new GeneratorTable(
 *     function() {
 *         yield 1 => (object)['id' => 1, 'name' => 'Alice'];
 *         yield 2 => (object)['id' => 2, 'name' => 'Bob'];
 *     },
 *     new ColumnDef('id', ColumnType::Int, IndexType::Primary),
 *     new ColumnDef('name', ColumnType::Text),
 * );
 * ```
 *
 * Small result sets (≤200 rows) are cached after first iteration for
 * efficient repeated access. Larger result sets stream without buffering.
 */
class GeneratorTable extends AbstractTable
{
    private Closure $generator;

    public function __construct(Closure $generator, ColumnDef ...$columns)
    {
        if (empty($columns)) {
            throw new \InvalidArgumentException('GeneratorTable requires at least one column');
        }
        $this->generator = $generator;
        parent::__construct(...$columns);
    }

    protected function materialize(string ...$additionalColumns): Traversable
    {
        $visibleCols = array_keys($this->getColumns());
        $cols = array_unique([...$visibleCols, ...$additionalColumns]);

        $skipped = 0;
        $emitted = 0;
        $limit = $this->getLimit();
        $offset = $this->getOffset();

        foreach (($this->generator)() as $key => $row) {
            if ($skipped < $offset) {
                $skipped++;
                continue;
            }

            // Project to requested columns
            $projected = new \stdClass();
            foreach ($cols as $col) {
                $projected->$col = $row->$col ?? null;
            }

            yield $key => $projected;
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
    // Filter methods - return FilteredTable wrappers
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

    public function order(?string $spec): TableInterface
    {
        $orders = $spec ? OrderDef::parse($spec) : [];
        if (empty($orders)) {
            return $this;
        }
        return new SortedTable($this, ...$orders);
    }

    public function or(Predicate ...$predicates): TableInterface
    {
        // Filter out empty predicates (match nothing)
        $predicates = array_values(array_filter(
            $predicates,
            fn($p) => !$p->isEmpty()
        ));

        // No predicates → nothing matches
        if (empty($predicates)) {
            return EmptyTable::from($this);
        }

        // In-memory table - use OrTable for single-pass evaluation
        return new OrTable($this, ...$predicates);
    }
}
