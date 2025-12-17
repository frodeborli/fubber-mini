<?php

namespace mini\Database;

use mini\Database\DatabaseInterface;
use mini\Mini;
use PDO;
use PDOException;
use Exception;
use function mini\sqlval;

/**
 * PDO-based database implementation
 *
 * Wraps any PDO instance with a clean, ergonomic API that makes database
 * operations pleasant while supporting any PDO-compatible database.
 * Fetches PDO from container lazily to ensure proper scoping.
 */
class PDODatabase implements DatabaseInterface
{
    private ?PDO $pdo = null;
    private bool $inTransaction = false;

    /**
     * Get PDO instance from container (lazy initialization)
     */
    private function lazyPdo(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = Mini::$mini->get(PDO::class);
        }
        return $this->pdo;
    }

    /**
     * Execute a SELECT query and return results as ResultSet
     */
    public function query(string $sql, array $params = []): ResultSetInterface
    {
        return new ResultSet((function () use ($sql, $params) {
            try {
                $stmt = $this->lazyPdo()->prepare($sql);
                $stmt->execute(array_map(sqlval(...), $params));

                while ($row = $stmt->fetch(PDO::FETCH_OBJ)) {
                    yield $row;
                }
            } catch (PDOException $e) {
                throw new Exception("Query failed: " . $e->getMessage());
            }
        })());
    }

    /**
     * Create a PartialQuery for composable query building
     */
    public function partialQuery(string $table, ?string $sql = null, array $params = []): PartialQuery
    {
        return PartialQuery::from($this, $table, $sql, $params);
    }

    /**
     * Execute query and return first row only as object
     */
    public function queryOne(string $sql, array $params = []): ?object
    {
        try {
            $stmt = $this->lazyPdo()->prepare($sql);
            $stmt->execute(array_map(sqlval(...), $params));
            $result = $stmt->fetch(PDO::FETCH_OBJ);
            return $result ?: null;
        } catch (PDOException $e) {
            throw new Exception("Query one failed: " . $e->getMessage());
        }
    }

    /**
     * Execute query and return first column of first row
     */
    public function queryField(string $sql, array $params = []): mixed
    {
        try {
            $stmt = $this->lazyPdo()->prepare($sql);
            $stmt->execute(array_map(sqlval(...), $params));
            return $stmt->fetchColumn();
        } catch (PDOException $e) {
            throw new Exception("Query field failed: " . $e->getMessage());
        }
    }

    /**
     * Execute query and return first column values as array
     */
    public function queryColumn(string $sql, array $params = []): array
    {
        try {
            $stmt = $this->lazyPdo()->prepare($sql);
            $stmt->execute(array_map(sqlval(...), $params));
            return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
        } catch (PDOException $e) {
            throw new Exception("Query column failed: " . $e->getMessage());
        }
    }

    /**
     * Execute a statement (INSERT, UPDATE, DELETE)
     */
    public function exec(string $sql, array $params = []): int
    {
        try {
            $stmt = $this->lazyPdo()->prepare($sql);
            $stmt->execute(array_map(sqlval(...), $params));
            return $stmt->rowCount();
        } catch (PDOException $e) {
            throw new Exception("Exec failed: " . $e->getMessage());
        }
    }

    /**
     * Get the last inserted row ID
     */
    public function lastInsertId(): ?string
    {
        $id = $this->lazyPdo()->lastInsertId();
        return $id !== false ? $id : null;
    }

    /**
     * Check if a table exists in the database
     *
     * Note: This implementation uses a database-agnostic approach that should
     * work with most SQL databases. For database-specific optimizations,
     * consider creating specialized implementations.
     */
    public function tableExists(string $tableName): bool
    {
        try {
            // Use INFORMATION_SCHEMA which is supported by most databases
            $result = $this->queryField(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = ?",
                [$tableName]
            );

            if ($result !== null) {
                return (int)$result > 0;
            }

            // Fallback for databases that don't support INFORMATION_SCHEMA (like SQLite)
            // Try to query the table and see if it fails
            $this->lazyPdo()->prepare("SELECT 1 FROM {$tableName} LIMIT 0")->execute();
            return true;
        } catch (PDOException $e) {
            // If the query failed, the table likely doesn't exist
            return false;
        }
    }

    /**
     * Execute a closure within a database transaction
     *
     * @throws \RuntimeException If called while already in a transaction
     */
    public function transaction(\Closure $task): mixed
    {
        if ($this->inTransaction) {
            throw new \RuntimeException(
                "Already in a transaction. Nested transactions are not supported. " .
                "Restructure your code to use a single transaction block."
            );
        }

        try {
            $this->lazyPdo()->beginTransaction();
            $this->inTransaction = true;
        } catch (PDOException $e) {
            throw new \RuntimeException("Failed to start transaction: " . $e->getMessage(), 0, $e);
        }

        try {
            $result = $task($this);
            $this->lazyPdo()->commit();
            $this->inTransaction = false;
            return $result;

        } catch (\Throwable $e) {
            try {
                $this->lazyPdo()->rollBack();
            } catch (PDOException $rollbackException) {
                error_log("Transaction rollback failed: " . $rollbackException->getMessage());
            }
            $this->inTransaction = false;
            throw $e;
        }
    }

    /**
     * Get the underlying PDO instance for advanced operations
     *
     * This allows access to PDO-specific functionality when needed,
     * while keeping the common operations clean through the interface.
     *
     * @return PDO The underlying PDO instance
     */
    public function getPdo(): PDO
    {
        return $this->lazyPdo();
    }

    /**
     * Get the SQL dialect for this database connection
     */
    public function getDialect(): SqlDialect
    {
        $driver = $this->lazyPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME);

        return match($driver) {
            'mysql' => SqlDialect::MySQL,
            'pgsql' => SqlDialect::Postgres,
            'sqlite' => SqlDialect::Sqlite,
            'sqlsrv', 'mssql', 'dblib' => SqlDialect::SqlServer,
            'oci' => SqlDialect::Oracle,
            default => SqlDialect::Generic,
        };
    }

    /**
     * Quotes a value for safe use in SQL query strings
     */
    public function quote(mixed $value): string
    {
        if ($value === null) return 'NULL';
        if (is_int($value)) return $this->lazyPdo()->quote($value, \PDO::PARAM_INT);
        if (is_bool($value)) return $this->lazyPdo()->quote($value, \PDO::PARAM_BOOL);
        return $this->lazyPdo()->quote($value, \PDO::PARAM_STR);
    }

    /**
     * Quotes an identifier (table name, column name) for safe use in SQL
     */
    public function quoteIdentifier(string $identifier): string
    {
        $dialect = $this->getDialect();

        // Handle dotted identifiers (e.g., "table.column")
        if (str_contains($identifier, '.')) {
            return implode('.', array_map(fn($part) => $this->quoteIdentifier($part), explode('.', $identifier)));
        }

        return match($dialect) {
            SqlDialect::MySQL => '`' . str_replace('`', '``', $identifier) . '`',
            SqlDialect::SqlServer => '[' . str_replace(']', ']]', $identifier) . ']',
            default => '"' . str_replace('"', '""', $identifier) . '"',
        };
    }

    /**
     * Delete rows matching a partial query
     */
    public function delete(PartialQuery $query): int
    {
        $table = $query->getTable();
        $ctes = $query->getCTEs();
        $where = $query->getWhere();

        // Require WHERE clause for safety
        // Use db()->exec('DELETE FROM table') or TRUNCATE for mass deletes
        if (empty($where['sql'])) {
            throw new \InvalidArgumentException(
                "DELETE requires a WHERE clause. Use db()->exec('DELETE FROM {$table}') for mass deletes."
            );
        }

        $sql = $ctes['sql'] . "DELETE FROM {$table}";
        $sql .= " WHERE {$where['sql']}";
        $limit = $query->getLimit();
        if ($limit !== null) {
            $sql .= " LIMIT {$limit}";
        }

        $params = array_merge($ctes['params'], $where['params']);

        try {
            $stmt = $this->lazyPdo()->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            throw new Exception("Delete failed: " . $e->getMessage());
        }
    }

    /**
     * Update rows matching a partial query
     */
    public function update(PartialQuery $query, string|array $set, array $params = []): int
    {
        $table = $query->getTable();
        $ctes = $query->getCTEs();
        $where = $query->getWhere();

        if (is_string($set)) {
            // Raw SQL expression with optional params
            $sql = $ctes['sql'] . "UPDATE {$table} SET {$set}";
            $params = array_merge($ctes['params'], $params, $where['params']);
        } else {
            // Array of column => value assignments
            $setParts = [];
            $setParams = [];

            foreach ($set as $column => $value) {
                $setParts[] = "$column = ?";
                $setParams[] = $value;
            }

            $sql = $ctes['sql'] . "UPDATE {$table} SET " . implode(', ', $setParts);
            $params = array_merge($ctes['params'], $setParams, $where['params']);
        }

        if ($where['sql']) {
            $sql .= " WHERE {$where['sql']}";
        }

        $limit = $query->getLimit();
        if ($limit !== null) {
            $sql .= " LIMIT {$limit}";
        }

        try {
            $stmt = $this->lazyPdo()->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            throw new Exception("Update failed: " . $e->getMessage());
        }
    }

    /**
     * Insert a new row into a table
     */
    public function insert(string $table, array $data): string
    {
        if (empty($data)) {
            throw new \InvalidArgumentException("Data array cannot be empty for insert");
        }

        $columns = array_keys($data);
        $values = array_values($data);
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        $columnList = implode(', ', $columns);
        $sql = "INSERT INTO $table ($columnList) VALUES ($placeholders)";

        try {
            $stmt = $this->lazyPdo()->prepare($sql);
            $stmt->execute($values);
            return $this->lastInsertId() ?? '';
        } catch (PDOException $e) {
            throw new Exception("Insert failed: " . $e->getMessage());
        }
    }

    /**
     * Insert a row, or update if conflict on unique columns
     */
    public function upsert(string $table, array $data, string ...$conflictColumns): int
    {
        if (empty($data)) {
            throw new \InvalidArgumentException("Data array cannot be empty for upsert");
        }

        if (empty($conflictColumns)) {
            throw new \InvalidArgumentException("At least one conflict column must be specified for upsert");
        }

        $columns = array_keys($data);
        $values = array_values($data);
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        $dialect = $this->getDialect();

        // Build dialect-specific UPSERT SQL
        $sql = match($dialect) {
            SqlDialect::MySQL => $this->buildMySQLUpsert($table, $columns, $placeholders, $conflictColumns),
            SqlDialect::Postgres => $this->buildPostgresUpsert($table, $columns, $placeholders, $conflictColumns),
            SqlDialect::Sqlite => $this->buildSqliteUpsert($table, $columns, $placeholders, $conflictColumns),
            SqlDialect::SqlServer => $this->buildSqlServerUpsert($table, $columns, $values, $conflictColumns),
            SqlDialect::Oracle => $this->buildOracleUpsert($table, $columns, $values, $conflictColumns),
            SqlDialect::Generic => $this->buildPostgresUpsert($table, $columns, $placeholders, $conflictColumns), // Use Postgres syntax as generic
        };

        try {
            // SQL Server and Oracle MERGE use values directly in SQL, others use placeholders
            if ($dialect === SqlDialect::SqlServer || $dialect === SqlDialect::Oracle) {
                $stmt = $this->lazyPdo()->prepare($sql);
                $stmt->execute();
            } else {
                // MySQL, Postgres, Sqlite use placeholders only for INSERT
                // UPDATE uses VALUES() for MySQL or EXCLUDED for Postgres/Sqlite
                $stmt = $this->lazyPdo()->prepare($sql);
                $stmt->execute($values);
            }

            return $stmt->rowCount();
        } catch (PDOException $e) {
            throw new Exception("Upsert failed: " . $e->getMessage());
        }
    }

    private function buildMySQLUpsert(string $table, array $columns, string $placeholders, array $conflictColumns): string
    {
        $columnList = implode(', ', $columns);

        // Build UPDATE clause for all columns except conflict columns
        $updateParts = [];
        foreach ($columns as $column) {
            $updateParts[] = "$column = VALUES($column)";
        }
        $updateClause = implode(', ', $updateParts);

        return "INSERT INTO $table ($columnList) VALUES ($placeholders) ON DUPLICATE KEY UPDATE $updateClause";
    }

    private function buildPostgresUpsert(string $table, array $columns, string $placeholders, array $conflictColumns): string
    {
        $columnList = implode(', ', $columns);
        $conflictList = implode(', ', $conflictColumns);

        // Build UPDATE clause using EXCLUDED
        $updateParts = [];
        foreach ($columns as $column) {
            // Don't update conflict columns
            if (!in_array($column, $conflictColumns)) {
                $updateParts[] = "$column = EXCLUDED.$column";
            }
        }

        if (empty($updateParts)) {
            // If all columns are conflict columns, use DO NOTHING
            return "INSERT INTO $table ($columnList) VALUES ($placeholders) ON CONFLICT ($conflictList) DO NOTHING";
        }

        $updateClause = implode(', ', $updateParts);
        return "INSERT INTO $table ($columnList) VALUES ($placeholders) ON CONFLICT ($conflictList) DO UPDATE SET $updateClause";
    }

    private function buildSqliteUpsert(string $table, array $columns, string $placeholders, array $conflictColumns): string
    {
        // SQLite 3.24.0+ uses same syntax as PostgreSQL
        return $this->buildPostgresUpsert($table, $columns, $placeholders, $conflictColumns);
    }

    private function buildSqlServerUpsert(string $table, array $columns, array $values, array $conflictColumns): string
    {
        // SQL Server uses MERGE statement - more complex
        // Build quoted values for SQL
        $quotedValues = array_map(fn($v) => $this->quote($v), $values);

        $columnList = implode(', ', $columns);
        $valuesList = implode(', ', $quotedValues);

        // Build source values
        $sourceValues = [];
        foreach ($columns as $i => $column) {
            $sourceValues[] = "{$quotedValues[$i]} AS $column";
        }
        $sourceClause = implode(', ', $sourceValues);

        // Build match condition
        $matchConditions = [];
        foreach ($conflictColumns as $column) {
            $matchConditions[] = "target.$column = source.$column";
        }
        $matchClause = implode(' AND ', $matchConditions);

        // Build update SET clause
        $updateParts = [];
        foreach ($columns as $column) {
            if (!in_array($column, $conflictColumns)) {
                $updateParts[] = "$column = source.$column";
            }
        }
        $updateClause = empty($updateParts) ? '' : 'UPDATE SET ' . implode(', ', $updateParts);

        $insertClause = "INSERT ($columnList) VALUES ($valuesList)";

        return "MERGE INTO $table AS target USING (SELECT $sourceClause) AS source ON $matchClause " .
               "WHEN MATCHED THEN $updateClause " .
               "WHEN NOT MATCHED THEN $insertClause;";
    }

    private function buildOracleUpsert(string $table, array $columns, array $values, array $conflictColumns): string
    {
        // Oracle uses same MERGE syntax as SQL Server
        return $this->buildSqlServerUpsert($table, $columns, $values, $conflictColumns);
    }

    /**
     * Build a temporary table containing schema metadata
     */
    public function buildSchemaTable(string $tableName): void
    {
        $dialect = $this->getDialect();

        // Create or clear the temp table
        $this->exec("DROP TABLE IF EXISTS {$tableName}");
        $this->exec("CREATE TEMP TABLE {$tableName} (
            table_name TEXT,
            name TEXT,
            type TEXT,
            data_type TEXT,
            is_nullable INTEGER,
            default_value TEXT,
            ordinal INTEGER,
            extra TEXT
        )");

        // Populate based on dialect
        match ($dialect) {
            SqlDialect::Sqlite => $this->populateSchemaSqlite($tableName),
            SqlDialect::MySQL => $this->populateSchemaMySQL($tableName),
            SqlDialect::Postgres => $this->populateSchemaPostgres($tableName),
            default => $this->populateSchemaGeneric($tableName),
        };
    }

    private function populateSchemaSqlite(string $schemaTable): void
    {
        // Get all table names
        $tables = $this->queryColumn(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
        );

        foreach ($tables as $table) {
            // Skip the schema table itself
            if ($table === $schemaTable) {
                continue;
            }

            // Get column info via PRAGMA
            $columns = $this->query("PRAGMA table_info({$table})")->toArray();
            $pkColumns = [];

            foreach ($columns as $col) {
                $this->exec(
                    "INSERT INTO {$schemaTable} (table_name, name, type, data_type, is_nullable, default_value, ordinal, extra)
                     VALUES (?, ?, 'column', ?, ?, ?, ?, NULL)",
                    [
                        $table,
                        $col->name,
                        $col->type,
                        $col->notnull ? 0 : 1,
                        $col->dflt_value,
                        $col->cid + 1
                    ]
                );

                if ($col->pk) {
                    $pkColumns[$col->pk] = $col->name; // pk is 1-based position in PK
                }
            }

            // Add primary key as an index entry
            if (!empty($pkColumns)) {
                ksort($pkColumns);
                $this->exec(
                    "INSERT INTO {$schemaTable} (table_name, name, type, data_type, is_nullable, default_value, ordinal, extra)
                     VALUES (?, ?, 'primary', NULL, NULL, NULL, NULL, ?)",
                    [$table, 'PRIMARY', implode(', ', $pkColumns)]
                );
            }

            // Get indexes via PRAGMA
            $indexes = $this->query("PRAGMA index_list({$table})")->toArray();

            foreach ($indexes as $idx) {
                // Skip auto-generated indexes for PRIMARY KEY and UNIQUE constraints
                // (they start with "sqlite_autoindex_")
                if (str_starts_with($idx->name, 'sqlite_autoindex_')) {
                    continue;
                }

                // Get columns in this index
                $indexCols = $this->query("PRAGMA index_info({$idx->name})")->toArray();
                $colNames = array_map(fn($c) => $c->name, $indexCols);

                $type = $idx->unique ? 'unique' : 'index';

                $this->exec(
                    "INSERT INTO {$schemaTable} (table_name, name, type, data_type, is_nullable, default_value, ordinal, extra)
                     VALUES (?, ?, ?, NULL, NULL, NULL, NULL, ?)",
                    [$table, $idx->name, $type, implode(', ', $colNames)]
                );
            }
        }
    }

    private function populateSchemaMySQL(string $schemaTable): void
    {
        // Insert columns
        $this->exec(
            "INSERT INTO {$schemaTable} (table_name, name, type, data_type, is_nullable, default_value, ordinal, extra)
             SELECT
                 TABLE_NAME,
                 COLUMN_NAME,
                 'column',
                 COLUMN_TYPE,
                 CASE WHEN IS_NULLABLE = 'YES' THEN 1 ELSE 0 END,
                 COLUMN_DEFAULT,
                 ORDINAL_POSITION,
                 NULL
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
             ORDER BY TABLE_NAME, ORDINAL_POSITION"
        );

        // Insert indexes (grouped by index name with columns concatenated)
        $this->exec(
            "INSERT INTO {$schemaTable} (table_name, name, type, data_type, is_nullable, default_value, ordinal, extra)
             SELECT
                 TABLE_NAME,
                 INDEX_NAME,
                 CASE
                     WHEN INDEX_NAME = 'PRIMARY' THEN 'primary'
                     WHEN NON_UNIQUE = 0 THEN 'unique'
                     ELSE 'index'
                 END,
                 NULL,
                 NULL,
                 NULL,
                 NULL,
                 GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX)
             FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
             GROUP BY TABLE_NAME, INDEX_NAME, NON_UNIQUE
             ORDER BY TABLE_NAME, INDEX_NAME"
        );
    }

    private function populateSchemaPostgres(string $schemaTable): void
    {
        // Insert columns
        $this->exec(
            "INSERT INTO {$schemaTable} (table_name, name, type, data_type, is_nullable, default_value, ordinal, extra)
             SELECT
                 table_name,
                 column_name,
                 'column',
                 data_type,
                 CASE WHEN is_nullable = 'YES' THEN 1 ELSE 0 END,
                 column_default,
                 ordinal_position,
                 NULL
             FROM information_schema.columns
             WHERE table_schema = current_schema()
             ORDER BY table_name, ordinal_position"
        );

        // Insert indexes
        $this->exec(
            "INSERT INTO {$schemaTable} (table_name, name, type, data_type, is_nullable, default_value, ordinal, extra)
             SELECT
                 t.relname AS table_name,
                 i.relname AS index_name,
                 CASE
                     WHEN ix.indisprimary THEN 'primary'
                     WHEN ix.indisunique THEN 'unique'
                     ELSE 'index'
                 END,
                 NULL,
                 NULL,
                 NULL,
                 NULL,
                 string_agg(a.attname, ', ' ORDER BY array_position(ix.indkey, a.attnum))
             FROM pg_class t
             JOIN pg_index ix ON t.oid = ix.indrelid
             JOIN pg_class i ON i.oid = ix.indexrelid
             JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(ix.indkey)
             JOIN pg_namespace n ON n.oid = t.relnamespace
             WHERE n.nspname = current_schema()
               AND t.relkind = 'r'
             GROUP BY t.relname, i.relname, ix.indisprimary, ix.indisunique
             ORDER BY t.relname, i.relname"
        );
    }

    private function populateSchemaGeneric(string $schemaTable): void
    {
        // Try INFORMATION_SCHEMA for columns (works for many databases)
        try {
            $this->exec(
                "INSERT INTO {$schemaTable} (table_name, name, type, data_type, is_nullable, default_value, ordinal, extra)
                 SELECT
                     TABLE_NAME,
                     COLUMN_NAME,
                     'column',
                     DATA_TYPE,
                     CASE WHEN IS_NULLABLE = 'YES' THEN 1 ELSE 0 END,
                     COLUMN_DEFAULT,
                     ORDINAL_POSITION,
                     NULL
                 FROM INFORMATION_SCHEMA.COLUMNS
                 ORDER BY TABLE_NAME, ORDINAL_POSITION"
            );
        } catch (\Throwable) {
            // If INFORMATION_SCHEMA not available, leave table empty
        }
    }
}
