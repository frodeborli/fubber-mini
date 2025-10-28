<?php

namespace mini\Tables;

use mini\Table;
use mini\Attributes\Entity;
use mini\Attributes\Key;
use mini\Attributes\Navigation;
use mini\Attributes\Column;
use mini\Tables\CodecStrategies\FieldCodecInterface;
use mini\Tables\CodecStrategies\ScalarCodecStrategy;

/**
 * Abstract repository for scalar data sources
 *
 * Provides complete ORM functionality for backends that can provide raw scalar data.
 * Implementations only need to define field types and provide raw row iteration.
 *
 * @template T of object
 */
abstract class ScalarRepository implements ReadonlyRepositoryInterface
{
    protected string $modelClass;
    protected ?string $actualTableName = null;
    protected ?string $primaryKeyColumn = null;
    protected array $fieldMappings = [];
    protected array $fieldTypes = [];
    protected array $fieldCodecs = [];
    protected ScalarCodecStrategy $codecStrategy;
    private bool $attributesAnalyzed = false;

    /**
     * @param class-string<T> $modelClass
     */
    public function __construct(string $modelClass)
    {
        $this->modelClass = $modelClass;
        $this->codecStrategy = new ScalarCodecStrategy();
    }

    /**
     * Define the scalar field types for this data source
     *
     * @return array<string, string> ['backend_field_name' => 'int|float|string|bool|?int|?float|?string|?bool', ...]
     */
    abstract protected function getFields(): array;

    /**
     * Provide raw row data with optional backend optimizations
     *
     * @param array $where Backend may ignore - ScalarRepository will re-filter
     * @param int $startOffset Logical offset in FILTERED result set
     * @param int $limit Maximum rows to yield
     * @param string|null $orderBy Backend may ignore - ScalarRepository will re-sort
     * @param bool $orderDescending Backend may ignore
     * @return \Generator<int, array> Yields logical_record_number => row_data
     */
    abstract protected function getRows(
        array $where = [],
        int $startOffset = 0,
        int $limit = 1000,
        ?string $orderBy = null,
        bool $orderDescending = false
    ): \Generator;

    // ReadonlyRepositoryInterface implementation

    public function name(): string
    {
        $this->analyzeModelAttributes();
        return $this->actualTableName ?? strtolower(basename(str_replace('\\', '/', $this->modelClass)));
    }

    public function pk(): string
    {
        $this->analyzeModelAttributes();
        return $this->primaryKeyColumn ?? 'id';
    }

    public function getModelClass(): string
    {
        return $this->modelClass;
    }

    public function all(): Table
    {
        return new Table($this);
    }

    public function create(): object
    {
        return new ($this->modelClass)();
    }

    public function load(mixed $id): ?object
    {
        $row = $this->fetchOne([$this->pk() => $id]);
        return $row ? $this->hydrate($row) : null;
    }

    public function loadMany(string|int ...$ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $models = [];
        foreach ($this->fetchMany([$this->pk() . ':in' => array_values($ids)]) as $row) {
            $models[] = $this->hydrate($row);
        }

        return $models;
    }

    public function fetchOne(array $where): ?array
    {
        foreach ($this->fetchMany($where, [], 1) as $row) {
            return $row;
        }
        return null;
    }

    public function fetchMany(array $where, array $order = [], ?int $limit = null, int $offset = 0): iterable
    {
        // Extract order field and direction from order array
        $orderBy = null;
        $orderDescending = false;
        if (!empty($order)) {
            $orderBy = array_key_first($order);
            $orderDescending = strtolower($order[$orderBy] ?? 'asc') === 'desc';
        }

        $rowsProcessed = 0;
        $rowsYielded = 0;
        $maxRows = $limit ?? PHP_INT_MAX;

        foreach ($this->getRows($where, $offset, $maxRows, $orderBy, $orderDescending) as $recordNumber => $row) {
            // Apply additional filtering if backend couldn't handle it
            if (!$this->matchesWhere($row, $where)) {
                continue;
            }

            // Skip rows if backend couldn't handle offset
            if ($recordNumber < $offset) {
                continue;
            }

            yield $row;
            $rowsYielded++;

            if ($rowsYielded >= $maxRows) {
                break;
            }
        }
    }

    public function count(array $where = []): int
    {
        $count = 0;
        foreach ($this->fetchMany($where) as $row) {
            $count++;
        }
        return $count;
    }

    public function getFieldNames(): array
    {
        $this->analyzeModelAttributes();
        return array_keys($this->fieldMappings);
    }

    public function transformFromStorage(string $field, mixed $value): mixed
    {
        $this->analyzeModelAttributes();

        // Use FieldCodec if available (attribute-driven)
        if (isset($this->fieldCodecs[$field])) {
            return $this->fieldCodecs[$field]->fromStorage($value);
        }

        // Fallback to simple type conversion from getFields()
        if ($value === null || $value === '') {
            return null;
        }

        $type = $this->fieldTypes[$field] ?? 'string';

        // Handle nullable types
        if (str_starts_with($type, '?')) {
            $type = substr($type, 1);
            if ($value === null || $value === '') {
                return null;
            }
        }

        return match ($type) {
            'bool', 'boolean' => in_array(strtolower($value), ['1', 'true', 'yes', 'on']),
            'int', 'integer' => (int) $value,
            'float' => (float) $value,
            'string' => (string) $value,
            default => $value
        };
    }

