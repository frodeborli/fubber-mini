<?php

namespace mini\Table\Utility;

use mini\Table\ColumnDef;
use mini\Table\Contracts\SetInterface;
use mini\Table\Contracts\TableInterface;
use mini\Table\Predicate;
use mini\Table\Types\ColumnType;
use mini\Table\Utility\EmptyTable;
use mini\Table\Utility\TablePropertiesTrait;
use mini\Table\Wrappers\AliasTable;
use mini\Table\Wrappers\UnionTable;
use Traversable;

/**
 * A table with exactly one row and dynamic columns
 *
 * Used as the base for SELECT without FROM (like Oracle's DUAL or SQL Server's constants).
 *
 * ```php
 * // SELECT 1, 2, 'hello'
 * $table = new SingleRowTable(['1' => 1, '2' => 2, "'hello'" => 'hello']);
 * ```
 */
final class SingleRowTable implements TableInterface
{
    use TablePropertiesTrait;

    /** @var array<string, mixed> column name => value */
    private array $values;

    /** @var array<string, ColumnDef> */
    private array $columns;

    /**
     * @param array<string, mixed> $values Column name => value pairs
     */
    public function __construct(array $values = [])
    {
        $this->values = $values;
        $this->columns = [];
        foreach ($values as $name => $value) {
            $type = match (true) {
                is_int($value) => ColumnType::Int,
                is_float($value) => ColumnType::Float,
                default => ColumnType::Text,
            };
            $this->columns[$name] = new ColumnDef($name, $type, indexed: true);
        }
    }

    public function getIterator(): Traversable
    {
        yield 1 => (object) $this->values;
    }

    public function count(): int
    {
        return 1;
    }

    public function getColumns(): array
    {
        return $this->columns;
    }

    public function getAllColumns(): array
    {
        return $this->columns;
    }

    public function has(object $member): bool
    {
        foreach ($this->values as $col => $val) {
            if (!property_exists($member, $col) || $member->$col !== $val) {
                return false;
            }
        }
        return true;
    }

    public function eq(string $column, int|float|string|null $value): TableInterface
    {
        if (!isset($this->values[$column])) {
            return EmptyTable::from($this);
        }
        return $this->values[$column] === $value ? $this : EmptyTable::from($this);
    }

    public function lt(string $column, int|float|string $value): TableInterface
    {
        if (!isset($this->values[$column])) {
            return EmptyTable::from($this);
        }
        return $this->values[$column] < $value ? $this : EmptyTable::from($this);
    }

    public function lte(string $column, int|float|string $value): TableInterface
    {
        if (!isset($this->values[$column])) {
            return EmptyTable::from($this);
        }
        return $this->values[$column] <= $value ? $this : EmptyTable::from($this);
    }

    public function gt(string $column, int|float|string $value): TableInterface
    {
        if (!isset($this->values[$column])) {
            return EmptyTable::from($this);
        }
        return $this->values[$column] > $value ? $this : EmptyTable::from($this);
    }

    public function gte(string $column, int|float|string $value): TableInterface
    {
        if (!isset($this->values[$column])) {
            return EmptyTable::from($this);
        }
        return $this->values[$column] >= $value ? $this : EmptyTable::from($this);
    }

    public function in(string $column, SetInterface $values): TableInterface
    {
        if (!isset($this->values[$column])) {
            return EmptyTable::from($this);
        }
        $member = (object) [$column => $this->values[$column]];
        return $values->has($member) ? $this : EmptyTable::from($this);
    }

    public function like(string $column, string $pattern): TableInterface
    {
        if (!isset($this->values[$column])) {
            return EmptyTable::from($this);
        }
        $regex = '/^' . str_replace(['%', '_'], ['.*', '.'], preg_quote($pattern, '/')) . '$/i';
        return preg_match($regex, (string)$this->values[$column]) ? $this : EmptyTable::from($this);
    }

    public function union(TableInterface $other): TableInterface
    {
        return new UnionTable($this, $other);
    }

    public function or(Predicate $a, Predicate $b, Predicate ...$more): TableInterface
    {
        // Single row - just check if any predicate matches
        foreach ([$a, $b, ...$more] as $p) {
            if ($p->test((object) $this->values)) {
                return $this;
            }
        }
        return EmptyTable::from($this);
    }

    public function except(SetInterface $other): TableInterface
    {
        return $other->has((object) $this->values) ? EmptyTable::from($this) : $this;
    }

    public function distinct(): TableInterface
    {
        return $this; // Single row is already distinct
    }

    public function columns(string ...$columns): TableInterface
    {
        $projected = [];
        foreach ($columns as $col) {
            if (isset($this->values[$col])) {
                $projected[$col] = $this->values[$col];
            }
        }
        return new self($projected);
    }

    public function order(?string $spec): TableInterface
    {
        return $this; // Single row needs no ordering
    }

    public function limit(?int $n): TableInterface
    {
        return $n === 0 ? EmptyTable::from($this) : $this;
    }

    public function offset(int $n): TableInterface
    {
        return $n > 0 ? EmptyTable::from($this) : $this;
    }

    public function getLimit(): ?int
    {
        return null;
    }

    public function getOffset(): int
    {
        return 0;
    }

    public function exists(): bool
    {
        return true;
    }

    public function load(string|int $rowId): ?object
    {
        return $rowId === 1 ? (object) $this->values : null;
    }

    public function withAlias(?string $tableAlias = null, array $columnAliases = []): TableInterface
    {
        return new AliasTable($this, $tableAlias, $columnAliases);
    }
}
