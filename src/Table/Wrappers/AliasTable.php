<?php

namespace mini\Table\Wrappers;

use mini\Table\ColumnDef;
use mini\Table\Contracts\SetInterface;
use mini\Table\Contracts\TableInterface;
use mini\Table\OrderDef;
use mini\Table\Predicate;
use mini\Table\Utility\TablePropertiesTrait;
use Traversable;

/**
 * Wrapper that applies table/column aliasing to a source table
 *
 * Transforms column names by prefixing with table alias. Used for JOINs
 * and correlated subqueries where tables need qualified column names.
 *
 * ```php
 * $aliased = $users->withAlias('u');
 * // Columns: u.id, u.name
 * // Rows: (object) ['u.id' => 123, 'u.name' => 'Frode']
 *
 * // Filter methods require aliased column names
 * $aliased->eq('u.id', 123);  // Works
 * $aliased->eq('id', 123);    // Throws InvalidArgumentException
 * ```
 */
class AliasTable implements TableInterface
{
    use TablePropertiesTrait;

    private TableInterface $source;

    /** @var array<string,string> Original name → aliased name mapping */
    private array $aliasMap = [];

    /** @var array<string,string> Aliased name → original name mapping */
    private array $reverseMap = [];

    /** @var ?string Table alias for prefixing columns */
    private ?string $alias;

    /** @var array<string,string> Column renames [original => alias] */
    private array $colAliases;

    public function __construct(
        TableInterface $source,
        ?string $tableAlias = null,
        array $columnAliases = [],
    ) {
        $this->source = $source;
        $this->alias = $tableAlias;
        $this->colAliases = $columnAliases;

        // Build alias mappings from source columns
        foreach ($source->getColumns() as $origName => $def) {
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
     * Get the underlying source table
     */
    public function getSource(): TableInterface
    {
        return $this->source;
    }

    /**
     * Create a copy with a different source table
     */
    public function withSource(TableInterface $source): self
    {
        $copy = clone $this;
        $copy->source = $source;
        return $copy;
    }

    /**
     * Resolve aliased column name to original
     *
     * Only accepts aliased names (e.g., 'users.id'), not original ('id').
     */
    public function resolveToOriginal(string $name): string
    {
        // Must be an aliased name
        if (isset($this->reverseMap[$name])) {
            return $this->reverseMap[$name];
        }

        throw new \InvalidArgumentException(
            "Column '$name' does not exist in table. " .
            "Available columns: " . implode(', ', array_keys($this->reverseMap))
        );
    }

    public function getIterator(): Traversable
    {
        foreach ($this->source as $id => $row) {
            // Remap properties to aliased names
            $aliased = new \stdClass();
            foreach ($row as $origName => $value) {
                $aliasedName = $this->aliasMap[$origName]
                    ?? throw new \RuntimeException("No alias mapping for column '$origName'");
                $aliased->$aliasedName = $value;
            }
            yield $id => $aliased;
        }
    }

    public function count(): int
    {
        return $this->source->count();
    }

    public function getColumns(): array
    {
        $sourceCols = $this->source->getColumns();
        $result = [];

        foreach ($sourceCols as $origName => $def) {
            $aliasedName = $this->aliasMap[$origName]
                ?? throw new \RuntimeException("No alias mapping for column '$origName'");
            $result[$aliasedName] = new ColumnDef($aliasedName, $def->type, $def->index, ...$def->indexWith);
        }

        return $result;
    }

    public function getAllColumns(): array
    {
        $sourceCols = $this->source->getAllColumns();
        $result = [];

        foreach ($sourceCols as $origName => $def) {
            $aliasedName = $this->aliasMap[$origName]
                ?? throw new \RuntimeException("No alias mapping for column '$origName'");
            $result[$aliasedName] = new ColumnDef($aliasedName, $def->type, $def->index, ...$def->indexWith);
        }

        return $result;
    }

    public function has(object $member): bool
    {
        // Convert aliased member to original column names
        $original = new \stdClass();
        foreach ($member as $name => $value) {
            $origName = $this->reverseMap[$name] ?? $name;
            $original->$origName = $value;
        }
        return $this->source->has($original);
    }

    // -------------------------------------------------------------------------
    // Filter methods - resolve column name and delegate
    // -------------------------------------------------------------------------

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

    public function union(TableInterface $other): TableInterface
    {
        return new UnionTable($this, $other);
    }

    public function or(Predicate ...$predicates): TableInterface
    {
        // Translate aliased column names in predicates to original names
        $translated = array_map(
            fn(Predicate $p) => $p->mapColumns(fn($col) => $this->resolveToOriginal($col)),
            $predicates
        );

        $c = clone $this;
        $c->source = $this->source->or(...$translated);
        return $c;
    }

    public function except(SetInterface $other): TableInterface
    {
        return new ExceptTable($this, $other);
    }

    public function distinct(): TableInterface
    {
        $c = clone $this;
        $c->source = $this->source->distinct();
        return $c;
    }

    public function columns(string ...$columns): TableInterface
    {
        // Resolve to original names and delegate
        $origColumns = array_map([$this, 'resolveToOriginal'], $columns);
        $c = clone $this;
        $c->source = $this->source->columns(...$origColumns);

        // Rebuild alias maps for new column set
        $c->aliasMap = [];
        $c->reverseMap = [];
        foreach ($origColumns as $origName) {
            $aliasedName = $c->buildAliasedName($origName);
            $c->aliasMap[$origName] = $aliasedName;
            $c->reverseMap[$aliasedName] = $origName;
        }

        return $c;
    }

    public function order(?string $spec): TableInterface
    {
        if ($spec === null || $spec === '') {
            $c = clone $this;
            $c->source = $this->source->order(null);
            return $c;
        }

        // Parse and resolve column names in order spec
        $orders = OrderDef::parse($spec);
        $resolved = [];
        foreach ($orders as $order) {
            $origCol = $this->resolveToOriginal($order->column);
            $resolved[] = $origCol . ' ' . ($order->asc ? 'ASC' : 'DESC');
        }

        $c = clone $this;
        $c->source = $this->source->order(implode(', ', $resolved));
        return $c;
    }

    public function limit(int $n): TableInterface
    {
        $c = clone $this;
        $c->source = $this->source->limit($n);
        return $c;
    }

    public function offset(int $n): TableInterface
    {
        $c = clone $this;
        $c->source = $this->source->offset($n);
        return $c;
    }

    public function getLimit(): ?int
    {
        return $this->source->getLimit();
    }

    public function getOffset(): int
    {
        return $this->source->getOffset();
    }

    public function exists(): bool
    {
        return $this->source->exists();
    }

    public function load(string|int $rowId): ?object
    {
        $row = $this->source->load($rowId);
        if ($row === null) {
            return null;
        }

        // Remap to aliased names
        $aliased = new \stdClass();
        foreach ($row as $origName => $value) {
            $aliasedName = $this->aliasMap[$origName]
                ?? throw new \RuntimeException("No alias mapping for column '$origName'");
            $aliased->$aliasedName = $value;
        }
        return $aliased;
    }

    /**
     * Create new AliasTable with replaced/merged alias configuration
     *
     * When stacking aliases, the new table alias replaces the old one,
     * but column aliases are merged (new overrides old).
     */
    public function withAlias(?string $tableAlias = null, array $columnAliases = []): TableInterface
    {
        // Merge column aliases: new ones override old
        $merged = array_merge($this->colAliases, $columnAliases);
        return new AliasTable($this->source, $tableAlias, $merged);
    }
}
