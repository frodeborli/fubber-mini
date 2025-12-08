<?php

namespace mini\Database\Virtual;

/**
 * Order metadata yielded by virtual tables
 *
 * Virtual tables can optionally yield this as the FIRST value from select()
 * to inform the VirtualDatabase engine about backend-applied ordering and filtering.
 *
 * If yielded, the engine can optimize query execution by:
 * - Streaming results when ORDER BY matches backend ordering
 * - Avoiding double-application of OFFSET
 * - Early-stopping when LIMIT is reached
 *
 * If not yielded, engine assumes unordered data with no backend filtering/offset.
 *
 * The `skipped` parameter controls both OFFSET and WHERE handling:
 * - null: VirtualDatabase applies WHERE and handles all OFFSET
 * - int: Backend handled WHERE (possibly with custom collation) and skipped N rows
 *
 * This allows table implementations to use custom collation for comparisons.
 */
final class OrderInfo implements ResultInterface
{
    /**
     * @param string|null $column Primary sort column (e.g., "birthday" or "users.birthday")
     * @param bool $desc True for DESC, false for ASC
     * @param int|null $skipped Controls WHERE and OFFSET handling:
     *                          - null: VirtualDatabase applies WHERE clause and handles OFFSET
     *                          - 0+: Backend already evaluated WHERE (with its own comparison rules)
     *                            and skipped this many matching rows. VirtualDatabase trusts the
     *                            filtering and only applies remaining OFFSET if needed.
     */
    public function __construct(
        public ?string $column = null,
        public bool $desc = false,
        public ?int $skipped = null,
    ) {
    }
}
