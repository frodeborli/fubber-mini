<?php

namespace mini\Database;

use mini\Contracts\DatabaseInterface;
use mini\Mini;
use PDO;
use PDOException;
use Exception;

/**
 * PDO-based database implementation
 *
 * Wraps any PDO instance with a clean, ergonomic API that makes database
 * operations pleasant while supporting any PDO-compatible database.
 * Fetches PDO from container lazily to ensure proper scoping.
 */
class PdoDatabase implements DatabaseInterface
{
    private ?PDO $pdo = null;
    private int $transactionDepth = 0;

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
     * Execute query and return all results as associative arrays
     */
    public function query(string $sql, array $params = []): array
    {
        try {
            $stmt = $this->lazyPdo()->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            throw new Exception("Query failed: " . $e->getMessage());
        }
    }

    /**
     * Execute query and return first row only as associative array
     */
    public function queryOne(string $sql, array $params = []): ?array
    {
        try {
            $stmt = $this->lazyPdo()->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetch();
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
            $stmt->execute($params);
            $result = $stmt->fetch();
            return $result ? array_values($result)[0] : null;
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
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
        } catch (PDOException $e) {
            throw new Exception("Query column failed: " . $e->getMessage());
        }
    }

    /**
     * Execute a statement (INSERT, UPDATE, DELETE)
     */
    public function exec(string $sql, array $params = []): bool
    {
        try {
            $stmt = $this->lazyPdo()->prepare($sql);
            return $stmt->execute($params);
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
     * Handles nested transactions by maintaining a transaction depth counter.
     * Only the outermost transaction actually commits/rollbacks with the database.
     * Inner transactions are "fake" - they just track depth.
     */
    public function transaction(\Closure $task): mixed
    {
        $this->transactionDepth++;

        // Only start a real transaction on the outermost level
        if ($this->transactionDepth === 1) {
            try {
                $this->lazyPdo()->beginTransaction();
            } catch (PDOException $e) {
                $this->transactionDepth--;
                throw new Exception("Failed to start transaction: " . $e->getMessage());
            }
        }

        try {
            // Execute the task
            $result = $task();

            // Only commit on the outermost transaction
            if ($this->transactionDepth === 1) {
                $this->lazyPdo()->commit();
            }

            $this->transactionDepth--;
            return $result;

        } catch (\Throwable $e) {
            // Rollback on any exception (but only if we're at the outermost level)
            if ($this->transactionDepth === 1) {
                try {
                    $this->lazyPdo()->rollBack();
                } catch (PDOException $rollbackException) {
                    // Log rollback failure but throw original exception
                    error_log("Transaction rollback failed: " . $rollbackException->getMessage());
                }
            }

            $this->transactionDepth = max(0, $this->transactionDepth - 1);
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

}