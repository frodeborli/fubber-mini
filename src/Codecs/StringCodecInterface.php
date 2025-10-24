<?php

namespace mini\Codecs;

/**
 * Interface for codecs that can convert to/from string backend representations
 */
interface StringCodecInterface extends CodecInterface
{
    /**
     * Convert from backend string to PHP type
     */
    public function fromBackendString(string $value): mixed;

    /**
     * Convert from PHP type to backend string
     */
    public function toBackendString(mixed $frontendValue): string;
}