<?php
namespace mini\Table\Index;

use Traversable;

/**
 * Index interface with range query support
 *
 * Extends HashIndexInterface with O(log n) range queries.
 * Keys are binary strings that sort correctly via memcmp.
 */
interface IndexInterface extends HashIndexInterface
{
    /**
     * Range query over keys
     *
     * @param string|null $start Minimum key (inclusive), null for no lower bound
     * @param string|null $end Maximum key (inclusive), null for no upper bound
     * @param bool $reverse Iterate in descending order
     * @return Traversable<int> Yields rowIds in key order
     */
    public function range(?string $start = null, ?string $end = null, bool $reverse = false): Traversable;
}
