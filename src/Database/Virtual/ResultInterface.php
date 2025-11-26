<?php

namespace mini\Database\Virtual;

/**
 * Marker interface for virtual table SELECT results
 *
 * Virtual tables yield instances of this interface:
 * - OrderInfo: Optional metadata about backend ordering (first yield only)
 * - Row: Data rows with unique IDs (required)
 *
 * This provides type safety while allowing the generator to yield
 * either OrderInfo or Row instances.
 *
 * Example:
 * ```php
 * function selectFn(SelectStatement $ast, CollatorInterface $collator): iterable {
 *     // Optional: yield OrderInfo first
 *     yield new OrderInfo(column: 'id', desc: false, collator: $collator);
 *
 *     // Then yield Row instances
 *     foreach ($data as $id => $columns) {
 *         yield new Row($id, $columns);
 *     }
 * }
 * ```
 */
interface ResultInterface
{
    // Marker interface - no methods required
}
