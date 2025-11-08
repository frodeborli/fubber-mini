# Tables\Attributes - Column Mapping Attributes

This namespace contains PHP attributes for mapping class properties to database columns in the Tables feature.

## Purpose

These attributes provide metadata about how entity properties should be mapped to database columns. They control column types, constraints, and behavior during serialization/deserialization.

## Usage

Attributes are applied to class properties to define their database representation:

```php
use mini\Tables\Attributes\{Entity, Column, Key, Generated};

#[Entity(table: 'users')]
class User {
    #[Key]
    #[Generated]
    #[Column(type: 'integer')]
    public int $id;

    #[Column(type: 'varchar', length: 255)]
    public string $email;

    #[Column(type: 'datetime')]
    public \DateTime $createdAt;
}
```

## Common Attributes

Attributes fall into several categories:

- **Entity metadata** - `#[Entity]`, `#[Key]`, `#[Generated]`
- **Column types** - `#[Column]`, `#[IntegerColumn]`, `#[VarcharColumn]`, `#[TextColumn]`, etc.
- **Special columns** - `#[JsonColumn]`, `#[EnumColumn]`, `#[BooleanColumn]`, `#[DecimalColumn]`
- **Relationships** - `#[Navigation]`
- **Exclusions** - `#[Ignore]`

See the main Tables documentation (`src/Tables/README.md`) for comprehensive examples and usage patterns.
