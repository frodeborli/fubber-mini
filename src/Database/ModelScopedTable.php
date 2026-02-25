<?php

namespace mini\Database;

use mini\Authorizer\Ability;
use mini\Exceptions\AccessDeniedException;
use mini\Table\AbstractTable;
use mini\Table\Contracts\MutableTableInterface;
use mini\Table\Contracts\TableInterface;
use mini\Table\Utility\Set;
use mini\Table\Wrappers\AbstractTableWrapper;

use function mini\can;
use function mini\model;

/**
 * MutableTableInterface decorator that enforces Model authorization on writes
 *
 * Wraps the inner table registered in VirtualDatabase. By the time rows reach
 * this decorator, VDB has already applied the scope WHERE at the SQL level
 * (Layer 1). This decorator adds per-entity can() checks (Layer 2).
 *
 * Write operations use a snapshot-then-pin pattern (in-memory equivalent of
 * SELECT FOR UPDATE): rows are materialized once, authorization is checked on
 * the snapshot, and the actual operation is pinned to exactly those row IDs.
 * This eliminates the TOCTOU gap between checking and operating.
 *
 * - insert(): gates on can(Create, $modelClass)
 * - update(): snapshots rows, checks can(Update, $entity) per row, pins by PK
 * - delete(): snapshots rows, checks can(Delete, $entity) per row, pins by PK
 * - reads: pass through (VDB applied readable scope at SQL level)
 */
class ModelScopedTable extends AbstractTableWrapper implements MutableTableInterface
{
    private MutableTableInterface $mutableSource;
    private string $primaryKey;

    public function __construct(
        AbstractTable&MutableTableInterface $source,
        private ModelTableConfig $config,
    ) {
        parent::__construct($source);
        $this->mutableSource = $source;
        $this->primaryKey = model($config->modelClass)->primaryKey;
    }

    /**
     * Get the model configuration for this table
     */
    public function getModelConfig(): ModelTableConfig
    {
        return $this->config;
    }

    // =========================================================================
    // MutableTableInterface — intercepted for authorization
    // =========================================================================

    public function insert(array $row): int|string
    {
        $allow = $this->config->allowInsert ?? can(Ability::Create, $this->config->modelClass);
        if (!$allow) {
            throw new AccessDeniedException(
                "Not authorized to create " . $this->config->modelClass
            );
        }

        return $this->mutableSource->insert($row);
    }

    public function update(TableInterface $query, array $changes): int
    {
        $pk = $this->primaryKey;

        // Snapshot: materialize rows once and check authorization.
        // This is the in-memory equivalent of SELECT ... FOR UPDATE —
        // we pin the operation to exactly the rows we authorized.
        $authorizedIds = [];
        foreach ($query as $row) {
            $entity = Dehydrator::hydrate($row, $this->config->modelClass);
            if (!can(Ability::Update, $entity)) {
                throw new AccessDeniedException(
                    "Not authorized to update " . $this->config->modelClass
                );
            }
            $authorizedIds[] = $row->$pk;
        }

        if (empty($authorizedIds)) {
            return 0;
        }

        // Pin to exactly the authorized rows by primary key
        $pinned = $this->mutableSource->in($pk, new Set($pk, $authorizedIds));
        return $this->mutableSource->update($pinned, $changes);
    }

    public function delete(TableInterface $query): int
    {
        $pk = $this->primaryKey;

        // Snapshot and authorize (same pattern as update)
        $authorizedIds = [];
        foreach ($query as $row) {
            $entity = Dehydrator::hydrate($row, $this->config->modelClass);
            if (!can(Ability::Delete, $entity)) {
                throw new AccessDeniedException(
                    "Not authorized to delete " . $this->config->modelClass
                );
            }
            $authorizedIds[] = $row->$pk;
        }

        if (empty($authorizedIds)) {
            return 0;
        }

        // Pin to exactly the authorized rows by primary key
        $pinned = $this->mutableSource->in($pk, new Set($pk, $authorizedIds));
        return $this->mutableSource->delete($pinned);
    }
}
