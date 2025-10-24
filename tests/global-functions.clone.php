<?php

/**
 * Test global model functions with clone-based state tracking
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
class GlobalTestUser
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
class MockGlobalUserRepository implements RepositoryInterface
{
    private array $data = [];
    private int $nextId = 1;

    public function __construct()
    {
        $this->data[1] = ['id' => 1, 'name' => 'Global User', 'email' => 'global@example.com'];
        $this->nextId = 2;
    }

    public function name(): string { return 'global_test_users'; }
    public function pk(): string { return 'id'; }
    public function getModelClass(): string { return GlobalTestUser::class; }
    public function isReadOnly(): bool { return false; }
    public function getFieldNames(): array { return ['id', 'name', 'email']; }

    public function create(): object { return new GlobalTestUser(); }

    public function load(mixed $id): ?object
    {
        $row = $this->data[$id] ?? null;
        if (!$row) return null;
        return $this->hydrate($row);
    }

    public function hydrate(array $row): object
    {
        return new GlobalTestUser($row['id'], $row['name'], $row['email']);
    }

    public function dehydrate(object $model): array
    {
        return ['id' => $model->id, 'name' => $model->name, 'email' => $model->email];
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

    // Stub implementations
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

// Set up global repositories for testing
if (!isset($GLOBALS['app']['repositories'])) {
    $GLOBALS['app']['repositories'] = new \mini\Util\InstanceStore(\mini\Repository\RepositoryInterface::class);
}
$GLOBALS['app']['repositories']->set(GlobalTestUser::class, new MockGlobalUserRepository());

echo "Testing global model functions with clone-based state tracking...\n\n";

test('model_dirty() works with global functions', function() {
    $user = \mini\table(GlobalTestUser::class)->load(1);
    assertEqual(false, \mini\model_dirty($user), 'Freshly loaded model should not be dirty');

    $user->name = 'Modified via global';
    assertEqual(true, \mini\model_dirty($user), 'Modified model should be dirty');
});

test('model_save() works with global functions', function() {
    $user = \mini\table(GlobalTestUser::class)->load(1);
    $user->name = 'Saved via global';

    assertEqual(true, \mini\model_dirty($user), 'Should be dirty before save');
    $success = \mini\model_save($user);
    assertEqual(true, $success, 'Save should succeed');
    assertEqual(false, \mini\model_dirty($user), 'Should not be dirty after save');
});

test('model_invalid() works with global functions', function() {
    $user = \mini\table(GlobalTestUser::class)->create();
    $user->name = 'Test User';

    $errors = \mini\model_invalid($user);
    assertEqual(null, $errors, 'Valid model should return null');
});

test('Complete workflow with global functions', function() {
    // Create new user
    $user = \mini\table(GlobalTestUser::class)->create();
    $user->name = 'Workflow User';
    $user->email = 'workflow@example.com';

    assertEqual(true, \mini\model_dirty($user), 'New user should be dirty');

    // Save
    $success = \mini\model_save($user);
    assertEqual(true, $success, 'Save should succeed');
    assertEqual(false, \mini\model_dirty($user), 'Should not be dirty after save');

    // Modify and save again
    $user->name = 'Updated Workflow User';
    assertEqual(true, \mini\model_dirty($user), 'Should be dirty after modification');

    $success = \mini\model_save($user);
    assertEqual(true, $success, 'Second save should succeed');
    assertEqual(false, \mini\model_dirty($user), 'Should not be dirty after second save');

    // Delete
    $success = \mini\model_delete($user);
    assertEqual(true, $success, 'Delete should succeed');
});

test('Original scenario from user question', function() {
    // Test the specific scenario: load, save, modify
    $u = \mini\table(GlobalTestUser::class)->load(1);
    \mini\model_save($u);  // Save current state (no-op if not dirty)
    $u->name = "Bob";      // Modify after save

    // Bob should NOT be saved yet (save already happened)
    assertEqual(true, \mini\model_dirty($u), 'Bob modification should make model dirty');

    // But if we save again, Bob gets saved
    $success = \mini\model_save($u);
    assertEqual(true, $success, 'Save should succeed');
    assertEqual(false, \mini\model_dirty($u), 'Should not be dirty after save');

    // Verify Bob was actually saved by checking the underlying data
    $freshLoad = \mini\table(GlobalTestUser::class)->load(1);
    assertEqual("Bob", $freshLoad->name, 'Bob should be persisted in database');
});

echo "\nGlobal model functions tests completed!\n";