<?php

namespace mini\Table\Wrappers;

use mini\Table\AbstractTable;
use mini\Table\Contracts\SetInterface;
use mini\Table\Contracts\TableInterface;
use mini\Table\Types\Operator;
use Traversable;

/**
 * Debug wrapper that logs operations reaching the implementation
 *
 * Wraps any table and logs what predicates, ordering, and pagination
 * are in effect when the table is actually materialized or queried.
 *
 * ```php
 * $debug = DebugTable::wrap($table);
 * $result = $debug->eq('status', 'active')->gt('age', 18)->limit(10);
 * foreach ($result as $row) { ... }
 * // Logs: [DebugTable] MATERIALIZE: WHERE status='active' AND age>18 LIMIT 10
 * ```
 */
class DebugTable extends AbstractTableWrapper
{
    /** @var array<array{column: string, op: Operator, value: mixed}> */
    private array $filters = [];

    /** @var string|null */
    private ?string $orderSpec = null;

    /** @var callable|null */
    private $logger;

    /** @var string */
    private string $tableName;

    private function __construct(
        AbstractTable $source,
        ?callable $logger = null,
        string $tableName = 'table',
    ) {
        parent::__construct($source);
        $this->logger = $logger;
        $this->tableName = $tableName;
    }

    /**
     * Wrap a table for debugging
     *
     * @param TableInterface $table Table to wrap
     * @param callable|null $logger Custom logger (receives string), defaults to error_log
     * @param string $tableName Name to show in logs
     */
    public static function wrap(
        TableInterface $table,
        ?callable $logger = null,
        string $tableName = 'table',
    ): self {
        if (!$table instanceof AbstractTable) {
            throw new \InvalidArgumentException('DebugTable requires AbstractTable source');
        }
        return new self($table, $logger, $tableName);
    }

    private function log(string $message): void
    {
        $line = "[DebugTable:{$this->tableName}] $message";
        if ($this->logger) {
            ($this->logger)($line);
        } else {
            error_log($line);
        }
    }

    /**
     * Build SQL-like description of current state
     */
    private function describeState(string $operation): string
    {
        $parts = [$operation];

        // WHERE clause
        if ($this->filters) {
            $conditions = [];
            foreach ($this->filters as $f) {
                $conditions[] = $this->describeFilter($f);
            }
            $parts[] = 'WHERE ' . implode(' AND ', $conditions);
        }

        // ORDER BY
        if ($this->orderSpec) {
            $parts[] = 'ORDER BY ' . $this->orderSpec;
        }

        // LIMIT/OFFSET
        $limit = $this->source->getLimit();
        $offset = $this->source->getOffset();
        if ($limit !== null) {
            $parts[] = 'LIMIT ' . $limit;
        }
        if ($offset > 0) {
            $parts[] = 'OFFSET ' . $offset;
        }

        // Show wrapper chain to identify implementation path
        $parts[] = '-- via ' . $this->describeWrapperChain();

        return implode(' ', $parts);
    }

    /**
     * Describe the wrapper chain (helps identify optimization barriers)
     */
    private function describeWrapperChain(): string
    {
        $chain = [];
        $current = $this->source;

        while ($current !== null) {
            $class = (new \ReflectionClass($current))->getShortName();
            $chain[] = $class;

            if ($current instanceof AbstractTableWrapper) {
                $current = $current->getSource();
            } else {
                break;
            }
        }

        return implode(' → ', $chain);
    }

    private function describeFilter(array $f): string
    {
        $col = $f['column'];
        $op = match ($f['op']) {
            Operator::Eq => '=',
            Operator::Lt => '<',
            Operator::Lte => '<=',
            Operator::Gt => '>',
            Operator::Gte => '>=',
            Operator::In => 'IN',
            Operator::Like => 'LIKE',
        };
        $val = $this->describeValue($f['value']);

        if ($f['op'] === Operator::In) {
            return "$col IN ($val)";
        }
        return "$col$op$val";
    }

    private function describeValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_string($value)) {
            return "'" . addslashes($value) . "'";
        }
        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }
        if (is_numeric($value)) {
            return (string) $value;
        }
        if ($value instanceof SetInterface) {
            return '...set...';
        }
        if ($value instanceof TableInterface) {
            return '...subquery...';
        }
        return '?';
    }

    // =========================================================================
    // Materialization - log when data is actually accessed
    // =========================================================================

    protected function materialize(string ...$additionalColumns): Traversable
    {
        $this->log($this->describeState('MATERIALIZE'));
        return parent::materialize(...$additionalColumns);
    }

    public function count(): int
    {
        $this->log($this->describeState('COUNT'));
        return parent::count();
    }

    // =========================================================================
    // Membership - log has() calls
    // =========================================================================

    public function has(object $member): bool
    {
        $props = [];
        foreach (get_object_vars($member) as $k => $v) {
            $props[] = "$k=" . $this->describeValue($v);
        }
        $memberDesc = '{' . implode(', ', $props) . '}';

        $result = parent::has($member);
        $resultStr = $result ? 'true' : 'false';

        $this->log("HAS $memberDesc → $resultStr");
        return $result;
    }

    public function load(string|int $rowId): ?object
    {
        $result = parent::load($rowId);
        $resultStr = $result !== null ? 'found' : 'null';
        $this->log("LOAD($rowId) → $resultStr");
        return $result;
    }

    // =========================================================================
    // Filter methods - track predicates and delegate
    // =========================================================================

    private function cloneWithFilter(string $column, Operator $op, mixed $value): self
    {
        $c = clone $this;
        $c->filters[] = ['column' => $column, 'op' => $op, 'value' => $value];
        return $c;
    }

    public function eq(string $column, int|float|string|null $value): TableInterface
    {
        $c = $this->cloneWithFilter($column, Operator::Eq, $value);
        $c->source = $this->source->eq($column, $value);
        return $c;
    }

    public function lt(string $column, int|float|string $value): TableInterface
    {
        $c = $this->cloneWithFilter($column, Operator::Lt, $value);
        $c->source = $this->source->lt($column, $value);
        return $c;
    }

    public function lte(string $column, int|float|string $value): TableInterface
    {
        $c = $this->cloneWithFilter($column, Operator::Lte, $value);
        $c->source = $this->source->lte($column, $value);
        return $c;
    }

    public function gt(string $column, int|float|string $value): TableInterface
    {
        $c = $this->cloneWithFilter($column, Operator::Gt, $value);
        $c->source = $this->source->gt($column, $value);
        return $c;
    }

    public function gte(string $column, int|float|string $value): TableInterface
    {
        $c = $this->cloneWithFilter($column, Operator::Gte, $value);
        $c->source = $this->source->gte($column, $value);
        return $c;
    }

    public function in(string $column, SetInterface $values): TableInterface
    {
        $c = $this->cloneWithFilter($column, Operator::In, $values);
        $c->source = $this->source->in($column, $values);
        return $c;
    }

    public function like(string $column, string $pattern): TableInterface
    {
        $c = $this->cloneWithFilter($column, Operator::Like, $pattern);
        $c->source = $this->source->like($column, $pattern);
        return $c;
    }

    // =========================================================================
    // Ordering and pagination
    // =========================================================================

    public function order(?string $spec): TableInterface
    {
        $c = clone $this;
        $c->orderSpec = $spec;
        $c->source = $this->source->order($spec);
        return $c;
    }

    public function limit(?int $n): TableInterface
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

    // =========================================================================
    // Column projection
    // =========================================================================

    public function columns(string ...$columns): TableInterface
    {
        $c = clone $this;
        $c->source = $this->source->columns(...$columns);
        return $c;
    }
}
