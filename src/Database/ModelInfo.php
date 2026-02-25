<?php

namespace mini\Database;

use mini\Database\Attributes\PrimaryKey;
use mini\Database\Attributes\Table;

/**
 * Parsed model metadata: table name and primary key
 *
 * Created by the model() function and cached per class.
 * Replaces the internal attribute parsing that was in Model::tableName()/primaryKey().
 */
final class ModelInfo
{
    public function __construct(
        public readonly string $tableName,
        public readonly string $primaryKey,
    ) {}

    public static function fromClass(string $class): self
    {
        $refClass = new \ReflectionClass($class);

        // Parse #[Table]
        $tableAttrs = $refClass->getAttributes(Table::class);
        if (empty($tableAttrs) || $tableAttrs[0]->newInstance()->name === null) {
            throw new \RuntimeException("$class must have #[Table(\"name\")] attribute");
        }
        $tableName = $tableAttrs[0]->newInstance()->name;

        // Parse #[PrimaryKey] — defaults to 'id'
        $primaryKey = 'id';
        foreach ($refClass->getProperties() as $prop) {
            if (!empty($prop->getAttributes(PrimaryKey::class))) {
                $primaryKey = $prop->getName();
                break;
            }
        }

        return new self($tableName, $primaryKey);
    }
}
