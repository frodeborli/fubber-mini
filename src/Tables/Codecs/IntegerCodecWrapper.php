<?php

namespace mini\Tables\Codecs;

use mini\Tables\Codecs\FieldCodecInterface;

/**
 * Wrapper to adapt IntegerCodecInterface to FieldCodecInterface
 *
 * Bridges the new codec system to the existing FieldCodecInterface.
 */
final class IntegerCodecWrapper implements FieldCodecInterface
{
    public function __construct(
        private IntegerCodecInterface $codec,
        private string $fieldName
    ) {}

    public function fromStorage(mixed $storageValue): mixed
    {
        if ($storageValue === null) {
            return null;
        }
        return $this->codec->fromBackendInteger((int) $storageValue);
    }

    public function toStorage(mixed $domainValue): mixed
    {
        if ($domainValue === null) {
            return null;
        }
        return $this->codec->toBackendInteger($domainValue);
    }

    public function normalizeDomain(mixed $domainValue): mixed
    {
        // Round-trip through integer to normalize
        if ($domainValue === null) {
            return null;
        }

        $intValue = $this->codec->toBackendInteger($domainValue);
        return $this->codec->fromBackendInteger($intValue);
    }

    public function getFieldName(): string
    {
        return $this->fieldName;
    }
}