<?php

namespace mini\Repository;

use mini\Repository\RepositoryInterface;
use mini\Repository\RepositoryException;
use mini\Table;
use mini\Contracts\DatabaseInterface;
use mini\Contracts\CodecStrategyInterface;
use mini\Contracts\FieldCodecInterface;
use mini\Attributes\Entity;
use mini\Attributes\Column;
use mini\Attributes\Key;
use mini\Attributes\Generated;
use mini\Attributes\Ignore;
use mini\Attributes\Navigation;
use ReflectionClass;
use ReflectionProperty;

/**
 * Attribute-driven database repository with auto-implemented methods
 *
 * Uses reflection to analyze model attributes and auto-generate
 * hydrate(), dehydrate(), convertConditionValue(), and validate() methods.
 *
 * All models MUST have:
 * - #[Entity(table: 'table_name')] on the class
 * - Column attributes on all public properties (or #[Ignore])
 * - #[Key] attribute on the primary key property
 *
 * @template T of object
 * @implements RepositoryInterface<T>
 */
class DatabaseRepository implements RepositoryInterface
{
    /** @var array<string, string> Property name → database column mapping */
    private array $fieldMappings = [];

    /** @var array<string, FieldCodecInterface> Property name → codec mapping */
    private array $fieldCodecs = [];

    /** @var array<string, array> Property name → JSON Schema validation rules */
    private array $validationSchemas = [];

    /** @var string|null Primary key property name */
    private ?string $primaryKeyProperty = null;

    /** @var string|null Primary key database column name */
    private ?string $primaryKeyColumn = null;

    /** @var string Actual table name (from Entity attribute or derived) */
    private string $actualTableName;

    /** @var bool Whether analysis has been performed */
    private bool $analyzed = false;

    /**
     * @param DatabaseInterface $db Database connection
     * @param class-string<T> $modelClass Model class name
     * @param CodecStrategyInterface $codecStrategy Strategy for creating codecs
     * @param string|null $tableName Override table name (optional)
     */
    public function __construct(
        protected DatabaseInterface $db,
        protected string $modelClass,
        protected CodecStrategyInterface $codecStrategy,
        ?string $tableName = null
    ) {
        $this->actualTableName = $tableName ?? $this->deriveTableName();

        // Validate that the model class has required attributes
        $this->validateRequiredAttributes();

        // Perform initial analysis to catch attribute issues early
        $this->analyzeModelAttributes();
    }

    /**
     * Validate that the model class has all required attributes
     * Throws helpful exceptions with instructions on what's missing
     */
    private function validateRequiredAttributes(): void
    {
        $reflection = new ReflectionClass($this->modelClass);
        $className = $reflection->getShortName();

        // Check for Entity attribute
        $entityAttributes = $reflection->getAttributes(Entity::class);
        if (empty($entityAttributes)) {
            throw new RepositoryException(
                "Model '{$className}' must have #[Entity] attribute.\n" .
                "Add: #[Entity(table: 'your_table_name')] above the class declaration.\n" .
                "Example:\n" .
                "#[Entity(table: 'users')]\n" .
                "class {$className} { ... }"
            );
        }

        // Check that at least one property has a column attribute
        $hasColumnAttributes = false;
        $publicProperties = $reflection->getProperties(\ReflectionProperty::IS_PUBLIC);

        if (empty($publicProperties)) {
            throw new RepositoryException(
                "Model '{$className}' has no public properties.\n" .
                "Repository models must have public properties with column attributes.\n" .
                "Example:\n" .
                "class {$className} {\n" .
                "    #[Key]\n" .
                "    #[IntegerColumn('id')]\n" .
                "    public ?int \$id = null;\n" .
                "}"
            );
        }

        $missingAttributes = [];
        foreach ($publicProperties as $property) {
            // Skip ignored properties and navigation properties
            if (!empty($property->getAttributes(Ignore::class)) ||
                !empty($property->getAttributes(Navigation::class))) {
                continue;
            }

            $columnAttributes = $property->getAttributes(Column::class, \ReflectionAttribute::IS_INSTANCEOF);
            if (!empty($columnAttributes)) {
                $hasColumnAttributes = true;
            } else {
                $missingAttributes[] = $property->getName();
            }
        }

        if (!$hasColumnAttributes) {
            throw new RepositoryException(
                "Model '{$className}' has no properties with column attributes.\n" .
                "All public properties must have column attributes (or be marked with #[Ignore]).\n" .
                "Example column attributes:\n" .
                "  #[IntegerColumn('id')] for integers\n" .
                "  #[VarcharColumn('name', length: 100)] for strings\n" .
                "  #[BooleanColumn('is_active')] for booleans\n" .
                "  #[DateTimeColumn('created_at')] for dates\n" .
                "  #[JsonColumn('metadata')] for JSON data"
            );
        }

        if (!empty($missingAttributes)) {
            $propertyList = implode(', $', $missingAttributes);
            throw new RepositoryException(
                "Model '{$className}' has properties without column attributes: \${$propertyList}\n" .
                "Each public property must have a column attribute or be marked with #[Ignore].\n" .
                "Add appropriate column attributes:\n" .
                "  #[IntegerColumn('column_name')] for integers\n" .
                "  #[VarcharColumn('column_name', length: 255)] for strings\n" .
                "  #[BooleanColumn('column_name')] for booleans\n" .
                "  #[DateTimeColumn('column_name')] for dates\n" .
                "  #[JsonColumn('column_name')] for JSON data\n" .
                "Or mark as ignored:\n" .
                "  #[Ignore] public \$temporaryProperty;"
            );
        }

        // Check for primary key
        $hasPrimaryKey = false;
        foreach ($publicProperties as $property) {
            if (!empty($property->getAttributes(Key::class))) {
                $hasPrimaryKey = true;
                break;
            }
        }

        if (!$hasPrimaryKey) {
            throw new RepositoryException(
                "Model '{$className}' has no primary key.\n" .
                "Add #[Key] attribute to the primary key property.\n" .
                "Example:\n" .
                "#[Key]\n" .
                "#[IntegerColumn('id')]\n" .
                "public ?int \$id = null;"
            );
        }
    }

