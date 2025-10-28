<?php

namespace mini\Tables\Codecs;

/**
 * Interface for codecs that can convert to/from float backend representations
 */
interface FloatCodecInterface extends CodecInterface
{
    /**
     * Convert from backend float to PHP type
     */
    public function fromBackendFloat(float $value): mixed;

    /**
     * Convert from PHP type to backend float
     */
    public function toBackendFloat(mixed $frontendValue): float;
}