    public function transformToStorage(string $field, mixed $value): mixed
    {
        $this->analyzeModelAttributes();

        // Use FieldCodec if available (attribute-driven)
        if (isset($this->fieldCodecs[$field])) {
            return $this->fieldCodecs[$field]->toStorage($value);
        }

        // Fallback to simple type conversion
        if ($value === null) {
            return null;
        }

        $type = $this->fieldTypes[$field] ?? 'string';

        // Handle nullable types
        if (str_starts_with($type, '?')) {
            if ($value === null) {
                return null;
            }
            $type = substr($type, 1);
        }

        return match ($type) {
            'bool', 'boolean' => $value ? '1' : '0',
            'int', 'integer' => (string) (int) $value,
            'float' => (string) (float) $value,
            'string' => (string) $value,
            default => (string) $value
        };
    }

    public function hydrate(array $row): object
    {
        $this->analyzeModelAttributes();

        $model = new ($this->modelClass)();

        foreach ($this->fieldMappings as $propertyName => $columnName) {
            if (!array_key_exists($columnName, $row)) {
                continue;
            }

            $value = $this->transformFromStorage($propertyName, $row[$columnName]);
            $model->$propertyName = $value;
        }

        return $model;
    }

    public function getFieldCodec(string $field): ?object
    {
        $this->analyzeModelAttributes();
        return $this->fieldCodecs[$field] ?? null;
    }

    public function canList(): bool
    {
        return true; // Read-only repositories allow listing by default
    }

    public function canRead(object $model): bool
    {
        return true; // Read-only repositories allow reading by default
    }

    // Private helpers

    private function analyzeModelAttributes(): void
    {
        if ($this->attributesAnalyzed) {
            return;
        }

        $this->attributesAnalyzed = true;
        $reflection = new \ReflectionClass($this->modelClass);

        // Analyze class-level Entity attribute
        $entityAttributes = $reflection->getAttributes(Entity::class);
        if (!empty($entityAttributes)) {
            $entity = $entityAttributes[0]->newInstance();
            $this->actualTableName = $entity->table;
        }

        // Analyze properties for Column and Key attributes
        foreach ($reflection->getProperties() as $property) {
            $propertyName = $property->getName();

            // Skip navigation properties
            if (!empty($property->getAttributes(Navigation::class))) {
                continue;
            }

            // Check for Key attribute
            $keyAttributes = $property->getAttributes(Key::class);
            if (!empty($keyAttributes)) {
                $this->primaryKeyColumn = $this->getColumnNameForProperty($property);
            }

            // Map property to column and create codec if needed
            $columnName = $this->getColumnNameForProperty($property);
            if ($columnName) {
                $this->fieldMappings[$propertyName] = $columnName;

                // Create FieldCodec from column attribute if present
                $codec = $this->createFieldCodecForProperty($property);
                if ($codec) {
                    $this->fieldCodecs[$propertyName] = $codec;
                } else {
                    // Fallback to getFields() type mapping
                    $fields = $this->getFields();
                    $this->fieldTypes[$propertyName] = $fields[$columnName] ?? 'string';
                }
            }
        }
    }

    private function getColumnNameForProperty(\ReflectionProperty $property): ?string
    {
        // Check if property has any column attribute
        $columnAttributes = array_merge(
            $property->getAttributes(\mini\Attributes\IntegerColumn::class),
            $property->getAttributes(\mini\Attributes\VarcharColumn::class),
            $property->getAttributes(\mini\Attributes\DateTimeImmutableColumn::class)
            // Add other column types as needed
        );

        if (!empty($columnAttributes)) {
            $column = $columnAttributes[0]->newInstance();
            return $column->name;
        }

        // Fallback to property name if it exists in getFields()
        $fields = $this->getFields();
        return array_key_exists($property->getName(), $fields) ? $property->getName() : null;
    }

    private function matchesWhere(array $row, array $where): bool
    {
        foreach ($where as $key => $value) {
            if (str_contains($key, ':')) {
                [$field, $operator] = explode(':', $key, 2);
            } else {
                $field = $key;
                $operator = '=';
            }

            $columnName = $this->fieldMappings[$field] ?? $field;
            if (!array_key_exists($columnName, $row)) {
                return false;
            }

            $rowValue = $this->transformFromStorage($field, $row[$columnName]);

            if (!$this->compareValues($rowValue, $value, $operator)) {
                return false;
            }
        }

        return true;
    }

    private function compareValues(mixed $a, mixed $b, string $operator): bool
    {
        return match ($operator) {
            '=', '==' => $a == $b,
            'gt', '>' => $a > $b,
            'gte', '>=' => $a >= $b,
            'lt', '<' => $a < $b,
            'lte', '<=' => $a <= $b,
            'in' => is_array($b) && in_array($a, $b),
            default => false
        };
    }

    private function createFieldCodecForProperty(\ReflectionProperty $property): ?FieldCodecInterface
    {
        // Get column attributes (any type that extends Column)
        $columnAttributes = $property->getAttributes(Column::class, \ReflectionAttribute::IS_INSTANCEOF);
        if (empty($columnAttributes)) {
            return null;
        }

        $columnAttr = $columnAttributes[0]->newInstance();
        return $columnAttr->createCodec($this->codecStrategy, $property);
    }
}