    /**
     * Analyze model attributes and build mappings
     */
    private function analyzeModelAttributes(): void
    {
        if ($this->analyzed) {
            return;
        }

        $reflection = new ReflectionClass($this->modelClass);

        // Get table name from Entity attribute
        $entityAttributes = $reflection->getAttributes(Entity::class);
        if (!empty($entityAttributes)) {
            $entityAttr = $entityAttributes[0]->newInstance();
            $this->actualTableName = $entityAttr->getFullTableName();
        }

        // Analyze properties
        foreach ($reflection->getProperties() as $property) {
            // Skip ignored properties and navigation properties
            if (!empty($property->getAttributes(Ignore::class)) ||
                !empty($property->getAttributes(Navigation::class))) {
                continue;
            }

            $columnAttributes = $property->getAttributes(Column::class, \ReflectionAttribute::IS_INSTANCEOF);
            if (empty($columnAttributes)) {
                continue;
            }

            $columnAttr = $columnAttributes[0]->newInstance();
            $propertyName = $property->getName();
            $columnName = $columnAttr->getColumnName($propertyName);

            // Field mapping
            $this->fieldMappings[$propertyName] = $columnName;

            // Validation schema
            $this->validationSchemas[$propertyName] = $columnAttr->getJsonSchema();

            // Codec creation
            $codec = $columnAttr->createCodec($this->codecStrategy, $property);
            if ($codec) {
                // Update codec with correct field name
                $this->fieldCodecs[$propertyName] = new class($codec, $propertyName) implements FieldCodecInterface {
                    public function __construct(
                        private FieldCodecInterface $innerCodec,
                        private string $fieldName
                    ) {}

                    public function fromStorage(mixed $storageValue): mixed {
                        return $this->innerCodec->fromStorage($storageValue);
                    }

                    public function toStorage(mixed $domainValue): mixed {
                        return $this->innerCodec->toStorage($domainValue);
                    }

                    public function normalizeDomain(mixed $domainValue): mixed {
                        return $this->innerCodec->normalizeDomain($domainValue);
                    }

                    public function getFieldName(): string {
                        return $this->fieldName;
                    }
                };
            }

            // Primary key detection
            if (!empty($property->getAttributes(Key::class))) {
                $this->primaryKeyProperty = $propertyName;
                $this->primaryKeyColumn = $columnName;
            }
        }

        $this->analyzed = true;
    }

    private function deriveTableName(): string
    {
        $reflection = new ReflectionClass($this->modelClass);
        return strtolower($reflection->getShortName()) . 's';
    }

    // RepositoryInterface implementation

