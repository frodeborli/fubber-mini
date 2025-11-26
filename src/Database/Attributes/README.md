# Database Attributes

Schema declaration attributes for entity classes. Inspired by Entity Framework Core's data annotations.

**Current Status:** Declaration only - these attributes document the database schema but are not yet used by the framework for automatic migrations or dehydration. They serve as:

1. **Documentation** - Schema lives alongside the code
2. **Future tooling** - Migration generators can read these
3. **IDE support** - Attributes provide context for developers

## Available Attributes

### Table

Maps entity class to database table.

```php
use mini\Database\Attributes\Table;

#[Table(name: 'users')]
class User {
    // ...
}
```

### Column

Maps property to database column with optional type and ordering.

```php
use mini\Database\Attributes\Column;

class User {
    #[Column(name: 'user_name', type: 'VARCHAR(255)', order: 1)]
    public string $name;

    #[Column(type: 'TIMESTAMP', order: 2)]
    public \DateTimeImmutable $created_at;

    #[Column(type: 'TEXT')]
    public string $bio;
}
```

**Parameters:**
- `name` - Column name (defaults to property name)
- `type` - SQL type (e.g., 'VARCHAR(255)', 'INTEGER', 'TIMESTAMP')
- `order` - Column order in table definition (0-based)

### PrimaryKey

Marks property as primary key.

```php
use mini\Database\Attributes\PrimaryKey;

class User {
    #[PrimaryKey]
    public ?int $id = null;
}

// Non-auto-increment primary key
class Session {
    #[PrimaryKey(autoIncrement: false)]
    public string $token;
}
```

### ForeignKey

Specifies foreign key relationship.

```php
use mini\Database\Attributes\ForeignKey;

class Post {
    // Applied to foreign key property
    #[ForeignKey(navigation: 'user', references: 'users.id', onDelete: 'CASCADE')]
    public int $user_id;

    public User $user;
}

// Or applied to navigation property
class Comment {
    public int $post_id;

    #[ForeignKey(property: 'post_id', references: 'posts.id', onDelete: 'CASCADE')]
    public Post $post;
}
```

**Parameters:**
- `property` - Property name holding the foreign key value
- `navigation` - Navigation property name
- `references` - Referenced table.column (e.g., 'users.id')
- `onDelete` - CASCADE, SET NULL, RESTRICT, NO ACTION
- `onUpdate` - CASCADE, SET NULL, RESTRICT, NO ACTION

### Index

Creates database index (single or composite).

```php
use mini\Database\Attributes\Index;

// Single column index (property-level)
class User {
    #[Index]
    public string $email;

    #[Index(unique: true)]
    public string $username;
}

// Composite indexes (class-level, repeatable)
#[Index(columns: ['last_name', 'first_name'])]
#[Index(columns: ['email'], unique: true)]
#[Index(columns: ['created_at'], descending: true)]
class User {
    public string $first_name;
    public string $last_name;
    public string $email;
    public \DateTimeImmutable $created_at;
}

// Per-column descending control
#[Index(columns: ['category', 'created_at'], descending: [false, true])]
class Article {
    public string $category;
    public \DateTimeImmutable $created_at;
}
```

**Parameters:**
- `columns` - Array of column names (for composite indexes, class-level only)
- `name` - Index name (auto-generated if not provided)
- `unique` - Whether this is a unique index
- `descending` - True for all DESC, or array of bool per column

### NotMapped

Excludes property from database mapping.

```php
use mini\Database\Attributes\NotMapped;

class User {
    public string $firstName;
    public string $lastName;

    #[NotMapped]
    public string $fullName;  // Computed property

    #[NotMapped]
    public array $cachedData = [];  // Transient data
}
```

## Complete Example

```php
use mini\Database\Attributes\{
    Table,
    Column,
    PrimaryKey,
    ForeignKey,
    Index,
    NotMapped
};

#[Table(name: 'blog_posts')]
#[Index(columns: ['user_id', 'published_at'], descending: [false, true])]
#[Index(columns: ['slug'], unique: true)]
class Post
{
    #[PrimaryKey]
    #[Column(type: 'INTEGER')]
    public ?int $id = null;

    #[Column(type: 'VARCHAR(255)', order: 1)]
    #[Index(unique: true)]
    public string $slug;

    #[Column(type: 'VARCHAR(500)', order: 2)]
    public string $title;

    #[Column(type: 'TEXT', order: 3)]
    public string $content;

    #[Column(type: 'INTEGER', order: 4)]
    #[ForeignKey(navigation: 'author', references: 'users.id', onDelete: 'CASCADE')]
    #[Index]
    public int $user_id;

    #[Column(type: 'TIMESTAMP', order: 5)]
    #[Index]
    public \DateTimeImmutable $published_at;

    #[Column(type: 'JSON', order: 6)]
    public array $metadata = [];

    // Navigation property (not a column)
    #[NotMapped]
    public ?User $author = null;

    // Computed property (not a column)
    #[NotMapped]
    public string $excerpt;
}
```

## Future Usage

These attributes are designed to support:

1. **Migration generators** - Read attributes to generate SQL schema
2. **Automatic dehydration** - Convert entities to database values
3. **Schema validation** - Ensure database matches entity definitions
4. **Documentation tools** - Generate schema documentation

## Relationship to Entity Framework Core

Mini's database attributes are directly inspired by [Entity Framework Core](https://learn.microsoft.com/en-us/ef/core/modeling/)'s data annotations, with adaptations for PHP and Mini's philosophy:

- Same naming and concepts where possible
- Simplified where EF Core's complexity isn't needed
- PHP-native types and conventions
- Declaration-first approach (no framework coupling yet)
