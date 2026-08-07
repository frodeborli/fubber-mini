<?php

namespace mini\Database;

use mini\Table\Contracts\MutableTableInterface;

/**
 * Buffers row mutations so that no write is applied while a query is reading
 *
 * A statement whose source reads the table it writes must not apply changes
 * during the scan — the written rows feed back into the read. This is the
 * Halloween problem, and its clearest symptom is
 * `INSERT INTO t SELECT ... FROM t` never terminating: every inserted row is
 * re-read and inserted again until memory runs out.
 *
 * The rule in Mini is therefore absolute: **mutations are logged during the
 * read and applied after it completes.** Every mutation path in
 * `VirtualDatabase` funnels through this class, so the invariant holds in one
 * place instead of being re-implemented (and forgotten) per statement kind.
 *
 * Deferring the writes is preferred over materialising the source: the source
 * stays streaming — it may be a remote `TableInterface`, since this is a
 * federated engine — and only the payloads that must be written are held.
 *
 * Buffering is bounded. A runaway source fails with an actionable error rather
 * than exhausting memory; the cap is `Limits::$maxBufferedWrites`, also
 * reachable as `VirtualDatabase::setMaxMaterializedRows()`.
 *
 * Operations are applied in the order they were logged, which is what makes
 * REPLACE (delete-then-insert of the same key) behave correctly.
 *
 * ```php
 * $writes = new PendingWrites('INSERT ... SELECT', $max);
 * foreach ($this->executeSelect($ast->select) as $row) {
 *     $writes->insert($this->toRowArray($row));   // logged, not applied
 * }
 * $count = $writes->apply($table);                 // read is over: write now
 * ```
 */
final class PendingWrites implements \Countable
{
    private const OP_INSERT = 0;
    private const OP_UPDATE = 1;
    private const OP_DELETE = 2;

    /** @var array<int, array> Logged operations, in application order */
    private array $ops = [];

    /** Last id produced by an applied insert */
    private int|string $lastInsertId = 0;

    /**
     * @param string $context Statement kind, used in the cap's error message
     * @param int|null $maxRows Maximum buffered writes, null to disable the cap
     */
    public function __construct(
        private readonly string $context,
        private readonly ?int $maxRows = 1_000_000,
    ) {}

    /**
     * Log a row to insert
     *
     * @param array<string, mixed> $row Column => value
     */
    public function insert(array $row): void
    {
        $this->log([self::OP_INSERT, $row]);
    }

    /**
     * Log an update to the row identified by $column = $value
     *
     * @param array<string, mixed> $changes Column => new value
     */
    public function update(string $column, mixed $value, array $changes): void
    {
        $this->log([self::OP_UPDATE, $column, $value, $changes]);
    }

    /**
     * Log a delete of the row identified by $column = $value
     */
    public function delete(string $column, mixed $value): void
    {
        $this->log([self::OP_DELETE, $column, $value]);
    }

    private function log(array $op): void
    {
        $this->ops[] = $op;

        if ($this->maxRows !== null && count($this->ops) > $this->maxRows) {
            throw new \RuntimeException(
                "{$this->context} exceeded the maxBufferedWrites limit of {$this->maxRows} buffered " .
                'rows. Writes are buffered because no row may be written while the statement is ' .
                'still reading. Narrow the source with a WHERE or LIMIT, or raise the cap with ' .
                'VirtualDatabase::setMaxMaterializedRows() (equivalently, ' .
                'setLimits(new Limits(maxBufferedWrites: ...))).'
            );
        }
    }

    /**
     * Number of logged operations not yet applied
     */
    public function count(): int
    {
        return count($this->ops);
    }

    /**
     * Apply every logged operation, in order, and clear the log
     *
     * Call only once the statement has finished reading.
     *
     * @return int Number of operations applied
     */
    public function apply(MutableTableInterface $table): int
    {
        $applied = 0;

        foreach ($this->ops as $op) {
            switch ($op[0]) {
                case self::OP_INSERT:
                    $this->lastInsertId = $table->insert($op[1]);
                    break;
                case self::OP_UPDATE:
                    $table->update($table->eq($op[1], $op[2]), $op[3]);
                    break;
                case self::OP_DELETE:
                    $table->delete($table->eq($op[1], $op[2]));
                    break;
            }
            $applied++;
        }

        $this->ops = [];

        return $applied;
    }

    /**
     * Id produced by the last applied insert
     */
    public function lastInsertId(): int|string
    {
        return $this->lastInsertId;
    }
}
