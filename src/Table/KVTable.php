<?php

namespace mini\Table;

use mini\Table\Contracts\MutableTableInterface;
use mini\Table\Contracts\SetInterface;
use mini\Table\Contracts\TableInterface;
use mini\Table\Types\Operator;
use mini\Table\Wrappers\FilteredTable;
use mini\Table\Wrappers\SortedTable;
use mini\Table\Types\ColumnType;
use mini\Table\Types\IndexType;
use Traversable;

/**
 * Base class for a key/value table whose values live somewhere else
 *
 * Settings, feature flags, profile fields, counters, statistics - values that
 * belong to a system rather than to a row of storage, and that would sit badly
 * in a normal table. A subclass says which keys exist and how to read (and
 * optionally write) each one; this class turns that into a two-column SQL
 * table of `key` and `value`.
 *
 * ```php
 * final class Settings extends KVTable
 * {
 *     protected function keys(): array
 *     {
 *         return ['theme', 'items_per_page', 'password'];
 *     }
 *
 *     protected function read(string $key): mixed
 *     {
 *         return match ($key) {
 *             'theme'          => config('ui.theme'),
 *             'items_per_page' => (int) config('ui.page_size'),
 *             'password'       => '***',        // readable as a mask only
 *         };
 *     }
 *
 *     protected function write(string $key, mixed $value): void
 *     {
 *         match ($key) {
 *             'theme' => in_array($value, ['light', 'dark'], true)
 *                 ? config_set('ui.theme', $value)
 *                 : throw new \InvalidArgumentException(
 *                     "Setting 'theme' must be one of: light, dark; got '$value'."
 *                 ),
 *             'password' => $this->users->setPassword($value),
 *             default    => parent::write($key, $value),   // throws: read-only
 *         };
 *     }
 * }
 * ```
 *
 * ```sql
 * SELECT * FROM settings                       -- every key and its value
 * SELECT value FROM settings WHERE key = 'theme'
 * UPDATE settings SET value = 'dark' WHERE key = 'theme'
 * ```
 *
 * ## Why this is safe to mask
 *
 * `read()` is the only source of values: there is no storage engine behind
 * this table that could answer a predicate differently from what the rows
 * show, so the `TableInterface` consistency contract holds by construction.
 * A key whose `read()` returns `'***'` cannot be probed - `WHERE value LIKE
 * 's%'` filters the masked value, exactly as the caller sees it. That is what
 * makes "an agent may change the password but not read it" expressible here
 * and not on a storage-backed table.
 *
 * ## Errors reach the caller
 *
 * `VirtualDatabase` does not wrap exceptions from the table layer, so anything
 * `write()` throws arrives at the client with its class and message intact.
 * Write the message the way the engine writes its own - name the valid values,
 * not just "invalid" - and an LLM client can correct itself from it.
 *
 * ## When NOT to use this
 *
 * For a handful of related values that are always read together, a single row
 * of columns reads better than a list of key/value pairs: `SELECT * FROM
 * profile` giving one row of `display_name`, `timezone`, `status` beats three
 * rows a caller has to pivot. Use {@see \mini\Table\Utility\SingleRowTable}
 * for that shape. KVTable earns its keep when there are many keys, when the
 * set of keys is dynamic, or when each key is backed by something different.
 *
 * @see \mini\Table\Utility\SingleRowTable for the few-values shape
 */
abstract class KVTable extends AbstractTable implements MutableTableInterface
{
    public function __construct(string $keyColumn = 'key', string $valueColumn = 'value')
    {
        $this->keyColumn = $keyColumn;
        $this->valueColumn = $valueColumn;

        parent::__construct(
            new ColumnDef($keyColumn, ColumnType::Text, IndexType::Primary),
            new ColumnDef($valueColumn, ColumnType::Text),
        );
    }

    protected readonly string $keyColumn;
    protected readonly string $valueColumn;

    /**
     * The keys this table exposes
     *
     * Called on every scan, so it may be dynamic. Order is preserved.
     *
     * @return list<string>
     */
    abstract protected function keys(): array;

    /**
     * The current value of one key
     *
     * The only source of values this table has. Return a masked stand-in for a
     * value the caller may not see; because nothing else answers predicates
     * here, the mask is what filters compare against too.
     */
    abstract protected function read(string $key): mixed;

    /**
     * Apply a new value to one key
     *
     * Read-only by default: a subclass overrides this for the keys it allows
     * to change, and throws for the rest. Throwing is the intended way to
     * reject a value - the message reaches the SQL caller verbatim.
     *
     * @throws \RuntimeException always, unless overridden
     */
    protected function write(string $key, mixed $value): void
    {
        throw new \RuntimeException(
            "Setting '$key' is read-only and cannot be changed through SQL."
        );
    }

    /**
     * Does this key exist?
     *
     * Override when `keys()` is expensive and membership is cheap to answer.
     */
    protected function hasKey(string $key): bool
    {
        return in_array($key, $this->keys(), true);
    }

    protected function materialize(string ...$additionalColumns): Traversable
    {
        $rowId = 0;
        foreach ($this->keys() as $key) {
            yield $rowId++ => (object) [
                $this->keyColumn => $key,
                $this->valueColumn => $this->read($key),
            ];
        }
    }

    public function count(): int
    {
        return iterator_count($this);
    }

    // -------------------------------------------------------------------------
    // Filter methods
    //
    // Every predicate is answered by filtering the rows this table yields,
    // exactly as GeneratorTable does. That is deliberate: the values come only
    // from read(), so filters compare against precisely what the caller can
    // see, and a masked value cannot be probed with a predicate. A subclass
    // must not "optimise" one of these into a lookup that consults the
    // underlying source directly - that would break the TableInterface
    // consistency contract and turn every predicate into an oracle.
    //
    // If keys() is expensive, make read() lazy or cache it; do not bypass
    // these.
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
        if ($orders === []) {
            return $this;
        }
        return new SortedTable($this, ...$orders);
    }

    public function insert(array $row): int|string
    {
        $key = $row[$this->keyColumn] ?? null;
        if (!is_string($key) && !is_int($key)) {
            throw new \InvalidArgumentException(
                "INSERT into this key/value table requires a '{$this->keyColumn}' column."
            );
        }
        $key = (string) $key;

        if (!$this->hasKey($key)) {
            throw new \RuntimeException(
                "Unknown key '$key'. Valid keys: " . implode(', ', $this->keys()) . '.'
            );
        }

        $this->write($key, $row[$this->valueColumn] ?? null);
        return $key;
    }

    public function update(TableInterface $query, array $changes): int
    {
        if (!array_key_exists($this->valueColumn, $changes)) {
            throw new \InvalidArgumentException(
                "UPDATE on this key/value table may only set '{$this->valueColumn}'."
            );
        }
        if (array_key_exists($this->keyColumn, $changes)) {
            throw new \InvalidArgumentException(
                "The '{$this->keyColumn}' column identifies a setting and cannot be changed."
            );
        }

        $count = 0;
        foreach ($query as $row) {
            $key = $row->{$this->keyColumn} ?? null;
            if ($key === null) {
                continue;
            }
            $this->write((string) $key, $changes[$this->valueColumn]);
            $count++;
        }
        return $count;
    }

    public function delete(TableInterface $query): int
    {
        throw new \RuntimeException(
            'Rows cannot be deleted from a key/value table; the set of keys is defined '
            . 'by the table itself. Set the value instead.'
        );
    }
}
