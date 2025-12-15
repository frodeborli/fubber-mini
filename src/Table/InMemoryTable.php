<?php

namespace mini\Table;

use SQLite3;
use Traversable;

/**
 * SQLite-backed in-memory table implementation
 *
 * Intended as a ground-truth oracle for testing - all operations are
 * translated to SQL and executed by SQLite, which provides well-defined
 * semantics for filtering, sorting, and set operations.
 *
 * ```php
 * $table = new InMemoryTable(
 *     new ColumnDef('id', ColumnType::Int, IndexType::Primary),
 *     new ColumnDef('name', ColumnType::Text, IndexType::Index),
 *     new ColumnDef('age', ColumnType::Int),
 * );
 *
 * $table->insert(['id' => 1, 'name' => 'Alice', 'age' => 30]);
 * $table->insert(['id' => 2, 'name' => 'Bob', 'age' => 25]);
 *
 * foreach ($table->gt('age', 20)->order('name ASC') as $id => $row) {
 *     echo "$row->name is $row->age\n";
 * }
 * ```
 */
class InMemoryTable extends AbstractTable implements MutableTableInterface
{
    private SQLite3 $db;
    private string $tableName = 'data';

    /** @var array{column: string, op: string, value: mixed, paramName: string}[] */
    private array $where = [];

    /**
     * OR groups - when non-empty, WHERE becomes: (group1) OR (group2) OR ...
     * Each group is an array of conditions that are ANDed together.
     * @var array[][]
     */
    private array $orGroups = [];

    /** @var OrderDef[] */
    private array $orderBy = [];

    /** @var int Counter for unique parameter names */
    private int $paramCounter = 0;

    /**
     * Create a new in-memory table with the given schema
     */
    public function __construct(ColumnDef ...$columns)
    {
        if (empty($columns)) {
            throw new \InvalidArgumentException('InMemoryTable requires at least one column');
        }

        parent::__construct(...$columns);

        $this->db = new SQLite3(':memory:');
        $this->db->enableExceptions(true);
        $this->bufferingDisabled = true;

        $this->createTable($columns);
    }

    /**
     * Share database connection on clone (filters are query-level state)
     */
    public function __clone()
    {
        parent::__clone();
        // Keep same $db reference - clones share the data
        // Keep paramCounter to ensure unique parameter names across filter chain
    }

    /**
     * Quote a SQLite identifier (column/table name).
     */
    private function quoteIdentifier(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    /**
     * Create the SQLite table with proper schema
     */
    private function createTable(array $columns): void
    {
        $colDefs = [];
        $indexes = [];
        $primaryKey = null;

        foreach ($columns as $col) {
            $def = $this->quoteIdentifier($col->name) . ' ' . $col->type->sqlType();

            if ($col->index === IndexType::Primary) {
                // INTEGER PRIMARY KEY becomes rowid alias in SQLite
                $def .= ' PRIMARY KEY';
                $primaryKey = $col->name;
            }

            $colDefs[] = $def;

            // Handle non-primary indexes
            if ($col->index === IndexType::Index || $col->index === IndexType::Unique) {
                $indexCols = [$col->name, ...$col->indexWith];
                $indexName = $this->quoteIdentifier('idx_' . implode('_', $indexCols));
                $unique = $col->index === IndexType::Unique ? 'UNIQUE ' : '';
                $indexes[] = "CREATE {$unique}INDEX {$indexName} ON {$this->tableName} ("
                    . implode(', ', array_map(fn($c) => $this->quoteIdentifier($c), $indexCols))
                    . ')';
            }
        }

        $sql = "CREATE TABLE {$this->tableName} (" . implode(', ', $colDefs) . ')';
        $this->db->exec($sql);

        foreach ($indexes as $indexSql) {
            $this->db->exec($indexSql);
        }
    }

    // =========================================================================
    // MutableTableInterface
    // =========================================================================

    public function insert(array $row): int|string
    {
        $columns = [];
        $placeholders = [];
        $values = [];

        foreach ($row as $col => $value) {
            $columns[] = $this->quoteIdentifier($col);
            $placeholders[] = '?';
            $values[] = $value;
        }

        $sql = "INSERT INTO {$this->tableName} (" . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';

        $stmt = $this->db->prepare($sql);
        foreach ($values as $i => $value) {
            $stmt->bindValue($i + 1, $value);
        }
        $stmt->execute();

        return $this->db->lastInsertRowID();
    }

    public function update(array $changes): int
    {
        $sets = [];
        $params = [];
        $i = 0;

        foreach ($changes as $col => $value) {
            $paramName = ':set_' . ($i++);
            $sets[] = $this->quoteIdentifier($col) . ' = ' . $paramName;
            $params[$paramName] = $value;
        }

        $sql = "UPDATE {$this->tableName} SET " . implode(', ', $sets);

        [$whereSql, $whereParams] = $this->buildWhereClause();
        if ($whereSql) {
            $sql .= ' WHERE ' . $whereSql;
            $params = array_merge($params, $whereParams);
        }

        $stmt = $this->db->prepare($sql);
        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value);
        }
        $stmt->execute();

