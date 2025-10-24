<?php

namespace mini\Contracts;

use mini\Attributes\Column;

/**
 * Strategy interface for creating database-specific field codecs
 *
 * Different database backends can implement this interface to provide
 * appropriate codecs for their storage capabilities and type systems.
 */
interface CodecStrategyInterface
{
    /**
     * Create appropriate codec for a column attribute and property
     *
     * The strategy examines the column's type, storage hints, and the PHP property type
     * to determine the most appropriate codec for the target database backend.
     *
     * @param Column $column Column attribute with type and storage information
     * @param \ReflectionProperty $property Property for PHP type detection
     * @return FieldCodecInterface|null Codec for the column, or null for no conversion
     */
    public function getCodecFor(Column $column, \ReflectionProperty $property): ?FieldCodecInterface;

    /**
     * Get the backend name this strategy targets
     *
     * @return string Backend identifier (e.g., 'postgresql', 'sqlite', 'mysql')
     */
    public function getBackendName(): string;
}