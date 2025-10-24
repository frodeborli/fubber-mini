<?php

namespace mini\Codecs;

/**
 * Interface for codecs that can convert to/from DateTimeInterface backend representations
 */
interface DateTimeCodecInterface extends CodecInterface
{
    /**
     * Convert from backend DateTimeInterface to PHP type
     */
    public function fromBackendDateTime(\DateTimeInterface $value): mixed;

    /**
     * Convert from PHP type to backend DateTimeInterface
     */
    public function toBackendDateTime(mixed $frontendValue): \DateTimeInterface;
}