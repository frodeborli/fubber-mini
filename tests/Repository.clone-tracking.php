<?php

/**
 * Test Repository with clone-based state tracking
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

use mini\Repository\RepositoryInterface;
use mini\Repository;

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
class CloneTestUser
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
class MockCloneUserRepository implements RepositoryInterface
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

    public function name(): string { return 'clone_test_users'; }
    public function pk(): string { return 'id'; }
    public function getModelClass(): string { return CloneTestUser::class; }
    public function isReadOnly(): bool { return false; }
    public function getFieldNames(): array { return ['id', 'name', 'email']; }

    public function create(): object
    {
        return new CloneTestUser();
    }

    public function load(mixed $id): ?object
    {
        $row = $this->data[$id] ?? null;
        if (!$row) return null;
        return $this->hydrate($row);
    }

    public function hydrate(array $row): object
    {
        return new CloneTestUser($row['id'], $row['name'], $row['email']);
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

echo "Testing Repository with clone-based state tracking...\n\n";

test('Load creates original state clone', function() {
    $mockRepo = new MockCloneUserRepository();
    $repo = new Repository($mockRepo);

    $user = $repo->load(1);
    assertEqual(false, $repo->isDirty($user), 'Freshly loaded model should not be dirty');
    assertEqual('John Doe', $user->name, 'User should have correct name');
});

test('Modification makes model dirty', function() {
    $mockRepo = new MockCloneUserRepository();
    $repo = new Repository($mockRepo);

    $user = $repo->load(1);
    assertEqual(false, $repo->isDirty($user), 'Should not be dirty initially');

    $user->name = 'Modified Name';
    assertEqual(true, $repo->isDirty($user), 'Should be dirty after modification');
});

test('Save updates original state clone', function() {
    $mockRepo = new MockCloneUserRepository();
    $repo = new Repository($mockRepo);

    $user = $repo->load(1);
    $user->name = 'Updated Name';
    assertEqual(true, $repo->isDirty($user), 'Should be dirty before save');

    $success = $repo->saveModel($user);
    assertEqual(true, $success, 'Save should succeed');
    assertEqual(false, $repo->isDirty($user), 'Should not be dirty after save');
});

test('Multiple saves work correctly', function() {
    $mockRepo = new MockCloneUserRepository();
    $repo = new Repository($mockRepo);

    $user = $repo->load(1);

    // First modification and save
    $user->name = 'First Update';
    assertEqual(true, $repo->isDirty($user), 'Should be dirty after first modification');
    $repo->saveModel($user);
    assertEqual(false, $repo->isDirty($user), 'Should not be dirty after first save');

    // Second modification and save
    $user->name = 'Second Update';
    assertEqual(true, $repo->isDirty($user), 'Should be dirty after second modification');
    $repo->saveModel($user);
    assertEqual(false, $repo->isDirty($user), 'Should not be dirty after second save');
});

test('Identity map ensures same object instances', function() {
    $mockRepo = new MockCloneUserRepository();
    $repo = new Repository($mockRepo);

    $user1 = $repo->load(1);
    $user2 = $repo->load(1);

    assertEqual(true, $user1 === $user2, 'Loading same ID should return identical object');
});

test('New models are dirty by default', function() {
    $mockRepo = new MockCloneUserRepository();
    $repo = new Repository($mockRepo);

    $newUser = $repo->create();
    $newUser->name = 'New User';
    $newUser->email = 'new@example.com';

    assertEqual(true, $repo->isDirty($newUser), 'New model should be dirty');
});

test('Save of new model creates tracking', function() {
    $mockRepo = new MockCloneUserRepository();
    $repo = new Repository($mockRepo);

    $newUser = $repo->create();
    $newUser->name = 'New User';
    $newUser->email = 'new@example.com';

    $success = $repo->saveModel($newUser);
    assertEqual(true, $success, 'Save should succeed');
    assertEqual(false, $repo->isDirty($newUser), 'Should not be dirty after save');

    // Modify and check dirty again
    $newUser->name = 'Modified New User';
    assertEqual(true, $repo->isDirty($newUser), 'Should be dirty after modification');
});

test('Delete removes from tracking', function() {
    $mockRepo = new MockCloneUserRepository();
    $repo = new Repository($mockRepo);

    $user = $repo->load(2);
    assertEqual(false, $repo->isDirty($user), 'Should not be dirty initially');

    $success = $repo->deleteModel($user);
    assertEqual(true, $success, 'Delete should succeed');

    // Trying to load again should return null (deleted from mock data)
    $deletedUser = $repo->load(2);
    assertEqual(null, $deletedUser, 'Loading deleted user should return null');
});

test('Clone-based comparison works with object changes', function() {
    $mockRepo = new MockCloneUserRepository();
    $repo = new Repository($mockRepo);

    $user = $repo->load(1);

    // Make a complex change
    $user->name = 'Complex Update';
    $user->email = 'updated@example.com';

    assertEqual(true, $repo->isDirty($user), 'Should detect multiple field changes');

    $repo->saveModel($user);
    assertEqual(false, $repo->isDirty($user), 'Should not be dirty after save');

    // Verify we can detect changes from the new baseline
    $user->name = 'Another Update';
    assertEqual(true, $repo->isDirty($user), 'Should detect changes from updated baseline');
});

echo "\nRepository clone-based state tracking tests completed!\n";