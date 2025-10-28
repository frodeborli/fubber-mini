<?php

namespace mini\Tables\Codecs;

/**
 * Interface for codecs that can convert to/from integer backend representations
 */
interface IntegerCodecInterface extends CodecInterface
{
    /**
     * Convert from backend integer to PHP type
     */
    public function fromBackendInteger(int $value): mixed;

    /**
     * Convert from PHP type to backend integer
     */
    public function toBackendInteger(mixed $frontendValue): int;
}