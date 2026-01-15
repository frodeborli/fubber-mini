<?php

namespace mini\Table;

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
 * Simple table backed by a PHP array
 *
 * Accepts an array of rows (associative arrays or objects) with optional
 * explicit column definitions. If columns aren't provided, they're inferred
 * from the first row with automatic type detection.
 *
 * ```php
 * // With explicit columns
 * $table = new ArrayTable([
 *     ['id' => 1, 'name' => 'Alice'],
 *     ['id' => 2, 'name' => 'Bob'],
 * ], new ColumnDef('id', ColumnType::Int, IndexType::Primary),
 *    new ColumnDef('name', ColumnType::Text));
 *
 * // With auto-inference
 * $table = new ArrayTable([
 *     ['id' => 1, 'name' => 'Alice', 'score' => 95.5],
 *     ['id' => 2, 'name' => 'Bob', 'score' => 87.0],
 * ]);
 * ```
 */
class ArrayTable extends AbstractTable
{
    /** @var array<int|string, object> */
    private array $rows;

    /**
     * @param array<array|object> $rows Array of rows (associative arrays or objects)
     * @param ColumnDef ...$columns Optional column definitions (inferred if not provided)
     */
    public function __construct(array $rows, ColumnDef ...$columns)
    {
        // Convert rows to objects with preserved keys
        $this->rows = [];
        $idx = 0;
        foreach ($rows as $key => $row) {
            $this->rows[$key] = is_array($row) ? (object) $row : $row;
            $idx++;
        }

        // Infer columns from first row if not provided
        if (empty($columns) && !empty($this->rows)) {
            $columns = self::inferColumns(reset($this->rows));
        }

        if (empty($columns)) {
            throw new \InvalidArgumentException('ArrayTable requires columns (provide explicitly or via non-empty data)');
        }

        parent::__construct(...$columns);
    }

    /**
     * Infer column definitions from a row
     *
     * @return ColumnDef[]
     */
    private static function inferColumns(object $row): array
    {
        $columns = [];
        foreach ($row as $name => $value) {
            $type = match (true) {
                is_int($value) => ColumnType::Int,
                is_float($value) => ColumnType::Float,
                is_bool($value) => ColumnType::Int,
                default => ColumnType::Text,
            };
            $columns[] = new ColumnDef($name, $type);
        }
        return $columns;
    }

    protected function materialize(string ...$additionalColumns): Traversable
    {
        $visibleCols = array_keys($this->getColumns());
        $cols = array_unique([...$visibleCols, ...$additionalColumns]);

        $skipped = 0;
        $emitted = 0;
        $limit = $this->getLimit();
        $offset = $this->getOffset();

        foreach ($this->rows as $key => $row) {
            if ($skipped < $offset) {
                $skipped++;
                continue;
            }

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

        $total = count($this->rows);
        $offset = $this->getOffset();
        $limit = $this->getLimit();

        $count = max(0, $total - $offset);
        if ($limit !== null) {
            $count = min($count, $limit);
        }

        return $this->cachedCount = $count;
    }

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

    public function or(Predicate $a, Predicate $b, Predicate ...$more): TableInterface
    {
        $predicates = array_values(array_filter(
            [$a, $b, ...$more],
            fn($p) => !$p->isEmpty()
        ));

        if (empty($predicates)) {
            return EmptyTable::from($this);
        }

        return new OrTable($this, ...$predicates);
    }
}
