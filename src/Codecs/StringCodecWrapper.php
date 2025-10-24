<?php

namespace mini\Codecs;

use mini\Contracts\FieldCodecInterface;

/**
 * Wrapper to adapt StringCodecInterface to FieldCodecInterface
 *
 * Bridges the new codec system to the existing FieldCodecInterface.
 */
final class StringCodecWrapper implements FieldCodecInterface
{
    public function __construct(
        private StringCodecInterface $codec,
        private string $fieldName
    ) {}

    public function fromStorage(mixed $storageValue): mixed
    {
        if ($storageValue === null) {
            return null;
        }
        return $this->codec->fromBackendString((string) $storageValue);
    }

    public function toStorage(mixed $domainValue): mixed
    {
        if ($domainValue === null) {
            return null;
        }
        return $this->codec->toBackendString($domainValue);
    }

    public function normalizeDomain(mixed $domainValue): mixed
    {
        // For string codecs, we round-trip through string to normalize
        if ($domainValue === null) {
            return null;
        }

        $stringValue = $this->codec->toBackendString($domainValue);
        return $this->codec->fromBackendString($stringValue);
    }

    public function getFieldName(): string
    {
        return $this->fieldName;
    }
}