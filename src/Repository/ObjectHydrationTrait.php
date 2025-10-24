<?php

namespace mini\Repository;

/**
 * Provides default hydrate/dehydrate implementations using field-level transformations
 *
 * Repositories can use this trait to get working hydrate/dehydrate methods without
 * having to implement them manually. The trait relies on:
 * - transformFromStorage(string $field, mixed $value): mixed
 * - transformToStorage(string $field, mixed $value): mixed
 * - getFieldNames(): array
 * - getModelClass(): string
 */
trait ObjectHydrationTrait
{
    public function hydrate(array $row): object
    {
        $model = new ($this->getModelClass())();

        foreach ($this->getFieldNames() as $field) {
            // Map field name to column name if needed
            $columnName = $this->getColumnName($field);

            if (!array_key_exists($columnName, $row)) {
                continue;
            }

            $value = $this->transformFromStorage($field, $row[$columnName]);
            $model->$field = $value;
        }

        return $model;
    }

    public function dehydrate(object $model): array
    {
        $data = [];

        foreach ($this->getFieldNames() as $field) {
            $value = $model->$field ?? null;

            // Map field name to column name if needed
            $columnName = $this->getColumnName($field);
            $data[$columnName] = $this->transformToStorage($field, $value);
        }

        return $data;
    }

    /**
     * Override this method if your repository has field-to-column mapping
     * Default implementation assumes field name = column name
     */
    protected function getColumnName(string $field): string
    {
        return $field;
    }
}