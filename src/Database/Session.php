<?php

namespace mini\Database;

use Closure;
use mini\Table\Contracts\TableInterface;

/**
 * Database session with isolated temporary tables
 *
 * A Session wraps a VirtualDatabase engine and provides:
 * - Isolated temporary table storage (CREATE TEMPORARY TABLE)
 * - Session-scoped state (future: prepared statement cache, transaction state)
 *
 * In fiber/coroutine environments, each fiber should have its own Session
 * to prevent temp table collisions. The mini\vdb() helper automatically
 * manages session-per-fiber via Lifetime::Scoped.
 *
 * ```php
 * // Get session (automatically scoped per fiber)
 * $db = mini\vdb();
 * $db->exec("CREATE TEMPORARY TABLE tmp (id INTEGER, val TEXT)");
 * $db->exec("INSERT INTO tmp VALUES (1, 'test')");
 * $result = $db->query("SELECT * FROM tmp");
 * // Temp table is automatically cleaned up when fiber ends
 * ```
 */
class Session implements DatabaseInterface
{
    /** @var array<string, TableInterface> Session-local temporary tables */
    private array $tempTables = [];

    /** @var string|null Last insert ID */
    private ?string $lastInsertId = null;

    public function __construct(
        private readonly VirtualDatabase $engine,
    ) {}

    /**
     * Get the underlying VirtualDatabase engine
     */
    public function getEngine(): VirtualDatabase
    {
        return $this->engine;
    }

    /**
     * Get a temporary table by name
     *
     * @internal Used by VirtualDatabase for table resolution
     */
    public function getTempTable(string $name): ?TableInterface
    {
        return $this->tempTables[$name] ?? null;
    }

    /**
     * Register a temporary table
     *
     * @internal Used by VirtualDatabase when executing CREATE TEMPORARY TABLE
     */
    public function setTempTable(string $name, TableInterface $table): void
    {
        $this->tempTables[$name] = $table;
    }

    /**
     * Drop a temporary table
     *
     * @internal Used by VirtualDatabase when executing DROP TABLE on temp tables
     */
    public function dropTempTable(string $name): bool
    {
        if (isset($this->tempTables[$name])) {
            unset($this->tempTables[$name]);
            return true;
        }
        return false;
    }

    /**
     * Check if a temporary table exists
     */
    public function hasTempTable(string $name): bool
    {
        return isset($this->tempTables[$name]);
    }

    /**
     * Get all temporary table names
     */
    public function getTempTableNames(): array
    {
        return array_keys($this->tempTables);
    }

    // =========================================================================
    // DatabaseInterface implementation - delegates to engine with session context
    // =========================================================================

    public function query(string $sql, array $params = []): Query
    {
        return $this->engine->queryWithSession($sql, $params, $this);
    }

    public function queryOne(string $sql, array $params = []): ?object
    {
        foreach ($this->query($sql, $params) as $row) {
            return $row;
        }
        return null;
    }

    public function queryField(string $sql, array $params = []): mixed
    {
        $row = $this->queryOne($sql, $params);
        if ($row === null) {
            return null;
        }
        $values = get_object_vars($row);
        return reset($values);
    }

    public function queryColumn(string $sql, array $params = []): array
    {
        $result = [];
        foreach ($this->query($sql, $params) as $row) {
            $values = get_object_vars($row);
            $result[] = reset($values);
        }
        return $result;
    }

    public function exec(string $sql, array $params = []): int
    {
        $result = $this->engine->execWithSession($sql, $params, $this);
        $this->lastInsertId = $this->engine->lastInsertId();
        return $result;
    }

    public function lastInsertId(): ?string
    {
        return $this->lastInsertId;
    }

    public function tableExists(string $tableName): bool
    {
        // Check temp tables first, then engine
        if (isset($this->tempTables[$tableName])) {
            return true;
        }
        return $this->engine->tableExists($tableName);
    }

    public function transaction(Closure $task): mixed
    {
        // VDB doesn't support real transactions, but we implement the interface
        return $this->engine->transaction($task);
    }

    public function getDialect(): SqlDialect
    {
        return $this->engine->getDialect();
    }

    public function quote(mixed $value): string
    {
        return $this->engine->quote($value);
    }

    public function quoteIdentifier(string $identifier): string
    {
        return $this->engine->quoteIdentifier($identifier);
    }

    public function delete(Query|PartialQuery $query): int
    {
        return $this->engine->delete($query);
    }

    public function update(Query|PartialQuery $query, string|array $set, array $params = []): int
    {
        return $this->engine->update($query, $set, $params);
    }

    public function insert(string $table, array $data): string
    {
        // Check if inserting into a temp table
        if (isset($this->tempTables[$table])) {
            $tempTable = $this->tempTables[$table];
            if ($tempTable instanceof \mini\Table\Contracts\MutableTableInterface) {
                $id = $tempTable->insert($data);
                $this->lastInsertId = (string) $id;
                return $this->lastInsertId;
            }
            throw new \RuntimeException("Temporary table '$table' is not mutable");
        }

        $result = $this->engine->insert($table, $data);
        $this->lastInsertId = $result;
        return $result;
    }

    public function upsert(string $table, array $data, string ...$conflictColumns): int
    {
        return $this->engine->upsert($table, $data, ...$conflictColumns);
    }

    public function withTables(array $tables): DatabaseInterface
    {
        return $this->engine->withTables($tables);
    }

    public function getSchema(): TableInterface
    {
        // TODO: Include temp tables in schema
        return $this->engine->getSchema();
    }
}
