<?php

namespace mini\Codecs;

/**
 * Interface for codecs that can convert to/from boolean backend representations
 */
interface BooleanCodecInterface extends CodecInterface
{
    /**
     * Convert from backend boolean to PHP type
     */
    public function fromBackendBoolean(bool $value): mixed;

    /**
     * Convert from PHP type to backend boolean
     */
    public function toBackendBoolean(mixed $frontendValue): bool;
}