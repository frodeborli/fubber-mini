<?php

namespace mini\Database;

/**
 * Immutable query builder for composable SQL queries
 *
 * PRIMARY VALUE: Expert-level composition architecture
 * - Each method returns a NEW instance (immutability = no side effects)
 * - Build reusable, non-mutating query fragments
 * - Safely branch query logic without copying or mutation
 * - Encapsulate security (parameter binding) at the architectural level
 *
 * SECONDARY VALUE: Beginner-friendly safety
 * - IDE autocomplete for discoverability
 * - Safe-by-default (SQL injection protection built-in)
 * - No need to understand parameterized queries to use safely
 *
 * DEFAULT LIMIT (1000 rows):
 * - Follows industry standards (Google BigTable, cloud database services)
 * - Prevents accidental full table scans and memory exhaustion
 * - Forces intentional pagination for large result sets
 * - If you need more: explicitly call limit(10000) or limit(PHP_INT_MAX)
 * - Note: "Unlimited" queries (no limit) are guaranteed to break at scale
 * - This is a feature, not a limitation - it encourages better architecture
 *
 * Example - simple table queries:
 * ```php
 * // Define reusable base query
 * $active = db()->query('users')->eq('is_deleted', 0);
 *
 * // Branch without mutation
 * $verified = $active->eq('email_verified', 1);
 * $admins = $active->eq('role', 'admin');
 *
 * // Original unchanged - safe for reuse
 * foreach ($active as $user) { }
 * foreach ($verified as $user) { }
 * ```
 *
 * Example - complex queries with JOINs:
 * ```php
 * // Any SELECT query works - JOINs, subqueries, existing WHERE clauses
 * $query = db()->query('
 *     SELECT u.*, r.name as role_name
 *     FROM users u
 *     JOIN roles r ON r.id = u.role_id
 *     WHERE u.active = 1
 * ');
 *
 * // Still composable! Uses CTE to wrap base query safely
 * $admins = $query->eq('r.name', 'admin')->order('u.name')->limit(10);
 * ```
 *
 * Example - relationships:
 * ```php
 * class User {
 *     use ActiveRecordTrait;
 *
 *     /** @return PartialQuery<User> Friends of this user *\/
 *     public function friends(): PartialQuery {
 *         return db()->query('
 *             SELECT u.* FROM users u
 *             INNER JOIN friendships f ON f.friend_id = u.id AND f.user_id = ?
 *         ', [$this->id])->withEntityClass(User::class, false);
 *     }
 * }
 *
 * // Composable - add WHERE/ORDER/LIMIT
 * $onlineFriends = $user->friends()->eq('online', 1)->limit(10);
 * ```
 *
 * See also: mini\validator($user::class)->isInvalid($user) for validation,
 *           mini\metadata($user::class)->getDescription() for metadata
 *
 * @template T of array|object
 * @implements \IteratorAggregate<int, T>
 */
final class PartialQuery implements \IteratorAggregate
{
    private DatabaseInterface $db;
    private string $baseSql;
    private ?string $table = null;
    private array $params = [];
    private array $wheres = [];
    private ?string $orderBy = null;
    private int $limit = 1000;
    private int $offset = 0;
    private ?\Closure $hydrator = null;
    private ?string $entityClass = null;
    private array|false $entityConstructorArgs = false;

    /**
     * Create a PartialQuery from a base SELECT query
     *
     * Accepts any valid SELECT query. The query is wrapped in a CTE (Common Table
     * Expression) when building the final SQL, allowing WHERE/ORDER/LIMIT to be
     * safely appended to any query - even those with existing WHERE clauses.
     *
     * Examples:
     * ```php
     * // Simple query
     * new PartialQuery(db(), 'SELECT * FROM users');
     *
     * // Complex query with JOINs
     * new PartialQuery(db(), '
     *     SELECT u.*, c.name as class_name
     *     FROM users u
     *     JOIN classes c ON c.id = u.class_id
     * ');
     *
     * // Query with existing WHERE - still composable!
     * new PartialQuery(db(), '
     *     SELECT * FROM users WHERE role = ?
     * ', ['admin']);
     *
     * // All can be further composed
     * $query->eq('active', 1)->order('name')->limit(10);
     * ```
     *
     * @param DatabaseInterface $db Database connection
     * @param string $sql Any valid SELECT query
     * @param array $params Parameters for placeholders in the query
     */
    public function __construct(DatabaseInterface $db, string $sql, array $params = [])
    {
        $trimmed = ltrim($sql);
        if (stripos($trimmed, 'SELECT') !== 0) {
            throw new \InvalidArgumentException(
                'PartialQuery SQL must start with SELECT'
            );
        }

        $this->db = $db;
        $this->baseSql = $sql;
        $this->params = $params;
    }

