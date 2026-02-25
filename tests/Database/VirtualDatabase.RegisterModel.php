<?php
/**
 * Test VirtualDatabase::registerModel() — Model-aware security
 *
 * Tests:
 * - Layer 1: Scope WHERE merging (readable, updatable, deletable)
 * - Layer 2: ModelScopedTable entity authorization (insert gate, per-entity can())
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Test;
use mini\Mini;
use mini\Phase;
use mini\Lifetime;
use mini\Authorizer\Ability;
use mini\Authorizer\Authorization;
use mini\Authorizer\AuthorizationQuery;
use mini\Database\VirtualDatabase;
use mini\Database\Model;
use mini\Database\Dehydrator;
use mini\Database\ModelTableConfig;
use mini\Database\ModelScopedTable;
use mini\Database\Attributes\Table;
use mini\Database\Attributes\PrimaryKey;
use mini\Table\InMemoryTable;
use mini\Table\ColumnDef;
use mini\Table\Types\ColumnType;
use mini\Table\Types\IndexType;
use mini\Exceptions\AccessDeniedException;

// ─────────────────────────────────────────────────────────────────────────
// Test Model class — uses a VDB stored in a static property
// ─────────────────────────────────────────────────────────────────────────

#[Table('users')]
class TestUser extends Model
{
    #[PrimaryKey]
    public ?int $id = null;
    public string $name = '';
    public int $owner_id = 0;

    /** @var VirtualDatabase|null The VDB to use for queries */
    public static ?VirtualDatabase $vdb = null;

    /** @var int|null Simulated current user ID for scoping */
    public static ?int $currentUserId = null;

    /** @var bool Whether the current user is an admin */
    public static bool $isAdmin = false;

    protected static function provideDatabase(): \mini\Database\DatabaseInterface
    {
        return static::$vdb ?? throw new \RuntimeException('TestUser::$vdb not set');
    }

    public static function query(): \mini\Database\Query
    {
        if (static::$isAdmin) {
            return static::queryUnsafe();
        }
        if (static::$currentUserId === null) {
            throw new \RuntimeException('Not authenticated');
        }
        return static::queryUnsafe()->eq('owner_id', static::$currentUserId);
    }

    public static function updatable(): \mini\Database\Query
    {
        // Same as query() — only own rows are updatable
        return static::query();
    }

    public static function deletable(): \mini\Database\Query
    {
        // Same as query() — only own rows are deletable
        return static::query();
    }

    public function provideCanUpdate(): ?bool
    {
        if (static::$isAdmin) return true;
        return $this->owner_id === static::$currentUserId;
    }

    public function provideCanDelete(): ?bool
    {
        if (static::$isAdmin) return true;
        return $this->owner_id === static::$currentUserId;
    }

    public static function provideCanCreate(): ?bool
    {
        return static::$currentUserId !== null || static::$isAdmin;
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Bootstrap Mini for Authorization
// ─────────────────────────────────────────────────────────────────────────

// Trigger Ready phase so Model auth handler fires
if (Mini::$mini->phase->getCurrentState() !== Phase::Ready) {
    Mini::$mini->phase->trigger(Phase::Ready);
}

// ─────────────────────────────────────────────────────────────────────────
// Test suite
// ─────────────────────────────────────────────────────────────────────────

$test = new class extends Test {

    private function createVdb(): VirtualDatabase
    {
        $table = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('name', ColumnType::Text),
            new ColumnDef('owner_id', ColumnType::Int),
        );

        $table->insert(['id' => 1, 'name' => 'Alice Post', 'owner_id' => 100]);
        $table->insert(['id' => 2, 'name' => 'Bob Post', 'owner_id' => 200]);
        $table->insert(['id' => 3, 'name' => 'Charlie Post', 'owner_id' => 100]);
        $table->insert(['id' => 4, 'name' => 'Dave Post', 'owner_id' => 300]);

        $vdb = new VirtualDatabase();
        $vdb->registerTable('users', $table);

        // Point the model at this VDB
        TestUser::$vdb = $vdb;

        return $vdb;
    }

    private function setUpVdb(int $userId, bool $isAdmin = false): VirtualDatabase
    {
        TestUser::$currentUserId = $userId;
        TestUser::$isAdmin = $isAdmin;
        $vdb = $this->createVdb();
        $vdb->registerModel('users', TestUser::class);
        return $vdb;
    }

    private function cleanUp(): void
    {
        TestUser::$vdb = null;
        TestUser::$currentUserId = null;
        TestUser::$isAdmin = false;
    }

    // =====================================================================
    // Layer 1: Readable scope — SELECT filtering
    // =====================================================================

    public function testSelectAsOwner_OnlySeeOwnRows(): void
    {
        $vdb = $this->setUpVdb(100);

        $rows = iterator_to_array($vdb->query('SELECT * FROM users'));
        $this->assertCount(2, $rows);
        $this->assertSame('Alice Post', $rows[0]->name);
        $this->assertSame('Charlie Post', $rows[1]->name);

        $this->cleanUp();
    }

    public function testSelectAsAdmin_SeeAllRows(): void
    {
        $vdb = $this->setUpVdb(100, isAdmin: true);

        $rows = iterator_to_array($vdb->query('SELECT * FROM users'));
        $this->assertCount(4, $rows);

        $this->cleanUp();
    }

    public function testSelectWithWhereAndScope_Intersects(): void
    {
        $vdb = $this->setUpVdb(100);

        // Owner 100 has rows 1 (Alice) and 3 (Charlie). Filtering by name = 'Alice Post'
        $rows = iterator_to_array($vdb->query(
            "SELECT * FROM users WHERE name = ?", ['Alice Post']
        ));
        $this->assertCount(1, $rows);
        $this->assertSame('Alice Post', $rows[0]->name);

        $this->cleanUp();
    }

    public function testSelectOutOfScope_ReturnsNothing(): void
    {
        $vdb = $this->setUpVdb(100);

        // Bob's post (owner_id=200) is not visible to user 100
        $rows = iterator_to_array($vdb->query(
            "SELECT * FROM users WHERE id = ?", [2]
        ));
        $this->assertCount(0, $rows);

        $this->cleanUp();
    }

    // =====================================================================
    // Layer 1: Updatable scope — UPDATE scoping
    // =====================================================================

    public function testUpdateOwnRow_Succeeds(): void
    {
        $vdb = $this->setUpVdb(100);

        $affected = $vdb->exec("UPDATE users SET name = 'Updated' WHERE id = ?", [1]);
        $this->assertSame(1, $affected);

        // Verify the update
        $rows = iterator_to_array($vdb->query("SELECT * FROM users WHERE id = ?", [1]));
        $this->assertCount(1, $rows);
        $this->assertSame('Updated', $rows[0]->name);

        $this->cleanUp();
    }

    public function testUpdateOtherUserRow_ScopeBlocksIt(): void
    {
        $vdb = $this->setUpVdb(100);

        // User 100 tries to update Bob's row (owner_id=200)
        // Scope merging makes UPDATE WHERE = (id=2) AND (owner_id=100) → 0 rows
        $affected = $vdb->exec("UPDATE users SET name = 'Hacked' WHERE id = ?", [2]);
        $this->assertSame(0, $affected);

        $this->cleanUp();
    }

    public function testUpdateAsAdmin_AllRows(): void
    {
        $vdb = $this->setUpVdb(100, isAdmin: true);

        $affected = $vdb->exec("UPDATE users SET name = 'Admin Updated' WHERE id = ?", [2]);
        $this->assertSame(1, $affected);

        $this->cleanUp();
    }

    // =====================================================================
    // Layer 1: Deletable scope — DELETE scoping
    // =====================================================================

    public function testDeleteOwnRow_Succeeds(): void
    {
        $vdb = $this->setUpVdb(100);

        $affected = $vdb->exec("DELETE FROM users WHERE id = ?", [1]);
        $this->assertSame(1, $affected);

        $this->cleanUp();
    }

    public function testDeleteOtherUserRow_ScopeBlocksIt(): void
    {
        $vdb = $this->setUpVdb(100);

        // User 100 tries to delete Bob's row (owner_id=200)
        $affected = $vdb->exec("DELETE FROM users WHERE id = ?", [2]);
        $this->assertSame(0, $affected);

        $this->cleanUp();
    }

    // =====================================================================
    // Layer 2: Insert gate (ModelScopedTable)
    // =====================================================================

    public function testInsertWithAuth_Succeeds(): void
    {
        $vdb = $this->setUpVdb(100);

        $vdb->exec(
            "INSERT INTO users (id, name, owner_id) VALUES (?, ?, ?)",
            [5, 'New Post', 100]
        );

        // Verify via admin view
        TestUser::$isAdmin = true;
        $rows = iterator_to_array($vdb->query("SELECT * FROM users WHERE id = ?", [5]));
        $this->assertCount(1, $rows);
        $this->assertSame('New Post', $rows[0]->name);

        $this->cleanUp();
    }

    public function testInsertWithoutAuth_ThrowsAccessDenied(): void
    {
        $vdb = $this->createVdb();
        TestUser::$currentUserId = null;
        TestUser::$isAdmin = false;
        $vdb->registerModel('users', TestUser::class);

        $this->assertThrows(function () use ($vdb) {
            $vdb->exec(
                "INSERT INTO users (id, name, owner_id) VALUES (?, ?, ?)",
                [5, 'Unauthorized', 999]
            );
        }, AccessDeniedException::class);

        $this->cleanUp();
    }

    public function testInsertWithExplicitAllowInsertFalse_ThrowsAccessDenied(): void
    {
        $vdb = $this->createVdb();
        TestUser::$currentUserId = 100;
        TestUser::$isAdmin = false;
        $vdb->registerModel('users', TestUser::class, allowInsert: false);

        $this->assertThrows(function () use ($vdb) {
            $vdb->exec(
                "INSERT INTO users (id, name, owner_id) VALUES (?, ?, ?)",
                [5, 'Blocked', 100]
            );
        }, AccessDeniedException::class);

        $this->cleanUp();
    }

    // =====================================================================
    // Layer 2: Per-entity can() checks on UPDATE/DELETE
    // =====================================================================

    public function testUpdateWithCanDenied_ThrowsAccessDenied(): void
    {
        // Use explicit admin-level scope (no WHERE) so Layer 1 allows all rows.
        // provideCanUpdate still checks owner_id → denies for non-owned rows.
        $vdb = $this->createVdb();
        TestUser::$currentUserId = 100;
        TestUser::$isAdmin = true;

        // Capture an unfiltered scope query while admin
        $allRows = TestUser::query();

        TestUser::$isAdmin = false;

        // Register with explicit wide scope, so Layer 1 doesn't filter
        $vdb->registerModel('users', TestUser::class,
            readable: $allRows, updatable: $allRows, deletable: $allRows,
        );

        // User 100 tries to update Bob's row (owner_id=200)
        // Scope passes (all rows visible), but can(Update, $entity) denies
        $this->assertThrows(function () use ($vdb) {
            $vdb->exec("UPDATE users SET name = 'Hacked' WHERE id = ?", [2]);
        }, AccessDeniedException::class);

        $this->cleanUp();
    }

    public function testDeleteWithCanDenied_ThrowsAccessDenied(): void
    {
        $vdb = $this->createVdb();
        TestUser::$currentUserId = 100;
        TestUser::$isAdmin = true;

        $allRows = TestUser::query();

        TestUser::$isAdmin = false;

        $vdb->registerModel('users', TestUser::class,
            readable: $allRows, updatable: $allRows, deletable: $allRows,
        );

        $this->assertThrows(function () use ($vdb) {
            $vdb->exec("DELETE FROM users WHERE id = ?", [2]);
        }, AccessDeniedException::class);

        $this->cleanUp();
    }

    // =====================================================================
    // Registration edge cases
    // =====================================================================

    public function testRegisterModelOnMissingTable_Throws(): void
    {
        $vdb = new VirtualDatabase();

        $this->assertThrows(function () use ($vdb) {
            $vdb->registerModel('nonexistent', TestUser::class);
        }, \RuntimeException::class);
    }

    public function testRegisterModelSignature(): void
    {
        $vdb = $this->createVdb();
        TestUser::$currentUserId = 100;
        TestUser::$isAdmin = false;

        // Fluent API
        $result = $vdb->registerModel('users', TestUser::class);
        $this->assertSame($vdb, $result);

        $this->cleanUp();
    }
};

exit($test->run());
