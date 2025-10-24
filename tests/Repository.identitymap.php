<?php

/**
 * Test Repository wrapper with IdentityMap integration
 */

// Find composer autoloader
$autoloader = realpath(__DIR__ . '/../vendor/autoload.php')
    ?: realpath(__DIR__ . '/../../../vendor/autoload.php')
    ?: realpath(__DIR__ . '/../../vendor/autoload.php');

if (!$autoloader) {
    echo "Error: Could not find Composer autoloader\n";
    exit(1);
}

require_once $autoloader;

use mini\Repository\DatabaseRepository;
use mini\Repository\RepositoryInterface;
use mini\Repository;
use mini\ModelTracker;
use mini\DB;

function test(string $description, callable $test): void
{
    try {
        $test();
        echo "✓ $description\n";
    } catch (\Exception $e) {
        echo "✗ $description\n";
        echo "  Error: " . $e->getMessage() . "\n";
        echo "  Trace: " . $e->getTraceAsString() . "\n";
    }
}

function assertEqual($expected, $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        $expectedStr = var_export($expected, true);
        $actualStr = var_export($actual, true);
        throw new \Exception("$message\nExpected: $expectedStr\nActual: $actualStr");
    }
}

// Test model class
class IdentityTestUser
{
    public int $id = 0;
    public string $name = '';
    public string $email = '';

    public function __construct(int $id = 0, string $name = '', string $email = '')
    {
        $this->id = $id;
        $this->name = $name;
        $this->email = $email;
    }
}

// Mock repository implementation for testing
class MockUserRepository implements RepositoryInterface
{
    private array $data = [];
    private int $nextId = 1;

    public function __construct()
    {
        // Pre-populate with test data
        $this->data[1] = ['id' => 1, 'name' => 'John Doe', 'email' => 'john@example.com'];
        $this->data[2] = ['id' => 2, 'name' => 'Jane Smith', 'email' => 'jane@example.com'];
        $this->nextId = 3;
    }

    public function name(): string { return 'identity_test_users'; }
    public function pk(): string { return 'id'; }
    public function getModelClass(): string { return IdentityTestUser::class; }
    public function isReadOnly(): bool { return false; }
    public function getFieldNames(): array { return ['id', 'name', 'email']; }

    public function create(): object
    {
        return new IdentityTestUser();
    }

    public function load(mixed $id): ?object
    {
        $row = $this->data[$id] ?? null;
        if (!$row) return null;
        return $this->hydrate($row);
    }

    public function hydrate(array $row): object
    {
        return new IdentityTestUser($row['id'], $row['name'], $row['email']);
    }

    public function dehydrate(object $model): array
    {
        return [
            'id' => $model->id,
            'name' => $model->name,
            'email' => $model->email
        ];
    }

    public function validate(object $model): array { return []; }

    public function insert(object $model): mixed
    {
        $id = $this->nextId++;
        $model->id = $id;
        $this->data[$id] = $this->dehydrate($model);
        return $id;
    }

    public function update(object $model, mixed $id): int
    {
        if (!isset($this->data[$id])) return 0;
        $this->data[$id] = $this->dehydrate($model);
        return 1;
    }

    public function delete(mixed $id): int
    {
        if (!isset($this->data[$id])) return 0;
        unset($this->data[$id]);
        return 1;
    }

    // Stub implementations for interface compliance
    public function all(): \mini\Table { throw new \Exception('Not implemented'); }
    public function fetchOne(array $where): ?array { return null; }
    public function fetchMany(array $where, array $order = [], ?int $limit = null, int $offset = 0): iterable { return []; }
    public function count(array $where = []): int { return 0; }
    public function convertConditionValue(string $field, mixed $value): mixed { return $value; }
    public function canList(): bool { return true; }
    public function canCreate(): bool { return true; }
    public function canRead(object $model): bool { return true; }
    public function canUpdate(object $model): bool { return true; }
    public function canDelete(object $model): bool { return true; }
}

echo "Testing Repository with IdentityMap integration...\n\n";

