<?php

/**
 * Database Attributes Example
 *
 * Shows how to declare database schema using attributes.
 * These are declaration-only for now - not yet used by the framework.
 *
 * Future uses:
 * - Migration generators
 * - Automatic dehydration
 * - Schema validation tools
 */

use mini\Database\Attributes\{
    Table,
    Column,
    PrimaryKey,
    ForeignKey,
    Index,
    NotMapped
};

// Simple entity with basic attributes
#[Table(name: 'users')]
#[Index(columns: ['email'], unique: true)]
class User
{
    #[PrimaryKey]
    public ?int $id = null;

    #[Column(type: 'VARCHAR(255)')]
    public string $name;

    #[Column(type: 'VARCHAR(255)')]
    #[Index(unique: true)]
    public string $email;

    #[Column(type: 'TIMESTAMP')]
    public \DateTimeImmutable $created_at;

    #[NotMapped]  // Not persisted to database
    public ?string $temporaryToken = null;
}

// Entity with foreign keys and composite indexes
#[Table(name: 'posts')]
#[Index(columns: ['user_id', 'published_at'], descending: [false, true])]
#[Index(columns: ['slug'], unique: true)]
class Post
{
    #[PrimaryKey]
    public ?int $id = null;

    #[Column(name: 'post_title', type: 'VARCHAR(500)')]
    public string $title;

    #[Column(type: 'VARCHAR(255)')]
    public string $slug;

    #[Column(type: 'TEXT')]
    public string $content;

    #[Column(type: 'INTEGER')]
    #[ForeignKey(navigation: 'author', references: 'users.id', onDelete: 'CASCADE')]
    public int $user_id;

    #[Column(type: 'TIMESTAMP')]
    public ?\DateTimeImmutable $published_at = null;

    #[Column(type: 'JSON')]
    public array $tags = [];

    // Navigation property - not a database column
    #[NotMapped]
    public ?User $author = null;

    // Computed property
    #[NotMapped]
    public function getExcerpt(): string
    {
        return substr($this->content, 0, 200) . '...';
    }
}

// Many-to-many relationship with composite primary key
#[Table(name: 'post_tags')]
#[Index(columns: ['post_id', 'tag_id'], unique: true)]
class PostTag
{
    #[Column(type: 'INTEGER')]
    #[ForeignKey(references: 'posts.id', onDelete: 'CASCADE')]
    #[PrimaryKey(autoIncrement: false)]
    public int $post_id;

    #[Column(type: 'INTEGER')]
    #[ForeignKey(references: 'tags.id', onDelete: 'CASCADE')]
    #[PrimaryKey(autoIncrement: false)]
    public int $tag_id;

    #[Column(type: 'TIMESTAMP')]
    public \DateTimeImmutable $created_at;
}

// Entity with custom column ordering
#[Table(name: 'products')]
class Product
{
    #[PrimaryKey]
    #[Column(type: 'INTEGER', order: 0)]
    public ?int $id = null;

    #[Column(type: 'VARCHAR(255)', order: 1)]
    public string $sku;

    #[Column(type: 'VARCHAR(500)', order: 2)]
    public string $name;

    #[Column(type: 'DECIMAL(10,2)', order: 3)]
    public float $price;

    #[Column(type: 'INTEGER', order: 4)]
    public int $stock;
}

// USAGE: These attributes are currently for declaration only
// They document the schema but don't affect runtime behavior yet

// In the future, a migration generator could read these:
// $schema = new SchemaReader();
// $sql = $schema->generateMigration(User::class, Post::class, PostTag::class);
// echo $sql;

// Or automatic dehydration:
// $post = new Post();
// $post->title = 'Hello World';
// $post->published_at = new DateTimeImmutable();
// $post->tags = ['php', 'mini'];
//
// // Future: db()->insert() reads attributes and converts types automatically
// db()->insert('posts', $post);