    public function name(): string
    {
        $this->analyzeModelAttributes();
        return $this->actualTableName;
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

    public function create(): object
    {
        return new ($this->modelClass)();
    }

    public function load(mixed $id): ?object
    {
        $this->analyzeModelAttributes();

        $row = $this->db->queryOne(
            "SELECT * FROM {$this->actualTableName} WHERE {$this->pk()} = ?",
            [$id]
        );

        if (!$row) {
            return null;
        }

        return $this->hydrate($row);
    }

    public function loadMany(string|int ...$ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $this->analyzeModelAttributes();

        // Remove duplicates and ensure we have valid IDs
        $uniqueIds = array_unique($ids);
        $placeholders = str_repeat('?,', count($uniqueIds) - 1) . '?';

        $rows = $this->db->query(
            "SELECT * FROM {$this->actualTableName} WHERE {$this->pk()} IN ($placeholders)",
            array_values($uniqueIds)
        );

        $models = [];
        foreach ($rows as $row) {
            $model = $this->hydrate($row);
            $models[] = $model;
        }

        return $models;
    }


    public function isInvalid(object $model): ?array
    {
        $this->analyzeModelAttributes();

        $fieldErrors = [];

        foreach ($this->validationSchemas as $propertyName => $schema) {
            $value = $model->$propertyName ?? null;

            // Basic validation - can be extended with a proper JSON Schema validator
            $propertyErrors = $this->validateProperty($value, $schema);
            if (!empty($propertyErrors)) {
                // Take the first error for each field for developer ergonomics
                $fieldErrors[$propertyName] = is_array($propertyErrors) ? $propertyErrors[0] : $propertyErrors;
            }
        }

        return empty($fieldErrors) ? null : $fieldErrors;
    }

    private function validateProperty(mixed $value, array $schema): array
    {
        $errors = [];

        // Basic required validation
        if ($value === null || $value === '') {
            if (isset($schema['type']) && !($schema['nullable'] ?? false)) {
                $errors[] = "Field is required";
            }
            return $errors; // Skip other validations for null values
        }

        // Type validation
        if (isset($schema['type'])) {
            $types = is_array($schema['type']) ? $schema['type'] : [$schema['type']];
            $valid = false;

            foreach ($types as $type) {
                $typeValid = match($type) {
                    'string' => is_string($value),
                    'integer' => is_int($value),
                    'number' => is_numeric($value),
                    'boolean' => is_bool($value),
                    'object' => is_array($value) || is_object($value),
                    default => true
                };

                if ($typeValid) {
                    $valid = true;
                    break;
                }
            }

            if (!$valid) {
                $expectedTypes = is_array($schema['type']) ? implode(' or ', $schema['type']) : $schema['type'];
                $errors[] = "Expected {$expectedTypes}, got " . gettype($value);
            }
        }

        // String validations
        if (is_string($value)) {
            if (isset($schema['minLength']) && strlen($value) < $schema['minLength']) {
                $errors[] = "String is too short (minimum {$schema['minLength']} characters)";
            }
            if (isset($schema['maxLength']) && strlen($value) > $schema['maxLength']) {
                $errors[] = "String is too long (maximum {$schema['maxLength']} characters)";
            }
            if (isset($schema['pattern']) && !preg_match('/' . $schema['pattern'] . '/', $value)) {
                $errors[] = "String does not match required pattern";
            }
        }

        // Numeric validations
        if (is_numeric($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                $errors[] = "Value is too small (minimum {$schema['minimum']})";
            }
            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                $errors[] = "Value is too large (maximum {$schema['maximum']})";
            }
            if (isset($schema['exclusiveMinimum']) && $value <= $schema['exclusiveMinimum']) {
                $errors[] = "Value must be greater than {$schema['exclusiveMinimum']}";
            }
            if (isset($schema['exclusiveMaximum']) && $value >= $schema['exclusiveMaximum']) {
                $errors[] = "Value must be less than {$schema['exclusiveMaximum']}";
            }
        }

        // Enum validation
        if (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            $enumValues = implode(', ', $schema['enum']);
            $errors[] = "Value must be one of: {$enumValues}";
        }

        return $errors;
    }

    public function transformFromStorage(string $field, mixed $value): mixed
    {
        $this->analyzeModelAttributes();

        if (isset($this->fieldCodecs[$field])) {
            return $this->fieldCodecs[$field]->fromStorage($value);
        }

        return $value;
    }

    public function transformToStorage(string $field, mixed $value): mixed
    {
        $this->analyzeModelAttributes();

        if (isset($this->fieldCodecs[$field])) {
            return $this->fieldCodecs[$field]->toStorage($value);
        }

        return $value;
    }

