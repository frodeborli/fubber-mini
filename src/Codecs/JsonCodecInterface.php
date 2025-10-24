<?php

namespace mini\Codecs;

/**
 * Interface for codecs that can convert to/from JSON backend representations
 *
 * Used for backends that support native JSON (like MongoDB, PostgreSQL JSONB)
 * or when the PHP type should be serialized as JSON.
 */
interface JsonCodecInterface extends CodecInterface
{
    /**
     * Convert from backend JSON to PHP type
     *
     * @param mixed $jsonValue JSON value from backend (could be array, object, scalar)
     */
    public function fromBackendJson($jsonValue);

    /**
     * Convert from PHP type to backend JSON
     *
     * @param mixed $frontendValue PHP value to convert
     * @return mixed JSON-serializable value for backend
     */
    public function toBackendJson($frontendValue);
}