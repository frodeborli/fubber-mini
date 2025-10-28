<?php

namespace mini\Tables\Codecs;

/**
 * Enhanced field codec interface for bidirectional field transformations
 *
 * Provides three distinct operations:
 * 1. fromStorage() - Convert database values to PHP values (hydration)
 * 2. toStorage() - Convert PHP values to database values (dehydration & query conditions)
 * 3. normalizeDomain() - Normalize PHP values for consistent equality checks (dirty detection)
 */
interface FieldCodecInterface
{
    /**
     * Convert database value to PHP domain value
     *
     * Used during hydration when loading models from storage.
     *
     * @param mixed $storageValue Value from database/storage
     * @return mixed PHP domain value
     */
    public function fromStorage(mixed $storageValue): mixed;

    /**
     * Convert PHP domain value to database storage value
     *
     * Used during dehydration when saving models and for query condition values.
     *
     * @param mixed $domainValue PHP domain value
     * @return mixed Database storage value
     */
    public function toStorage(mixed $domainValue): mixed;

    /**
     * Normalize PHP domain value for consistent equality comparison
     *
     * Used for dirty detection in model_dirty() to ensure consistent comparison.
     * Should produce stable, serializable output for the given domain value.
     *
     * @param mixed $domainValue PHP domain value
     * @return mixed Normalized value for equality comparison
     */
    public function normalizeDomain(mixed $domainValue): mixed;

    /**
     * Get the field name this codec handles
     *
     * @return string Field name
     */
    public function getFieldName(): string;
}