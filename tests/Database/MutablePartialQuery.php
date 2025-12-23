<?php
/**
 * Test MutablePartialQuery implementation
 *
 * Tests: INSERT/UPDATE/DELETE with validators, predicate matching, scope enforcement
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use mini\Test;
use mini\Database\MutablePartialQuery;
use mini\Database\PartialQuery;
use mini\Table\Contracts\MutableTableInterface;

$test = new class extends Test {

    protected function setUp(): void
    {
        \mini\bootstrap();
    }

    private function createTable(): void
    {
        \mini\db()->exec('DROP TABLE IF EXISTS mutable_test');
        \mini\db()->exec('CREATE TABLE mutable_test (
            id INTEGER PRIMARY KEY,
            org_id INTEGER NOT NULL,
            name TEXT,
            active INTEGER DEFAULT 1
        )');
    }

    private function dropTable(): void
    {
        \mini\db()->exec('DROP TABLE IF EXISTS mutable_test');
    }

    // -------------------------------------------------------------------------
    // Basic construction and interface
    // -------------------------------------------------------------------------

    public function testImplementsMutableTableInterface(): void
    {
        $this->createTable();
        $query = \mini\db()->query('SELECT * FROM mutable_test');
        $mutable = new MutablePartialQuery($query, \mini\db());

        $this->assertInstanceOf(MutableTableInterface::class, $mutable);
        $this->dropTable();
    }

    public function testRejectMultiTableQuery(): void
    {
        $this->createTable();
        // UNION creates multi-table query
        $query = \mini\db()->query('SELECT * FROM mutable_test UNION SELECT * FROM mutable_test');

        $threw = false;
        try {
            new MutablePartialQuery($query, \mini\db());
        } catch (\InvalidArgumentException $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'Expected InvalidArgumentException for multi-table query');
        $this->dropTable();
    }

    // -------------------------------------------------------------------------
    // INSERT operations
    // -------------------------------------------------------------------------

    public function testInsertBasic(): void
    {
        $this->createTable();
        $query = \mini\db()->query('SELECT * FROM mutable_test');
        $mutable = new MutablePartialQuery($query, \mini\db());

        $id = $mutable->insert(['id' => 1, 'org_id' => 100, 'name' => 'Test']);

        $this->assertEquals(1, $id);

        // Verify the row was inserted
        $row = \mini\db()->queryOne('SELECT * FROM mutable_test WHERE id = 1');
        $this->assertNotNull($row);
        $this->assertEquals('Test', $row->name);
        $this->assertEquals(100, (int)$row->org_id);
        $this->dropTable();
    }

    public function testInsertWithPredicateValidation(): void
    {
        $this->createTable();
        // Scope: only org_id = 100
        $query = \mini\db()->query('SELECT * FROM mutable_test')->eq('org_id', 100);
        $mutable = new MutablePartialQuery($query, \mini\db());

        // This should succeed - matches predicate
        $id = $mutable->insert(['id' => 1, 'org_id' => 100, 'name' => 'Valid']);
        $this->assertEquals(1, $id);

        // This should fail - violates predicate
        $threw = false;
        try {
            $mutable->insert(['id' => 2, 'org_id' => 200, 'name' => 'Invalid']);
        } catch (\RuntimeException $e) {
            $threw = true;
            $this->assertContains('Row violates query constraints', $e->getMessage());
        }
        $this->assertTrue($threw, 'Expected RuntimeException for predicate violation');
        $this->dropTable();
    }

    public function testInsertWithBaseSqlWhereValidation(): void
    {
        $this->createTable();

        // Scope defined in BASE SQL WHERE, not predicate
        $query = \mini\db()->query('SELECT * FROM mutable_test WHERE org_id = ?', [100]);
        $mutable = new MutablePartialQuery($query, \mini\db());

        // This should succeed - matches base SQL WHERE
        $id = $mutable->insert(['id' => 1, 'org_id' => 100, 'name' => 'Valid']);
        $this->assertEquals(1, $id);

        // This should fail - violates base SQL WHERE
        $threw = false;
        try {
            $mutable->insert(['id' => 2, 'org_id' => 200, 'name' => 'Invalid']);
        } catch (\RuntimeException $e) {
            $threw = true;
            $this->assertContains('Row violates query constraints', $e->getMessage());
        }
        $this->assertTrue($threw, 'Expected RuntimeException for base SQL WHERE violation');
        $this->dropTable();
    }

    public function testInsertWithCombinedBaseSqlAndPredicateValidation(): void
    {
        $this->createTable();

        // Both base SQL WHERE and chained predicate
        $query = \mini\db()->query('SELECT * FROM mutable_test WHERE org_id = ?', [100])
            ->eq('active', 1);
        $mutable = new MutablePartialQuery($query, \mini\db());

        // This should succeed - matches both
        $id = $mutable->insert(['id' => 1, 'org_id' => 100, 'active' => 1, 'name' => 'Valid']);
        $this->assertEquals(1, $id);

        // Fails base SQL WHERE (wrong org)
        $threw = false;
        try {
            $mutable->insert(['id' => 2, 'org_id' => 200, 'active' => 1, 'name' => 'Invalid']);
        } catch (\RuntimeException $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'Expected exception for org_id violation');

        // Fails predicate (wrong active)
        $threw = false;
        try {
            $mutable->insert(['id' => 3, 'org_id' => 100, 'active' => 0, 'name' => 'Invalid']);
        } catch (\RuntimeException $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'Expected exception for active violation');

        $this->dropTable();
    }

    public function testInsertWithCustomValidator(): void
    {
        $this->createTable();
        $query = \mini\db()->query('SELECT * FROM mutable_test');
        $mutable = (new MutablePartialQuery($query, \mini\db()))
            ->withInsertValidator(function (array $row) {
                if (empty($row['name'])) {
                    throw new \RuntimeException('Name is required');
                }
            });

        // This should fail - custom validator
        $threw = false;
        try {
            $mutable->insert(['id' => 1, 'org_id' => 100, 'name' => '']);
        } catch (\RuntimeException $e) {
            $threw = true;
            $this->assertContains('Name is required', $e->getMessage());
        }
        $this->assertTrue($threw, 'Expected RuntimeException for validation failure');
        $this->dropTable();
    }

    public function testInsertValidatorImmutability(): void
    {
        $this->createTable();
        $query = \mini\db()->query('SELECT * FROM mutable_test');
        $m1 = new MutablePartialQuery($query, \mini\db());
        $m2 = $m1->withInsertValidator(fn($row) => null);

        $this->assertFalse($m1 === $m2);
        $this->dropTable();
    }

    // -------------------------------------------------------------------------
    // UPDATE operations
    // -------------------------------------------------------------------------

    public function testUpdateBasic(): void
    {
        $this->createTable();
        \mini\db()->exec("INSERT INTO mutable_test (id, org_id, name) VALUES (1, 100, 'Original')");

        $query = \mini\db()->query('SELECT * FROM mutable_test');
        $mutable = new MutablePartialQuery($query, \mini\db());

        // Update via self-derived query
        $count = $mutable->update($mutable->eq('id', 1), ['name' => 'Updated']);

        $this->assertEquals(1, $count);

        $row = \mini\db()->queryOne('SELECT * FROM mutable_test WHERE id = 1');
        $this->assertEquals('Updated', $row->name);
        $this->dropTable();
    }

    public function testUpdateRespectsBaseScope(): void
    {
        $this->createTable();
        \mini\db()->exec("INSERT INTO mutable_test (id, org_id, name) VALUES (1, 100, 'Org100')");
        \mini\db()->exec("INSERT INTO mutable_test (id, org_id, name) VALUES (2, 200, 'Org200')");

        // Scope: only org_id = 100
        $query = \mini\db()->query('SELECT * FROM mutable_test')->eq('org_id', 100);
        $mutable = new MutablePartialQuery($query, \mini\db());

        // Update all visible rows (should only affect org_id = 100)
        $count = $mutable->update($mutable, ['name' => 'Updated']);

        $this->assertEquals(1, $count);

        // Org 100 should be updated
        $row1 = \mini\db()->queryOne('SELECT * FROM mutable_test WHERE id = 1');
        $this->assertEquals('Updated', $row1->name);

        // Org 200 should be unchanged
        $row2 = \mini\db()->queryOne('SELECT * FROM mutable_test WHERE id = 2');
        $this->assertEquals('Org200', $row2->name);
        $this->dropTable();
    }

    public function testUpdateWithValidator(): void
    {
        $this->createTable();
        \mini\db()->exec("INSERT INTO mutable_test (id, org_id, name) VALUES (1, 100, 'Test')");

        $query = \mini\db()->query('SELECT * FROM mutable_test');
        $validatorCalled = false;
        $mutable = (new MutablePartialQuery($query, \mini\db()))
            ->withUpdateValidator(function (array $pk, object $after) use (&$validatorCalled) {
                $validatorCalled = true;
                $this->assertEquals(['id' => 1], $pk);
                $this->assertEquals('NewName', $after->name);
            });

        $mutable->update($mutable->eq('id', 1), ['name' => 'NewName']);

        $this->assertTrue($validatorCalled);
        $this->dropTable();
    }

    // -------------------------------------------------------------------------
    // DELETE operations
    // -------------------------------------------------------------------------

    public function testDeleteBasic(): void
    {
        $this->createTable();
        \mini\db()->exec("INSERT INTO mutable_test (id, org_id, name) VALUES (1, 100, 'Test')");

        $query = \mini\db()->query('SELECT * FROM mutable_test');
        $mutable = new MutablePartialQuery($query, \mini\db());

        $count = $mutable->delete($mutable->eq('id', 1));

        $this->assertEquals(1, $count);

        $row = \mini\db()->queryOne('SELECT * FROM mutable_test WHERE id = 1');
        $this->assertNull($row);
        $this->dropTable();
    }

    public function testDeleteRespectsBaseScope(): void
    {
        $this->createTable();
        \mini\db()->exec("INSERT INTO mutable_test (id, org_id, name) VALUES (1, 100, 'Org100')");
        \mini\db()->exec("INSERT INTO mutable_test (id, org_id, name) VALUES (2, 200, 'Org200')");

        // Scope: only org_id = 100
        $query = \mini\db()->query('SELECT * FROM mutable_test')->eq('org_id', 100);
        $mutable = new MutablePartialQuery($query, \mini\db());

        // Try to delete all visible rows
        $count = $mutable->delete($mutable);

        $this->assertEquals(1, $count);

        // Org 200 should still exist
        $row = \mini\db()->queryOne('SELECT * FROM mutable_test WHERE id = 2');
        $this->assertNotNull($row);
        $this->dropTable();
    }

    public function testDeleteWithValidator(): void
    {
        $this->createTable();
        \mini\db()->exec("INSERT INTO mutable_test (id, org_id, name) VALUES (1, 100, 'Test')");

        $query = \mini\db()->query('SELECT * FROM mutable_test');
        $validatorCalled = false;
        $mutable = (new MutablePartialQuery($query, \mini\db()))
            ->withDeleteValidator(function (array $pk) use (&$validatorCalled) {
                $validatorCalled = true;
                $this->assertEquals(['id' => 1], $pk);
            });

        $mutable->delete($mutable->eq('id', 1));

        $this->assertTrue($validatorCalled);
        $this->dropTable();
    }

    // -------------------------------------------------------------------------
    // Filter delegation
    // -------------------------------------------------------------------------

    public function testEqReturnsMutablePartialQuery(): void
    {
        $this->createTable();
        $query = \mini\db()->query('SELECT * FROM mutable_test');
        $mutable = new MutablePartialQuery($query, \mini\db());

        $filtered = $mutable->eq('org_id', 100);

        $this->assertInstanceOf(MutablePartialQuery::class, $filtered);
        $this->assertFalse($mutable === $filtered); // Immutable
        $this->dropTable();
    }

    public function testFilterChaining(): void
    {
        $this->createTable();
        \mini\db()->exec("INSERT INTO mutable_test (id, org_id, name, active) VALUES (1, 100, 'Alice', 1)");
        \mini\db()->exec("INSERT INTO mutable_test (id, org_id, name, active) VALUES (2, 100, 'Bob', 0)");
        \mini\db()->exec("INSERT INTO mutable_test (id, org_id, name, active) VALUES (3, 200, 'Charlie', 1)");

        $query = \mini\db()->query('SELECT * FROM mutable_test');
        $mutable = new MutablePartialQuery($query, \mini\db());

        // Chain multiple filters
        $count = $mutable->eq('org_id', 100)->eq('active', 1)->count();

        $this->assertEquals(1, $count);
        $this->dropTable();
    }

    // -------------------------------------------------------------------------
    // Predicate access
    // -------------------------------------------------------------------------

    public function testGetPredicate(): void
    {
        $this->createTable();
        $query = \mini\db()->query('SELECT * FROM mutable_test')->eq('org_id', 100);
        $mutable = new MutablePartialQuery($query, \mini\db());

        $predicate = $mutable->getPredicate();

        $this->assertInstanceOf(\mini\Table\Predicate::class, $predicate);
        $this->assertTrue($predicate->test((object)['org_id' => 100]));
        $this->assertFalse($predicate->test((object)['org_id' => 200]));
        $this->dropTable();
    }

    public function testMatches(): void
    {
        $this->createTable();
        $query = \mini\db()->query('SELECT * FROM mutable_test')->eq('org_id', 100);
        $mutable = new MutablePartialQuery($query, \mini\db());

        $this->assertTrue($mutable->matches((object)['id' => 1, 'org_id' => 100]));
        $this->assertFalse($mutable->matches((object)['id' => 2, 'org_id' => 200]));
        $this->dropTable();
    }

    // -------------------------------------------------------------------------
    // Read operations delegation
    // -------------------------------------------------------------------------

    public function testCount(): void
    {
        $this->createTable();
        \mini\db()->exec("INSERT INTO mutable_test (id, org_id, name) VALUES (1, 100, 'A')");
        \mini\db()->exec("INSERT INTO mutable_test (id, org_id, name) VALUES (2, 100, 'B')");
        \mini\db()->exec("INSERT INTO mutable_test (id, org_id, name) VALUES (3, 200, 'C')");

        $query = \mini\db()->query('SELECT * FROM mutable_test')->eq('org_id', 100);
        $mutable = new MutablePartialQuery($query, \mini\db());

        $this->assertEquals(2, $mutable->count());
        $this->dropTable();
    }

    public function testIteration(): void
    {
        $this->createTable();
        \mini\db()->exec("INSERT INTO mutable_test (id, org_id, name) VALUES (1, 100, 'A')");
        \mini\db()->exec("INSERT INTO mutable_test (id, org_id, name) VALUES (2, 100, 'B')");

        $query = \mini\db()->query('SELECT * FROM mutable_test')->eq('org_id', 100);
        $mutable = new MutablePartialQuery($query, \mini\db());

        $rows = [];
        foreach ($mutable->order('id') as $row) {
            $rows[] = $row->name;
        }

        $this->assertEquals(['A', 'B'], $rows);
        $this->dropTable();
    }
};

exit($test->run());
