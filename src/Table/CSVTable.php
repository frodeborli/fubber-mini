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
 * Table backed by CSV data
 *
 * Reads CSV from a file path or string content. First row is used as
 * column headers. Column types are inferred from the data.
 *
 * ```php
 * // From file
 * $table = CSVTable::fromFile('data.csv');
 *
 * // From string
 * $table = CSVTable::fromString("id,name\n1,Alice\n2,Bob");
 *
 * // With custom delimiter
 * $table = CSVTable::fromFile('data.tsv', "\t");
 * ```
 */
class CSVTable extends AbstractTable
{
    /** @var array<int, object> */
    private array $rows = [];

    private function __construct(ColumnDef ...$columns)
    {
        parent::__construct(...$columns);
    }

    /**
     * Create table from a CSV file
     *
     * @param string $path File path
     * @param string $delimiter Field delimiter (default: comma)
     * @param string $enclosure Field enclosure (default: double quote)
     * @param string $escape Escape character (default: backslash)
     */
    public static function fromFile(
        string $path,
        string $delimiter = ',',
        string $enclosure = '"',
        string $escape = '\\'
    ): self {
        if (!file_exists($path)) {
            throw new \InvalidArgumentException("CSV file not found: $path");
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Failed to open CSV file: $path");
        }

        try {
            return self::fromHandle($handle, $delimiter, $enclosure, $escape);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Create table from a CSV string
     *
     * @param string $content CSV content
     * @param string $delimiter Field delimiter (default: comma)
     * @param string $enclosure Field enclosure (default: double quote)
     * @param string $escape Escape character (default: backslash)
     */
    public static function fromString(
        string $content,
        string $delimiter = ',',
        string $enclosure = '"',
        string $escape = '\\'
    ): self {
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, $content);
        rewind($handle);

        try {
            return self::fromHandle($handle, $delimiter, $enclosure, $escape);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Create table from a file handle
     *
     * @param resource $handle File handle
     */
    private static function fromHandle(
        $handle,
        string $delimiter,
        string $enclosure,
        string $escape
    ): self {
        // Read header row
        $headers = fgetcsv($handle, 0, $delimiter, $enclosure, $escape);
        if ($headers === false || $headers === [null]) {
            throw new \InvalidArgumentException('CSV file is empty or invalid');
        }

        // Sanitize headers (trim whitespace, handle BOM)
        $headers = array_map(function ($h) {
            $h = trim($h);
            // Remove UTF-8 BOM if present
            if (str_starts_with($h, "\xEF\xBB\xBF")) {
                $h = substr($h, 3);
            }
            return $h;
        }, $headers);

        // Read all data rows and infer types
        $rows = [];
        $typeHints = array_fill_keys($headers, null); // null = unknown

        $rowNum = 0;
        while (($data = fgetcsv($handle, 0, $delimiter, $enclosure, $escape)) !== false) {
            if ($data === [null]) {
                continue; // Skip empty lines
            }

            $row = new \stdClass();
            foreach ($headers as $i => $header) {
                $value = $data[$i] ?? null;

                // Convert empty strings to null
                if ($value === '') {
                    $value = null;
                }

                // Type conversion and inference
                if ($value !== null) {
                    if ($typeHints[$header] !== ColumnType::Text) {
                        if (is_numeric($value)) {
                            if (str_contains($value, '.') || str_contains($value, 'e') || str_contains($value, 'E')) {
                                $value = (float) $value;
                                $typeHints[$header] = match ($typeHints[$header]) {
                                    null, ColumnType::Int, ColumnType::Float => ColumnType::Float,
                                    default => ColumnType::Text,
                                };
                            } else {
                                $intVal = (int) $value;
                                // Check for overflow
                                if ((string) $intVal === $value) {
                                    $value = $intVal;
                                    $typeHints[$header] = match ($typeHints[$header]) {
                                        null, ColumnType::Int => ColumnType::Int,
                                        ColumnType::Float => ColumnType::Float,
                                        default => ColumnType::Text,
                                    };
                                } else {
                                    $value = (float) $value;
                                    $typeHints[$header] = ColumnType::Float;
                                }
                            }
                        } else {
                            $typeHints[$header] = ColumnType::Text;
                        }
                    }
                }

                $row->$header = $value;
            }

            $rows[$rowNum++] = $row;
        }

        // Build column definitions
        $columns = [];
        foreach ($headers as $header) {
            $type = $typeHints[$header] ?? ColumnType::Text;
            $columns[] = new ColumnDef($header, $type);
        }

        $table = new self(...$columns);
        $table->rows = $rows;

        return $table;
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
        $total = count($this->rows);
        $offset = $this->getOffset();
        $limit = $this->getLimit();

        $count = max(0, $total - $offset);
        if ($limit !== null) {
            $count = min($count, $limit);
        }

        return $count;
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
