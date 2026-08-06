<?php
/**
 * Model Example
 *
 * Demonstrates extending Model for Active Record entities.
 *
 * NOTE: This example was previously named "model-trait" when ModelTrait existed
 * as a separate trait. ModelTrait has been merged into the abstract Model class.
 */

require __DIR__ . '/../vendor/autoload.php';

// Setup test database
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$pdo->exec('
    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        email TEXT NOT NULL UNIQUE,
        status TEXT DEFAULT "active",
        created_at TEXT
    )
');

// Create database instance
$db = new mini\Database\PDODatabase($pdo);

// Define User model extending Model
#[mini\Database\Attributes\Table('users')]
class User extends mini\Database\Model
{
    #[mini\Database\Attributes\PrimaryKey]
    public ?int $id = null;
    public string $name;
    public string $email;
    public string $status = 'active';
    #[mini\Database\Attributes\CreatedAt]
    public ?string $created_at = null;

    protected static function provideDatabase(): mini\Database\DatabaseInterface {
        global $db;
        return $db;
    }

    // Custom query scopes
    /** @return mini\Database\Query<User> */
    public static function active(): mini\Database\Query {
        return self::query()->eq('status', 'active');
    }

    /** @return mini\Database\Query<User> */
    public static function inactive(): mini\Database\Query {
        return self::query()->eq('status', 'inactive');
    }
}

echo "=== Model Examples ===\n\n";

// Example 1: Create and save new model
echo "1. Create and save new user:\n";
$user = new User();
$user->name = 'John Doe';
$user->email = 'john@example.com';
$user->status = 'active';
$affected = $user->save();

echo "   Affected rows: $affected\n";
echo "   User ID after save: {$user->id}\n";
echo "   Created at: {$user->created_at}\n\n";

// Example 2: Find by ID
echo "2. Find user by ID:\n";
$found = User::find($user->id);
echo "   Found: {$found->name} ({$found->email})\n\n";

// Example 3: Update and save
echo "3. Update existing user:\n";
$found->name = 'John Updated';
$affected = $found->save();
echo "   Affected rows: $affected\n";
echo "   ID unchanged: {$found->id}\n";

// Verify update
$verified = User::find($found->id);
echo "   Verified name: {$verified->name}\n\n";

// Example 4: Query with scopes
echo "4. Create more users and query with scopes:\n";

$user2 = new User();
$user2->name = 'Jane Smith';
$user2->email = 'jane@example.com';
$user2->status = 'active';
$user2->save();

$user3 = new User();
$user3->name = 'Bob Inactive';
$user3->email = 'bob@example.com';
$user3->status = 'inactive';
$user3->save();

echo "   All users: " . User::query()->count() . "\n";
echo "   Active users: " . User::active()->count() . "\n";
echo "   Inactive users: " . User::inactive()->count() . "\n\n";

// Example 5: Iterate with typed results
echo "5. Iterate through active users:\n";
foreach (User::active() as $activeUser) {
    echo "   - {$activeUser->name} ({$activeUser->status})\n";
}
echo "\n";

// Example 6: Delete
echo "6. Delete user:\n";
$toDelete = User::find($user3->id);
echo "   Deleting: {$toDelete->name}\n";
$affected = $toDelete->delete();
echo "   Affected rows: $affected\n";
echo "   Remaining users: " . User::query()->count() . "\n\n";

echo "=== All examples complete ===\n";
