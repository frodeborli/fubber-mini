<?php

namespace mini\Database\Virtual;

/**
 * Order metadata yielded by virtual tables
 *
 * Virtual tables can optionally yield this as the FIRST value from select()
 * to inform the VirtualDatabase engine about backend-applied ordering.
 *
 * If yielded, the engine can optimize query execution by:
 * - Streaming results when ORDER BY matches backend ordering
 * - Avoiding double-application of OFFSET
 * - Early-stopping when LIMIT is reached
 *
 * If not yielded, engine assumes unordered data with no backend offset.
 *
 * Collation identifiers:
 * - "BINARY" - Case-sensitive, byte-order comparison (default)
 * - "NOCASE" - Case-insensitive ASCII comparison
 * - Locale codes - e.g., "sv_SE", "de_DE", "en_US" for locale-specific sorting
 */
final class OrderInfo implements ResultInterface
{
    /**
     * @param string|null $column Primary sort column (e.g., "birthday" or "users.birthday")
     * @param bool $desc True for DESC, false for ASC
     * @param int $skipped Number of rows already skipped by backend (backend-applied offset)
     * @param string $collation Collation identifier used by backend (BINARY, NOCASE, or locale like sv_SE)
     */
    public function __construct(
        public ?string $column = null,
        public bool $desc = false,
        public int $skipped = 0,
        public string $collation = 'BINARY'
    ) {
    }
}