        return $this->db->changes();
    }

    public function delete(): int
    {
        $sql = "DELETE FROM {$this->tableName}";

        [$whereSql, $whereParams] = $this->buildWhereClause();
        if ($whereSql) {
            $sql .= ' WHERE ' . $whereSql;
        }

        $stmt = $this->db->prepare($sql);
        foreach ($whereParams as $name => $value) {
            $stmt->bindValue($name, $value);
        }
        $stmt->execute();

        return $this->db->changes();
    }

    // =========================================================================
    // Filter methods - build WHERE state
    // =========================================================================

    public function eq(string $column, int|float|string|null $value): TableInterface
    {
        $clone = clone $this;
        $paramName = ':p' . (++$clone->paramCounter);

        if ($value === null) {
            $clone->where[] = ['column' => $column, 'op' => 'IS', 'value' => null, 'paramName' => null];
        } else {
            $clone->where[] = ['column' => $column, 'op' => '=', 'value' => $value, 'paramName' => $paramName];
        }

        return $clone;
    }

    public function lt(string $column, int|float|string $value): TableInterface
    {
        $clone = clone $this;
        $paramName = ':p' . (++$clone->paramCounter);
        $clone->where[] = ['column' => $column, 'op' => '<', 'value' => $value, 'paramName' => $paramName];
        return $clone;
    }

    public function lte(string $column, int|float|string $value): TableInterface
    {
        $clone = clone $this;
        $paramName = ':p' . (++$clone->paramCounter);
        $clone->where[] = ['column' => $column, 'op' => '<=', 'value' => $value, 'paramName' => $paramName];
        return $clone;
    }

    public function gt(string $column, int|float|string $value): TableInterface
    {
        $clone = clone $this;
        $paramName = ':p' . (++$clone->paramCounter);
        $clone->where[] = ['column' => $column, 'op' => '>', 'value' => $value, 'paramName' => $paramName];
        return $clone;
    }

    public function gte(string $column, int|float|string $value): TableInterface
    {
        $clone = clone $this;
        $paramName = ':p' . (++$clone->paramCounter);
        $clone->where[] = ['column' => $column, 'op' => '>=', 'value' => $value, 'paramName' => $paramName];
        return $clone;
    }

    public function in(string $column, SetInterface $values): TableInterface
    {
        // Materialize the set values
        $members = [];
        foreach ($values as $row) {
            $cols = array_keys($values->getColumns());
            if (count($cols) === 1) {
                $members[] = $row->{$cols[0]};
            }
        }

        if (empty($members)) {
            return EmptyTable::from($this);
        }

        $clone = clone $this;
        $placeholders = [];
        $inParams = [];

        foreach ($members as $i => $member) {
            $paramName = ':p' . (++$clone->paramCounter);
            $placeholders[] = $paramName;
            $inParams[$paramName] = $member;
        }

        $clone->where[] = [
            'column' => $column,
            'op' => 'IN',
            'value' => $inParams,
            'paramName' => $placeholders,
        ];

        return $clone;
    }

    public function like(string $column, string $pattern): TableInterface
    {
        $clone = clone $this;
        $paramName = ':p' . (++$clone->paramCounter);
        $clone->where[] = ['column' => $column, 'op' => 'LIKE', 'value' => $pattern, 'paramName' => $paramName];
        return $clone;
    }

    // =========================================================================
    // Ordering
    // =========================================================================

    public function order(?string $spec): TableInterface
    {
        $orders = $spec ? OrderDef::parse($spec) : [];

        $clone = clone $this;
        $clone->orderBy = $orders;
        return $clone;
    }

    // =========================================================================
    // Set operations - implemented with SQL for same-DB tables
    // =========================================================================

    public function union(TableInterface $other): TableInterface
    {
        // For InMemoryTable with same DB, use SQL UNION
        if ($other instanceof self && $other->db === $this->db && $other->tableName === $this->tableName) {
            return $this->sqlUnion($other);
        }

        // Fall back to wrapper for different table types
        return new UnionTable($this, $other);
    }

    public function except(SetInterface $other): TableInterface
    {
        // For InMemoryTable with same DB, use SQL NOT IN
        if ($other instanceof self && $other->db === $this->db && $other->tableName === $this->tableName) {
            return $this->sqlExcept($other);
        }

        // Fall back to wrapper for different table types
        return new ExceptTable($this, $other);
    }

    public function or(TableInterface ...$predicates): TableInterface
    {
        // If any predicate is a bare Predicate (matches everything), OR is redundant
        foreach ($predicates as $p) {
            if ($p instanceof Predicate) {
                return $this;
            }
        }

        // Filter out EmptyTable predicates (they match nothing)
        $predicates = array_values(array_filter(
            $predicates,
            fn($p) => !$p instanceof EmptyTable
        ));

        // No predicates → nothing matches
        if (empty($predicates)) {
            return EmptyTable::from($this);
        }

        $clone = clone $this;

        // Start OR groups with current conditions (if any)
        if (!empty($clone->where)) {
            $clone->orGroups = [$clone->where];
            $clone->where = [];
        } elseif (empty($clone->orGroups)) {
            // No existing conditions - will only match predicate conditions
            $clone->orGroups = [];
        }

        // Extract conditions from each predicate and add as OR groups
        foreach ($predicates as $predicate) {
            $conditions = $this->extractPredicateConditions($predicate, $clone);
            if (!empty($conditions)) {
                $clone->orGroups[] = $conditions;
            }
        }

        return $clone;
    }

    /**
     * Create SQL UNION of two InMemoryTable queries on same DB
     */
    private function sqlUnion(self $other): self
    {
        // Create a clone that will use UNION in its query
        $clone = clone $this;

        // Combine conditions: (this WHERE) OR (other WHERE)
        $thisConditions = !empty($this->orGroups) ? $this->orGroups : ($this->where ? [$this->where] : []);
        $otherConditions = !empty($other->orGroups) ? $other->orGroups : ($other->where ? [$other->where] : []);

        // If either has no conditions, it matches everything
        if (empty($thisConditions) && empty($otherConditions)) {
            $clone->orGroups = [];
            $clone->where = [];
        } elseif (empty($thisConditions)) {
            // this matches everything, union is everything
            $clone->orGroups = [];
            $clone->where = [];
        } elseif (empty($otherConditions)) {
            // other matches everything, union is everything
            $clone->orGroups = [];
            $clone->where = [];
        } else {
            // Renumber other's parameters to avoid collision
            $otherConditions = $this->renumberConditions($otherConditions, $clone);

            // Merge OR groups
            $clone->orGroups = [...$thisConditions, ...$otherConditions];
            $clone->where = [];
        }

        // Clear ordering (union results need fresh ordering)
        $clone->orderBy = [];
        $clone->limit = null;
        $clone->offset = 0;

        return $clone;
    }

    /**
     * Renumber parameter names in condition groups to avoid collision
     */
    private function renumberConditions(array $groups, self $clone): array
    {
        $result = [];
        foreach ($groups as $group) {
            $newGroup = [];
            foreach ($group as $filter) {
                $newFilter = $filter;

                if ($filter['op'] === 'IN' && is_array($filter['paramName'])) {
                    // Renumber IN parameters
                    $newPlaceholders = [];
                    $newParams = [];
                    foreach ($filter['value'] as $oldName => $val) {
                        $newName = ':p' . (++$clone->paramCounter);
                        $newPlaceholders[] = $newName;
                        $newParams[$newName] = $val;
                    }
                    $newFilter['paramName'] = $newPlaceholders;
                    $newFilter['value'] = $newParams;
                } elseif ($filter['paramName'] !== null && $filter['op'] !== 'NOT_IN_SUBQUERY') {
                    // Renumber simple parameter
                    $newName = ':p' . (++$clone->paramCounter);
                    $newFilter['paramName'] = $newName;
                }

                $newGroup[] = $newFilter;
            }
            $result[] = $newGroup;
        }
        return $result;
    }

    /**
     * Create SQL EXCEPT (NOT IN) of two InMemoryTable queries on same DB
     */
    private function sqlExcept(self $other): self
    {
        $clone = clone $this;

        // Build the exclusion condition: _rowid_ NOT IN (SELECT _rowid_ FROM ... WHERE other_conditions)
        [$otherWhere, $otherParams] = $other->buildWhereClause();

        $subquery = "SELECT _rowid_ FROM {$this->tableName}";
        if ($otherWhere) {
            $subquery .= " WHERE $otherWhere";
        }

        // Add as a special NOT IN condition
        $clone->where[] = [
            'column' => '_rowid_',
            'op' => 'NOT_IN_SUBQUERY',
            'value' => $otherParams,
            'paramName' => $subquery,
        ];

        return $clone;
    }

    /**
     * Extract filter conditions from a predicate chain
     *
     * Walks up the FilteredTable chain and collects conditions with proper parameter names.
     */
    private function extractPredicateConditions(TableInterface $predicate, self $clone): array
    {
        $conditions = [];
        $current = $predicate;

        while ($current instanceof FilteredTable) {
            $col = $current->getFilterColumn();
            $op = $current->getFilterOperator();
            $val = $current->getFilterValue();

            $paramName = ':p' . (++$clone->paramCounter);

            $condition = match ($op) {
                Operator::Eq => $val === null
                    ? ['column' => $col, 'op' => 'IS', 'value' => null, 'paramName' => null]
                    : ['column' => $col, 'op' => '=', 'value' => $val, 'paramName' => $paramName],
                Operator::Lt => ['column' => $col, 'op' => '<', 'value' => $val, 'paramName' => $paramName],
                Operator::Lte => ['column' => $col, 'op' => '<=', 'value' => $val, 'paramName' => $paramName],
                Operator::Gt => ['column' => $col, 'op' => '>', 'value' => $val, 'paramName' => $paramName],
                Operator::Gte => ['column' => $col, 'op' => '>=', 'value' => $val, 'paramName' => $paramName],
                Operator::Like => ['column' => $col, 'op' => 'LIKE', 'value' => $val, 'paramName' => $paramName],
                Operator::In => $this->buildInCondition($col, $val, $clone),
            };

            $conditions[] = $condition;
            $current = $current->getSource();
        }

        return $conditions;
    }

    /**
     * Build IN condition from SetInterface
     */
    private function buildInCondition(string $column, SetInterface $values, self $clone): array
    {
        $members = [];
        foreach ($values as $row) {
            $cols = array_keys($values->getColumns());
            if (count($cols) === 1) {
                $members[] = $row->{$cols[0]};
            }
        }

        $placeholders = [];
        $inParams = [];
        foreach ($members as $member) {
            $paramName = ':p' . (++$clone->paramCounter);
            $placeholders[] = $paramName;
            $inParams[$paramName] = $member;
        }

        return [
            'column' => $column,
            'op' => 'IN',
            'value' => $inParams,
            'paramName' => $placeholders,
        ];
    }

    // =========================================================================
    // Query building
    // =========================================================================

    /**
     * Build WHERE clause and parameter bindings
     *
     * @return array{string, array<string, mixed>} [SQL fragment, parameters]
     */
    private function buildWhereClause(): array
    {
        // Handle OR groups
        if (!empty($this->orGroups)) {
            $groups = [];
            $params = [];

            foreach ($this->orGroups as $group) {
                [$groupSql, $groupParams] = $this->buildConditionGroup($group);
                if ($groupSql) {
                    $groups[] = "($groupSql)";
                    $params = array_merge($params, $groupParams);
                }
            }

            if (empty($groups)) {
                return ['', []];
            }

            return [implode(' OR ', $groups), $params];
        }

        // Handle simple AND conditions
        return $this->buildConditionGroup($this->where);
    }

    /**
     * Build a group of AND conditions
     *
     * @return array{string, array<string, mixed>}
     */
    private function buildConditionGroup(array $filters): array
    {
        if (empty($filters)) {
            return ['', []];
        }

        $conditions = [];
        $params = [];

        foreach ($filters as $filter) {
            $col = $this->quoteIdentifier($filter['column']);

            if ($filter['op'] === 'IS') {
                $conditions[] = "$col IS NULL";
            } elseif ($filter['op'] === 'IN') {
                $conditions[] = "$col IN (" . implode(', ', $filter['paramName']) . ')';
                foreach ($filter['value'] as $name => $val) {
                    $params[$name] = $val;
                }
            } elseif ($filter['op'] === 'NOT_IN_SUBQUERY') {
                // Special case: NOT IN with subquery
                $conditions[] = "$col NOT IN ({$filter['paramName']})";
                foreach ($filter['value'] as $name => $val) {
                    $params[$name] = $val;
                }
            } else {
                $conditions[] = "$col {$filter['op']} {$filter['paramName']}";
                $params[$filter['paramName']] = $filter['value'];
            }
        }

        return [implode(' AND ', $conditions), $params];
    }

    /**
     * Build ORDER BY clause
     */
    private function buildOrderByClause(): string
    {
        if (empty($this->orderBy)) {
            return '';
        }

        $parts = [];
        foreach ($this->orderBy as $order) {
            $col = $this->quoteIdentifier($order->column);
            $dir = $order->asc ? 'ASC' : 'DESC';
            $parts[] = "$col $dir";
        }

        return ' ORDER BY ' . implode(', ', $parts);
    }

    /**
     * Build LIMIT/OFFSET clause
     */
    private function buildLimitClause(): string
    {
        $sql = '';

        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
        }

        if ($this->offset > 0) {
            if ($this->limit === null) {
                $sql .= ' LIMIT -1';  // SQLite requires LIMIT with OFFSET
            }
            $sql .= ' OFFSET ' . $this->offset;
        }

        return $sql;
    }

    // =========================================================================
    // Materialization
    // =========================================================================

    protected function materialize(string ...$additionalColumns): Traversable
    {
        $visibleCols = array_keys($this->getColumns());
        $selectCols = array_unique([...$visibleCols, ...$additionalColumns]);

        // Use _rowid_ with alias to avoid conflict with INTEGER PRIMARY KEY columns
        $selectList = '_rowid_ AS __rowid__, ' . implode(', ', array_map(
            fn($c) => $this->quoteIdentifier($c),
            $selectCols
        ));

        $sql = "SELECT $selectList FROM {$this->tableName}";

        [$whereSql, $whereParams] = $this->buildWhereClause();
        if ($whereSql) {
            $sql .= ' WHERE ' . $whereSql;
        }

        $sql .= $this->buildOrderByClause();
        $sql .= $this->buildLimitClause();

        $stmt = $this->db->prepare($sql);
        foreach ($whereParams as $name => $value) {
            $stmt->bindValue($name, $value);
        }

        $result = $stmt->execute();

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rowid = $row['__rowid__'];
            unset($row['__rowid__']);

            yield $rowid => (object) $row;
        }
    }

    public function load(string|int $rowId): ?object
    {
        $visibleCols = array_keys($this->getColumns());
        $selectList = implode(', ', array_map(
            fn($c) => $this->quoteIdentifier($c),
            $visibleCols
        ));

        $sql = "SELECT $selectList FROM {$this->tableName} WHERE _rowid_ = :rowid";

        [$whereSql, $whereParams] = $this->buildWhereClause();
        if ($whereSql) {
            $sql = "SELECT $selectList FROM {$this->tableName} WHERE _rowid_ = :rowid AND ($whereSql)";
        }

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':rowid', $rowId);
        foreach ($whereParams as $name => $value) {
            $stmt->bindValue($name, $value);
        }

        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);

        return $row ? (object) $row : null;
    }

    public function count(): int
    {
        $sql = "SELECT COUNT(*) FROM {$this->tableName}";

        [$whereSql, $whereParams] = $this->buildWhereClause();
        if ($whereSql) {
            $sql .= ' WHERE ' . $whereSql;
        }

        // Apply limit/offset to count via subquery if needed
        if ($this->limit !== null || $this->offset > 0) {
            $innerSql = "SELECT 1 FROM {$this->tableName}";
            if ($whereSql) {
                $innerSql .= ' WHERE ' . $whereSql;
            }
            $innerSql .= $this->buildLimitClause();
            $sql = "SELECT COUNT(*) FROM ($innerSql)";
        }

        $stmt = $this->db->prepare($sql);
        foreach ($whereParams as $name => $value) {
            $stmt->bindValue($name, $value);
        }

        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_NUM);

        return (int) $row[0];
    }
}