    /**
     * Create a PartialQuery for a simple table
     *
     * Convenience method for simple table queries. Supports UPDATE/DELETE
     * operations via db()->update() and db()->delete().
     *
     * @param DatabaseInterface $db Database connection
     * @param string $table Table name (must be a valid identifier, no spaces or special chars)
     * @return self
     */
    public static function table(DatabaseInterface $db, string $table): self
    {
        // Validate table name - simple identifier only
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)) {
            throw new \InvalidArgumentException(
                "Invalid table name '{$table}'. Use the constructor for complex queries."
            );
        }

        $query = new self($db, "SELECT * FROM {$table}");
        $query->table = $table;
        return $query;
    }

    /**
     * Use an entity class for hydration
     *
     * The framework will hydrate rows into instances of the specified class
     * using reflection to set properties after construction.
     *
     * Constructor behavior:
     * - If $constructorArgs is an array: calls constructor with those arguments
     * - If $constructorArgs is false: skips constructor entirely (useful when constructor has required params)
     *
     * Properties are set via reflection AFTER construction, supporting:
     * - Public, protected, and private properties
     * - Property cache per iteration for efficiency
     *
     * Example with constructor:
     * ```php
     * class User {
     *     public function __construct(private PDO $db) {}
     * }
     * $users = db()->query('users')->withEntityClass(User::class, [db()->getPdo()]);
     * ```
     *
     * Example without constructor:
     * ```php
     * // Skip constructor - useful when constructor has required params
     * $users = db()->query('users')->withEntityClass(User::class, false);
     * ```
     *
     * @template TObject of object
     * @param class-string<TObject> $class Entity class name
     * @param array|false $constructorArgs Constructor arguments or false to skip constructor
     * @return PartialQuery<TObject> New instance with entity class
     */
    public function withEntityClass(string $class, array|false $constructorArgs = false): self
    {
        $new = clone $this;
        $new->entityClass = $class;
        $new->entityConstructorArgs = $constructorArgs;
        $new->hydrator = null;
        return $new;
    }

    /**
     * Use a custom hydrator closure
     *
     * Provides full control over object construction from database rows.
     * The closure receives column values as separate arguments (PDO::FETCH_FUNC style).
     *
     * Example:
     * ```php
     * $users = db()->query('users')->withHydrator(
     *     fn($id, $name, $email) => new User($id, $name, $email)
     * );
     * ```
     *
     * @template TObject of object
     * @param \Closure(...mixed):TObject $hydrator Closure with signature fn(...$columnValues):T
     * @return PartialQuery<TObject> New instance with custom hydrator
     */
    public function withHydrator(\Closure $hydrator): self
    {
        $new = clone $this;
        $new->hydrator = $hydrator;
        $new->entityClass = null;
        return $new;
    }

    /**
     * Add a WHERE clause with raw SQL
     *
     * @param string $sql SQL condition (e.g., "age >= ? AND age <= ?")
     * @param array $params Parameters to bind
     * @return self New instance with added WHERE clause
     */
    public function where(string $sql, array $params = []): self
    {
        $new = clone $this;
        $new->wheres[] = "($sql)";
        $new->params = array_merge($new->params, $params);
        return $new;
    }

    /**
     * Add WHERE column = value clause
     *
     * Handles NULL automatically (converts to IS NULL).
     *
     * @param string $column Column name
     * @param mixed $value Value to compare
     * @return self New instance with added WHERE clause
     */
    public function eq(string $column, mixed $value): self
    {
        if ($value === null) {
            return $this->where("$column IS NULL");
        }
        return $this->where("$column = ?", [$value]);
    }

    /**
     * Add WHERE column < value clause
     */
    public function lt(string $column, mixed $value): self
    {
        return $this->where("$column < ?", [$value]);
    }

    /**
     * Add WHERE column <= value clause
     */
    public function lte(string $column, mixed $value): self
    {
        return $this->where("$column <= ?", [$value]);
    }

    /**
     * Add WHERE column > value clause
     */
    public function gt(string $column, mixed $value): self
    {
        return $this->where("$column > ?", [$value]);
    }

    /**
     * Add WHERE column >= value clause
     */
    public function gte(string $column, mixed $value): self
    {
        return $this->where("$column >= ?", [$value]);
    }

    /**
     * Add WHERE column IN (...) clause
     *
     * @param string $column Column name
     * @param array $values Array of values for IN clause
     * @return self New instance with added WHERE clause
     */
    public function in(string $column, array $values): self
    {
        if (empty($values)) {
            // IN with empty array should match nothing
            return $this->where('1 = 0');
        }
        $placeholders = implode(',', array_fill(0, count($values), '?'));
        return $this->where("$column IN ($placeholders)", $values);
    }

    /**
     * Set ORDER BY clause (overwrites previous)
     *
     * Accepts any SQL ORDER BY specification:
     * - Single column: ->order('name') or ->order('name ASC')
     * - Multi-column: ->order('priority DESC, created_at ASC')
     * - Complex expressions: ->order('FIELD(status, "active", "pending"), name')
     *
     * The SQL is used directly in the query without modification.
     * Direction (ASC/DESC) must be included in the string if needed.
     *
     * @param string $orderSpec SQL ORDER BY specification (without "ORDER BY" keyword)
     * @return self New instance with ORDER BY
     */
    public function order(string $orderSpec): self
    {
        $new = clone $this;
        $new->orderBy = $orderSpec;
        return $new;
    }

    /**
     * Set LIMIT (overwrites previous)
     *
     * @param int $limit Maximum number of rows
     * @return self New instance with LIMIT
     */
    public function limit(int $limit): self
    {
        $new = clone $this;
        $new->limit = $limit;
        return $new;
    }

    /**
     * Set OFFSET (overwrites previous)
     *
     * @param int $offset Number of rows to skip
     * @return self New instance with OFFSET
     */
    public function offset(int $offset): self
    {
        $new = clone $this;
        $new->offset = $offset;
        return $new;
    }

    /**
     * Build SQL query string
     *
     * Uses a CTE (Common Table Expression) to wrap the base query, allowing
     * WHERE/ORDER/LIMIT to be safely appended to any query.
     *
     * @return string Complete SQL query
     */
    private function buildSql(): string
    {
        // If no additional clauses, return base SQL with just LIMIT
        if (empty($this->wheres) && $this->orderBy === null && $this->offset === 0) {
            return $this->baseSql . $this->buildLimitClause();
        }

        // Wrap base query in CTE for safe composition
        $sql = "WITH _q AS ({$this->baseSql}) SELECT * FROM _q";

        if ($this->wheres) {
            $sql .= ' WHERE ' . implode(' AND ', $this->wheres);
        }

        if ($this->orderBy) {
            $sql .= " ORDER BY {$this->orderBy}";
        }

        $sql .= $this->buildLimitClause();

        return $sql;
    }

    /**
     * Build LIMIT clause according to database dialect
     */
    private function buildLimitClause(): string
    {
        $dialect = $this->db->getDialect();

        return match($dialect) {
            SqlDialect::MySQL => $this->offset > 0
                ? " LIMIT {$this->offset}, {$this->limit}"
                : " LIMIT {$this->limit}",

            SqlDialect::SqlServer => $this->offset > 0
                ? " OFFSET {$this->offset} ROWS FETCH NEXT {$this->limit} ROWS ONLY"
                : " OFFSET 0 ROWS FETCH NEXT {$this->limit} ROWS ONLY",

            SqlDialect::Postgres,
            SqlDialect::Sqlite,
            SqlDialect::Oracle,
            SqlDialect::Generic => $this->offset > 0
                ? " LIMIT {$this->limit} OFFSET {$this->offset}"
                : " LIMIT {$this->limit}",
        };
    }

    /**
     * Fetch first row or null
     *
     * Uses limit(1) internally for efficiency.
     *
     * @return T|null Single row or null if no results
     */
    public function one(): mixed
    {
        foreach ($this->limit(1) as $result) {
            return $result;
        }
        return null;
    }

    /**
     * Fetch first column from all rows
     *
     * Returns the first column of the result set, regardless of
     * which columns were selected.
     *
     * @return array Array of scalar values
     */
    public function column(): array
    {
        return $this->db->queryColumn($this->buildSql(), $this->params);
    }

    /**
     * Count total matching rows
     *
     * Wraps the query in a CTE and counts. Ignores ORDER BY and LIMIT/OFFSET.
     *
     * @return int Number of matching rows
     */
    public function count(): int
    {
        // If no additional WHERE clauses, count base query directly
        if (empty($this->wheres)) {
            $sql = "SELECT COUNT(*) FROM ({$this->baseSql}) AS _count";
            return (int)$this->db->queryField($sql, $this->params);
        }

        // Use CTE to apply WHERE clauses
        $sql = "WITH _q AS ({$this->baseSql}) SELECT COUNT(*) FROM _q WHERE " . implode(' AND ', $this->wheres);

        return (int)$this->db->queryField($sql, $this->params);
    }

    /**
     * Make query iterable (foreach support)
     *
     * Streams results for memory efficiency.
     * Use iterator_to_array() if you need an actual array.
     *
     * @return \Generator<int, T, mixed, void> Iterator over results
     */
    public function getIterator(): \Traversable
    {
        $rows = $this->db->rawQuery($this->buildSql(), $this->params);

        // No hydration - yield rows as-is
        if ($this->entityClass === null && $this->hydrator === null) {
            yield from $rows;
            return;
        }

        // Entity class hydration with reflection
        if ($this->entityClass !== null) {
            $class = $this->entityClass;
            $args = $this->entityConstructorArgs;
            $reflectionCache = [];

            try {
                $refClass = new \ReflectionClass($class);

                foreach ($rows as $row) {
                    // Create instance with or without constructor
                    if ($args === false) {
                        $obj = $refClass->newInstanceWithoutConstructor();
                    } else {
                        $obj = $refClass->newInstanceArgs($args);
                    }

                    // Set properties via reflection
                    foreach ($row as $propertyName => $value) {
                        // Cache reflection property on first access
                        $refProp = $reflectionCache[$propertyName] ?? ($reflectionCache[$propertyName] = new \ReflectionProperty($class, $propertyName));
                        $refProp->setValue($obj, $value);
                    }

                    yield $obj;
                }
            } catch (\ReflectionException $e) {
                throw new \RuntimeException(
                    "Failed to hydrate class '{$class}': " . $e->getMessage(),
                    0,
                    $e
                );
            }
            return;
        }

        // Custom closure hydration
        if ($this->hydrator !== null) {
            $hydrator = $this->hydrator;
            foreach ($rows as $row) {
                yield $hydrator(...array_values($row));
            }
            return;
        }
    }

    /**
     * Get the table name for UPDATE/DELETE operations
     *
     * Only available for queries created via db()->query('tablename') or PartialQuery::table().
     * Queries created with full SELECT SQL cannot be used with UPDATE/DELETE.
     *
     * @return string Table name
     * @throws \LogicException If query was not created from a simple table name
     */
    public function getTable(): string
    {
        if ($this->table === null) {
            throw new \LogicException(
                'Cannot get table name from a PartialQuery created with SELECT SQL. ' .
                "UPDATE/DELETE operations require queries created via db()->query('tablename')."
            );
        }
        return $this->table;
    }

    /**
     * Get WHERE clause SQL and parameters
     *
     * Used by DatabaseInterface for DELETE/UPDATE operations.
     * Returns the WHERE clause without the "WHERE" keyword.
     *
     * @return array{sql: string, params: array}
     */
    public function getWhere(): array
    {
        if (empty($this->wheres)) {
            return ['sql' => '', 'params' => []];
        }

        return [
            'sql' => implode(' AND ', $this->wheres),
            'params' => $this->params
        ];
    }

    /**
     * Get LIMIT value
     *
     * Used by DatabaseInterface for DELETE/UPDATE operations.
     *
     * @return int Limit value
     */
    public function getLimit(): int
    {
        return $this->limit;
    }

    /**
     * Debug information for var_dump/print_r
     *
     * @return array SQL query and parameters
     */
    public function __debugInfo(): array
    {
        return [
            'sql' => $this->buildSql(),
            'params' => $this->params,
        ];
    }

    /**
     * Convert to string for logging/debugging
     *
     * Returns executable SQL with quoted values (not parameterized).
     * Uses DatabaseInterface::quote() for safe value escaping.
     *
     * WARNING: For debugging only - use parameterized queries in production.
     *
     * @return string Executable SQL query
     */
    public function __toString(): string
    {
        $sql = $this->buildSql();
        $params = $this->params;

        foreach ($params as $param) {
            $pos = strpos($sql, '?');
            if ($pos === false) break;

            $quoted = $this->db->quote($param);
            $sql = substr_replace($sql, $quoted, $pos, 1);
        }

        return $sql;
    }
}