test('Same ID returns identical object instances', function() {
    $mockRepo = new MockUserRepository();
    $repo = new Repository($mockRepo);

    // Load same user twice
    $user1 = $repo->load(1);
    $user2 = $repo->load(1);

    assertEqual(true, $user1 === $user2, 'Loading same ID should return identical object');
    assertEqual(1, $user1->id, 'User should have correct ID');
    assertEqual('John Doe', $user1->name, 'User should have correct name');
});

test('ModelTracker correctly identifies loaded models', function() {
    $mockRepo = new MockUserRepository();
    $repo = new Repository($mockRepo);

    $user = $repo->load(1);

    assertEqual(true, ModelTracker::isLoaded($user), 'Loaded model should be tracked');
    assertEqual('identity_test_users', ModelTracker::getRepositoryName($user), 'Should track correct repository name');

    $originalData = ModelTracker::getOriginalData($user);
    assertEqual(1, $originalData['id'], 'Should store original data');
});

test('Changes detection works with identity map', function() {
    $mockRepo = new MockUserRepository();
    $repo = new Repository($mockRepo);

    $user = $repo->load(1);
    assertEqual(false, $repo->isDirty($user), 'Freshly loaded model should not be dirty');

    // Modify the user
    $user->name = 'Modified Name';
    assertEqual(true, $repo->isDirty($user), 'Modified model should be dirty');

    // Load same user again - should get same instance with modifications
    $user2 = $repo->load(1);
    assertEqual(true, $user === $user2, 'Should get same instance');
    assertEqual('Modified Name', $user2->name, 'Should have modifications');
    assertEqual(true, $repo->isDirty($user2), 'Same instance should still be dirty');
});

test('Save operations update identity map', function() {
    $mockRepo = new MockUserRepository();
    $repo = new Repository($mockRepo);

    // Create new user
    $newUser = $repo->create();
    $newUser->name = 'New User';
    $newUser->email = 'new@example.com';

    assertEqual(false, ModelTracker::isLoaded($newUser), 'New user should not be tracked initially');

    // Save (insert)
    $success = $repo->saveModel($newUser);
    assertEqual(true, $success, 'Save should succeed');
    assertEqual(true, ModelTracker::isLoaded($newUser), 'Saved user should be tracked');

    // Load by new ID should return same instance
    $loadedUser = $repo->load($newUser->id);
    assertEqual(true, $newUser === $loadedUser, 'Loading saved user should return same instance');
});

test('Delete removes from identity map', function() {
    $mockRepo = new MockUserRepository();
    $repo = new Repository($mockRepo);

    $user = $repo->load(2);
    assertEqual(true, ModelTracker::isLoaded($user), 'User should be tracked before delete');

    $success = $repo->deleteModel($user);
    assertEqual(true, $success, 'Delete should succeed');
    assertEqual(false, ModelTracker::isLoaded($user), 'User should not be tracked after delete');

    // Trying to load again should return null (since it's deleted from mock data)
    $deletedUser = $repo->load(2);
    assertEqual(null, $deletedUser, 'Loading deleted user should return null');
});

test('Multiple repositories have separate identity maps', function() {
    $mockRepo1 = new MockUserRepository();
    $mockRepo2 = new MockUserRepository();

    // Change the name for repo2 to simulate different repository
    $repo1 = new Repository($mockRepo1);

    // Create a mock with different name
    $mockRepo2 = new class extends MockUserRepository {
        public function name(): string { return 'other_users'; }
    };
    $repo2 = new Repository($mockRepo2);

    $user1 = $repo1->load(1);
    $user2 = $repo2->load(1);

    // Should be different instances because they're from different repositories
    assertEqual(false, $user1 === $user2, 'Same ID from different repositories should be different instances');
    assertEqual('identity_test_users', ModelTracker::getRepositoryName($user1), 'User1 should be from identity_test_users');
    assertEqual('other_users', ModelTracker::getRepositoryName($user2), 'User2 should be from other_users');
});

echo "\nRepository IdentityMap integration tests completed!\n";