<?php

namespace mini\Table;

use Traversable;

/**
 * Wrapper that applies table/column aliasing to a source table
 *
 * Used when the source table cannot be directly modified (e.g., BarrierTable,
 * userspace tables). For tables that extend AbstractTable, prefer using
 * withAlias() directly which is more efficient.
 *
 * ```php
 * // Wrap a frozen table with alias
 * $aliased = new AliasTable($barrierTable, 'u');
 * // Columns: u.id, u.name
 * // Rows: (object) ['u.id' => 123, 'u.name' => 'Frode']
 * ```
 */
class AliasTable extends AbstractTableWrapper
{
    /** @var array<string,string> Original name → aliased name mapping */
    private array $aliasMap = [];

    /** @var array<string,string> Aliased name → original name mapping */
    private array $reverseMap = [];

    /** @var ?string Table alias for prefixing columns */
    private ?string $alias;

    /** @var array<string,string> Column renames [original => alias] */
    private array $colAliases;

    public function __construct(
        AbstractTable $source,
        ?string $tableAlias = null,
        array $columnAliases = [],
    ) {
        parent::__construct($source);
        $this->alias = $tableAlias;
        $this->colAliases = $columnAliases;

        // Build alias mappings
        foreach ($source->getAllColumns() as $origName => $def) {
            $aliasedName = $this->buildAliasedName($origName);
            $this->aliasMap[$origName] = $aliasedName;
            $this->reverseMap[$aliasedName] = $origName;
        }
    }

    private function buildAliasedName(string $name): string
    {
        // Apply column alias first
        $name = $this->colAliases[$name] ?? $name;

        // Then apply table prefix
        if ($this->alias !== null) {
            $name = $this->alias . '.' . $name;
        }

        return $name;
    }

    /**
     * Resolve column name to original (accepts both original and aliased)
     */
    private function resolveToOriginal(string $name): string
    {
        // Check if it's already an original name
        if (isset($this->aliasMap[$name])) {
            return $name;
        }

        // Check if it's an aliased name
        if (isset($this->reverseMap[$name])) {
            return $this->reverseMap[$name];
        }

        throw new \InvalidArgumentException("Column '$name' does not exist in table");
    }

    public function getColumns(): array
    {
        $sourceCols = $this->source->getColumns();
        $result = [];

        foreach ($sourceCols as $origName => $def) {
            $aliasedName = $this->aliasMap[$origName] ?? $origName;
            $result[$aliasedName] = new ColumnDef($aliasedName, $def->type, $def->index, ...$def->indexWith);
        }

        return $result;
    }

    protected function materialize(string ...$additionalColumns): Traversable
    {
        // Map additional columns to original names
        $origAdditional = [];
        foreach ($additionalColumns as $col) {
            $origAdditional[] = $this->resolveToOriginal($col);
        }

        foreach (parent::materialize(...$origAdditional) as $id => $row) {
            // Remap properties to aliased names
            $aliased = new \stdClass();
            foreach ($row as $origName => $value) {
                $aliasedName = $this->aliasMap[$origName] ?? $origName;
                $aliased->$aliasedName = $value;
            }
            yield $id => $aliased;
        }
    }

    public function columns(string ...$columns): TableInterface
    {
        // Resolve to original names and delegate
        $origColumns = array_map([$this, 'resolveToOriginal'], $columns);
        $c = clone $this;
        $c->source = $this->source->columns(...$origColumns);
        return $c;
    }

    // Filter methods - resolve column name and delegate

    public function eq(string $column, int|float|string|null $value): TableInterface
    {
        $c = clone $this;
        $c->source = $this->source->eq($this->resolveToOriginal($column), $value);
        return $c;
    }

    public function lt(string $column, int|float|string $value): TableInterface
    {
        $c = clone $this;
        $c->source = $this->source->lt($this->resolveToOriginal($column), $value);
        return $c;
    }

    public function lte(string $column, int|float|string $value): TableInterface
    {
        $c = clone $this;
        $c->source = $this->source->lte($this->resolveToOriginal($column), $value);
        return $c;
    }

    public function gt(string $column, int|float|string $value): TableInterface
    {
        $c = clone $this;
        $c->source = $this->source->gt($this->resolveToOriginal($column), $value);
        return $c;
    }

    public function gte(string $column, int|float|string $value): TableInterface
    {
        $c = clone $this;
        $c->source = $this->source->gte($this->resolveToOriginal($column), $value);
        return $c;
    }

    public function in(string $column, SetInterface $values): TableInterface
    {
        $c = clone $this;
        $c->source = $this->source->in($this->resolveToOriginal($column), $values);
        return $c;
    }

    public function like(string $column, string $pattern): TableInterface
    {
        $c = clone $this;
        $c->source = $this->source->like($this->resolveToOriginal($column), $pattern);
        return $c;
    }

    public function withAlias(?string $tableAlias = null, array $columnAliases = []): TableInterface
    {
        // Stack aliasing - create new AliasTable wrapping this one
        return new self($this, $tableAlias, $columnAliases);
    }

    public function order(?string $spec): TableInterface
    {
        if ($spec === null || $spec === '') {
            return $this;
        }

        // Parse and resolve column names in order spec
        $orders = OrderDef::parse($spec);
        $resolved = [];
        foreach ($orders as $order) {
            $origCol = $this->resolveToOriginal($order->column);
            $resolved[] = $origCol . ' ' . ($order->descending ? 'DESC' : 'ASC');
        }

        $c = clone $this;
        $c->source = $this->source->order(implode(', ', $resolved));
        return $c;
    }

    public function count(): int
    {
        return $this->source->count();
    }
}
