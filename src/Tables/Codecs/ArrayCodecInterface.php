<?php

namespace mini\Tables\Codecs;

/**
 * Interface for codecs that can convert to/from array backend representations
 */
interface ArrayCodecInterface extends CodecInterface
{
    /**
     * Convert from backend array to PHP type
     */
    public function fromBackendArray(array $value): mixed;

    /**
     * Convert from PHP type to backend array
     */
    public function toBackendArray(mixed $frontendValue): array;
}