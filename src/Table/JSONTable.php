<?php

namespace mini\Table;

use mini\Table\Contracts\SetInterface;
use mini\Table\Contracts\TableInterface;
use mini\Table\Types\ColumnType;
use mini\Table\Types\Operator;
use mini\Table\Utility\EmptyTable;
use mini\Table\Wrappers\FilteredTable;
use mini\Table\Wrappers\OrTable;
use mini\Table\Wrappers\SortedTable;
use Traversable;

/**
 * Table backed by JSON data
 *
 * Reads an array of objects from a JSON file, string, or PHP array.
 * Column types are inferred from the data.
 *
 * ```php
 * // From file
 * $table = JSONTable::fromFile('data.json');
 *
 * // From string
 * $table = JSONTable::fromString('[{"id":1,"name":"Alice"}]');
 *
 * // From array (already decoded)
 * $table = JSONTable::fromArray([
 *     ['id' => 1, 'name' => 'Alice'],
 *     ['id' => 2, 'name' => 'Bob'],
 * ]);
 *
 * // With JSON pointer to nested array
 * $table = JSONTable::fromFile('response.json', '/data/users');
 * ```
 */
class JSONTable extends AbstractTable
{
    /** @var array<int, object> */
    private array $rows = [];

    private function __construct(ColumnDef ...$columns)
    {
        parent::__construct(...$columns);
    }

    /**
     * Create table from a JSON file
     *
     * @param string $path File path
     * @param string|null $pointer JSON pointer to array (e.g., '/data/items')
     */
    public static function fromFile(string $path, ?string $pointer = null): self
    {
        if (!file_exists($path)) {
            throw new \InvalidArgumentException("JSON file not found: $path");
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Failed to read JSON file: $path");
        }

        return self::fromString($content, $pointer);
    }

    /**
     * Create table from a JSON string
     *
     * @param string $content JSON content
     * @param string|null $pointer JSON pointer to array (e.g., '/data/items')
     */
    public static function fromString(string $content, ?string $pointer = null): self
    {
        $data = json_decode($content, false, 512, JSON_THROW_ON_ERROR);

        if ($pointer !== null) {
            $data = self::resolvePointer($data, $pointer);
        }

        if (!is_array($data)) {
            throw new \InvalidArgumentException('JSON must decode to an array of objects');
        }

        return self::fromArray($data);
    }

    /**
     * Create table from a PHP array
     *
     * @param array<array|object> $data Array of rows
     */
    public static function fromArray(array $data): self
    {
        if (empty($data)) {
            throw new \InvalidArgumentException('JSONTable requires non-empty data');
        }

        // Convert to objects and collect all column names
        $rows = [];
        $allColumns = [];

        foreach ($data as $idx => $row) {
            if (is_array($row)) {
                $row = (object) $row;
            }
            if (!is_object($row)) {
                throw new \InvalidArgumentException("Row $idx must be an array or object");
            }

            foreach ($row as $key => $value) {
                if (!isset($allColumns[$key])) {
                    $allColumns[$key] = null; // type TBD
                }
            }

            $rows[$idx] = $row;
        }

        // Infer types from all rows
        foreach ($rows as $row) {
            foreach ($allColumns as $col => $currentType) {
                $value = $row->$col ?? null;

                if ($value === null) {
                    continue;
                }

                $valueType = match (true) {
                    is_int($value) => ColumnType::Int,
                    is_float($value) => ColumnType::Float,
                    is_bool($value) => ColumnType::Int, // SQLite convention
                    is_string($value) => ColumnType::Text,
                    default => ColumnType::Text, // arrays/objects become Text
                };

                // Widen type if needed
                $allColumns[$col] = match ($currentType) {
                    null => $valueType,
                    ColumnType::Int => match ($valueType) {
                        ColumnType::Int => ColumnType::Int,
                        ColumnType::Float => ColumnType::Float,
                        default => ColumnType::Text,
                    },
                    ColumnType::Float => match ($valueType) {
                        ColumnType::Int, ColumnType::Float => ColumnType::Float,
                        default => ColumnType::Text,
                    },
                    default => ColumnType::Text,
                };
            }
        }

        // Stringify non-scalar values and convert bools to int
        foreach ($rows as $row) {
            foreach ($row as $col => $value) {
                if (is_bool($value)) {
                    $row->$col = $value ? 1 : 0;
                } elseif (is_array($value) || is_object($value)) {
                    $row->$col = json_encode($value);
                }
            }
        }

        // Build columns
        $columns = [];
        foreach ($allColumns as $name => $type) {
            $columns[] = new ColumnDef($name, $type ?? ColumnType::Text);
        }

        $table = new self(...$columns);
        $table->rows = $rows;

        return $table;
    }

    /**
     * Resolve a JSON pointer to a nested value
     *
     * @param mixed $data Root data
     * @param string $pointer JSON pointer (e.g., '/data/items')
     * @return mixed Resolved value
     */
    private static function resolvePointer(mixed $data, string $pointer): mixed
    {
        if ($pointer === '' || $pointer === '/') {
            return $data;
        }

        $parts = explode('/', ltrim($pointer, '/'));

        foreach ($parts as $part) {
            // Unescape JSON pointer special chars
            $part = str_replace(['~1', '~0'], ['/', '~'], $part);

            if (is_array($data)) {
                if (!array_key_exists($part, $data)) {
                    throw new \InvalidArgumentException("JSON pointer '$pointer' not found at '$part'");
                }
                $data = $data[$part];
            } elseif (is_object($data)) {
                if (!property_exists($data, $part)) {
                    throw new \InvalidArgumentException("JSON pointer '$pointer' not found at '$part'");
                }
                $data = $data->$part;
            } else {
                throw new \InvalidArgumentException("JSON pointer '$pointer' cannot traverse non-object at '$part'");
            }
        }

        return $data;
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
