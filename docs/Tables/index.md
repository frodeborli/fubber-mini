# Tables - ORM & Active Record

Map classes to database tables with attributes and use `mini\table()` for CRUD operations.

## Define Entity

```php
<?php
use mini\Attributes\{Entity, Key, VarcharColumn, IntegerColumn, Generated};

#[Entity(table: 'users')]
class User
{
    #[Key]
    #[IntegerColumn]
    #[Generated]
    public ?int $id = null;

    #[VarcharColumn(length: 100)]
    public string $username;

    #[VarcharColumn(length: 255)]
    public string $email;

    #[IntegerColumn]
    public int $created_at;
}
```

## CRUD Operations

```php
<?php
// Create
$user = new User();
$user->username = 'john';
$user->email = 'john@example.com';
$user->created_at = time();
mini\model_save($user);

// Read
$user = mini\table(User::class)->find($id);
$users = mini\table(User::class)->findAll();

// Update
$user->email = 'newemail@example.com';
mini\model_save($user);

// Delete
mini\model_delete($user);
```

## Query Methods

```php
<?php
$table = mini\table(User::class);

// Find by ID
$user = $table->find(123);

// Find by criteria
$user = $table->findBy(['username' => 'john']);
$users = $table->findAllBy(['active' => 1]);

// Count
$count = $table->count(['active' => 1]);

// Check existence
if ($table->exists(['username' => 'john'])) {
    // ...
}
```

## Validation

```php
<?php
// Check if valid
if (mini\model_invalid($user)) {
    $errors = mini\model_invalid($user);
    // Handle validation errors
}

// Check if modified
if (mini\model_dirty($user)) {
    // Save changes
    mini\model_save($user);
}
```

## Relationships

```php
<?php
use mini\Attributes\Navigation;

#[Entity(table: 'posts')]
class Post
{
    #[Key]
    #[IntegerColumn]
    public ?int $id = null;

    #[IntegerColumn]
    public int $user_id;

    #[Navigation(target: User::class, property: 'user_id')]
    public ?User $author = null;
}

// Load relationship
$post = mini\table(Post::class)->find($id);
$authorName = $post->author->username;
```

## API Reference

See `mini\Tables\Table` for full ORM API.