    public function getFieldCodec(string $field): ?object
    {
        $this->analyzeModelAttributes();
        return $this->fieldCodecs[$field] ?? null;
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

    public function dehydrate(object $model): array
    {
        $this->analyzeModelAttributes();

        $data = [];

        foreach ($this->fieldMappings as $propertyName => $columnName) {
            $value = $model->$propertyName ?? null;
            $data[$columnName] = $this->transformToStorage($propertyName, $value);
        }

        return $data;
    }

    public function insert(object $model): mixed
    {
        $this->analyzeModelAttributes();

        $data = $this->dehydrate($model);

        // Remove primary key if it's auto-increment
        if ($this->primaryKeyColumn && isset($data[$this->primaryKeyColumn])) {
            unset($data[$this->primaryKeyColumn]);
        }

        $columns = array_keys($data);
        $placeholders = array_fill(0, count($data), '?');

        $sql = "INSERT INTO {$this->actualTableName} (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";

        $this->db->exec($sql, array_values($data));

        return $this->db->lastInsertId();
    }

    public function update(object $model, mixed $id): int
    {
        $this->analyzeModelAttributes();

        $data = $this->dehydrate($model);

        // Remove primary key from update data
        if ($this->primaryKeyColumn && isset($data[$this->primaryKeyColumn])) {
            unset($data[$this->primaryKeyColumn]);
        }

        $setParts = [];
        foreach (array_keys($data) as $column) {
            $setParts[] = "$column = ?";
        }

        $sql = "UPDATE {$this->actualTableName} SET " . implode(', ', $setParts) . " WHERE {$this->pk()} = ?";

        $params = array_values($data);
        $params[] = $id;

        return $this->db->exec($sql, $params);
    }

    public function delete(mixed $id): int
    {
        $this->analyzeModelAttributes();

        return $this->db->exec("DELETE FROM {$this->actualTableName} WHERE {$this->pk()} = ?", [$id]);
    }

    // Stub implementations for other RepositoryInterface methods
    public function all(): Table {
        throw new \Exception('Not implemented - use Repository wrapper');
    }

    public function fetchOne(array $where): ?array {
        throw new \Exception('Not implemented');
    }

    public function fetchMany(array $where, array $order = [], ?int $limit = null, int $offset = 0): iterable {
        $this->analyzeModelAttributes();

        $sql = "SELECT * FROM {$this->actualTableName}";
        $params = [];

        // Build WHERE clause
        if (!empty($where)) {
            $whereParts = [];
            foreach ($where as $field => $value) {
                $columnName = $this->fieldMappings[$field] ?? $field;
                $convertedValue = $this->transformToStorage($field, $value);

                $whereParts[] = "$columnName = ?";
                $params[] = $convertedValue;
            }
            $sql .= " WHERE " . implode(' AND ', $whereParts);
        }

        // Build ORDER BY clause
        if (!empty($order)) {
            $orderClauses = [];
            foreach ($order as $field => $direction) {
                $columnName = $this->fieldMappings[$field] ?? $field;
                $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
                $orderClauses[] = "$columnName $direction";
            }
            $sql .= " ORDER BY " . implode(', ', $orderClauses);
        }

        // Add LIMIT and OFFSET
        if ($limit !== null) {
            $sql .= " LIMIT $limit";
            if ($offset > 0) {
                $sql .= " OFFSET $offset";
            }
        }

        return $this->db->query($sql, $params);
    }

    /**
     * Fetch and hydrate multiple objects based on conditions
     * @param array $where
     * @param array $order
     * @param int|null $limit
     * @param int $offset
     * @return array<T> Array of hydrated model objects
     */
    public function findMany(array $where = [], array $order = [], ?int $limit = null, int $offset = 0): array
    {
        $rows = $this->fetchMany($where, $order, $limit, $offset);

        $models = [];
        foreach ($rows as $row) {
            $models[] = $this->hydrate($row);
        }

        return $models;
    }

    public function count(array $where = []): int {
        $this->analyzeModelAttributes();

        if (empty($where)) {
            return (int)$this->db->queryField("SELECT COUNT(*) FROM {$this->actualTableName}");
        }

        // Build WHERE clause
        $whereParts = [];
        $params = [];

        foreach ($where as $field => $value) {
            $columnName = $this->fieldMappings[$field] ?? $field;
            $convertedValue = $this->convertConditionValue($field, $value);

            $whereParts[] = "$columnName = ?";
            $params[] = $convertedValue;
        }

        $whereClause = implode(' AND ', $whereParts);
        return (int)$this->db->queryField(
            "SELECT COUNT(*) FROM {$this->actualTableName} WHERE $whereClause",
            $params
        );
    }

    public function isReadOnly(): bool {
        return false;
    }

    public function getFieldNames(): array {
        $this->analyzeModelAttributes();
        return array_keys($this->fieldMappings);
    }

    // Access control methods (can be overridden)
    public function canList(): bool { return true; }
    public function canCreate(): bool { return true; }
    public function canRead(object $model): bool { return true; }
    public function canUpdate(object $model): bool { return true; }
    public function canDelete(object $model): bool { return true; }
}