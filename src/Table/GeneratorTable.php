<?php
namespace mini\Table;

use Closure;
use Traversable;

/**
 * Simple table backed by a generator/closure
 *
 * The closure must return a generator that yields key => stdClass pairs.
 * Column definitions are inferred from the first row's properties.
 *
 * ```php
 * $table = new GeneratorTable(function() {
 *     yield 1 => (object)['id' => 1, 'name' => 'Alice'];
 *     yield 2 => (object)['id' => 2, 'name' => 'Bob'];
 * });
 * ```
 *
 * The closure is called fresh each time the table is iterated, allowing
 * for arbitrarily large data sources without buffering.
 */
class GeneratorTable extends AbstractTable
{
    private Closure $generator;

    public function __construct(Closure $generator)
    {
        $this->generator = $generator;

        // Peek at first row to infer columns
        $columns = [];
        foreach ($generator() as $row) {
            foreach ((array)$row as $name => $value) {
                $columns[] = new ColumnDef($name);
            }
            break;
        }

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
        $count = 0;
        foreach ($this as $_) {
            $count++;
        }
        return $count;
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
}
