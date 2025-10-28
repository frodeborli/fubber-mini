<?php

namespace mini\Tables;

/**
 * CSV file repository implementation
 *
 * Provides read-only access to CSV files with full ORM integration through ScalarRepository.
 * Supports all fgetcsv() options and automatic field type mapping.
 *
 * @template T of object
 */
class CsvRepository extends ScalarRepository
{
    private array $fieldNames;
    private bool $firstRowIsFieldNames;
    private ?int $length;
    private string $separator;
    private string $enclosure;
    private string $escape;
    private array $fieldTypesMap;

    /**
     * @param class-string<T> $modelClass
     * @param string $csvPath Path to CSV file
     * @param array $fieldNames Field name to type mapping ['field_name' => 'int|float|string|bool|?int|?float|?string|?bool']
     * @param bool $firstRowIsFieldNames Whether first row contains field names
     * @param int|null $length Maximum line length (see fgetcsv)
     * @param string $separator Field separator character
     * @param string $enclosure Field enclosure character
     * @param string $escape Escape character
     */
    public function __construct(
        string $modelClass,
        private string $csvPath,
        array $fieldNames,
        bool $firstRowIsFieldNames = true,
        ?int $length = null,
        string $separator = ",",
        string $enclosure = "\"",
        string $escape = "\\"
    ) {
        parent::__construct($modelClass);

        $this->fieldNames = $fieldNames;
        $this->firstRowIsFieldNames = $firstRowIsFieldNames;
        $this->length = $length;
        $this->separator = $separator;
        $this->enclosure = $enclosure;
        $this->escape = $escape;
        $this->fieldTypesMap = $fieldNames;
    }

    protected function getFields(): array
    {
        return $this->fieldTypesMap;
    }

    protected function getRows(
        array $where = [],
        int $startOffset = 0,
        int $limit = 1000,
        ?string $orderBy = null,
        bool $orderDescending = false
    ): \Generator {
        $handle = fopen($this->csvPath, 'r');

        if ($handle === false) {
            throw new RepositoryException("Unable to read CSV file: {$this->csvPath}");
        }

        try {
            // Skip header row if present
            if ($this->firstRowIsFieldNames) {
                fgetcsv($handle, $this->length, $this->separator, $this->enclosure, $this->escape);
            }

            $recordNumber = 0;
            $headers = array_keys($this->fieldTypesMap);

            // Simple implementation - just yield all rows
            // Backend optimizations for filtering/sorting could be added here
            while (($row = fgetcsv($handle, $this->length, $this->separator, $this->enclosure, $this->escape)) !== false) {
                // Convert indexed array to associative array
                $assocRow = [];
                foreach ($headers as $index => $fieldName) {
                    $assocRow[$fieldName] = $row[$index] ?? null;
                }

                yield $recordNumber++ => $assocRow;
            }
        } finally {
            fclose($handle);
        }
    }
}