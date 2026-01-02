<?php
/**
 * Test InMemoryTable (SQLite-backed oracle) implementation
 */

require __DIR__ . '/../../ensure-autoloader.php';
require_once __DIR__ . '/_TableImplementationTest.php';

use mini\testing\TableImplementationTest;
use mini\Table\InMemoryTable;
use mini\Table\ColumnDef;
use mini\Table\Types\ColumnType;
use mini\Table\Types\IndexType;
use mini\Table\Contracts\TableInterface;

$test = new class extends TableImplementationTest {

    protected function createTable(): TableInterface
    {
        $table = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('name', ColumnType::Text, IndexType::Index),
            new ColumnDef('age', ColumnType::Int),
            new ColumnDef('dept', ColumnType::Text),
        );

        // Insert test data
        foreach ($this->getTestData() as $row) {
            $table->insert((array) $row);
        }

        return $table;
    }

    // =========================================================================
    // InMemoryTable-specific tests
    // =========================================================================

    public function testInsertReturnsRowId(): void
    {
        $table = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('name', ColumnType::Text),
        );

        $id1 = $table->insert(['id' => 100, 'name' => 'Test']);
        $this->assertSame(100, $id1);

        // Auto-increment if id not provided (SQLite behavior)
        $table2 = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('name', ColumnType::Text),
        );
        $id2 = $table2->insert(['name' => 'Test']);
        $this->assertSame(1, $id2);
    }

    public function testUpdateAffectsFilteredRows(): void
    {
        $table = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('status', ColumnType::Text),
        );

        $table->insert(['id' => 1, 'status' => 'active']);
        $table->insert(['id' => 2, 'status' => 'active']);
        $table->insert(['id' => 3, 'status' => 'inactive']);

        // Update only active rows
        $affected = $table->update($table->eq('status', 'active'), ['status' => 'archived']);
        $this->assertSame(2, $affected);

        // Verify
        $statuses = [];
        foreach ($table->order('id') as $row) {
            $statuses[] = $row->status;
        }
        $this->assertSame(['archived', 'archived', 'inactive'], $statuses);
    }

    public function testDeleteRemovesFilteredRows(): void
    {
        $table = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('status', ColumnType::Text),
        );

        $table->insert(['id' => 1, 'status' => 'active']);
        $table->insert(['id' => 2, 'status' => 'active']);
        $table->insert(['id' => 3, 'status' => 'inactive']);

        // Delete inactive rows
        $deleted = $table->delete($table->eq('status', 'inactive'));
        $this->assertSame(1, $deleted);

        // Verify
        $this->assertSame(2, $table->count());
    }

    public function testMultipleTablesAreIndependent(): void
    {
        $table1 = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
        );
        $table2 = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
        );

        $table1->insert(['id' => 1]);
        $table2->insert(['id' => 100]);

        $this->assertSame(1, $table1->count());
        $this->assertSame(1, $table2->count());

        $ids1 = [];
        foreach ($table1 as $row) {
            $ids1[] = $row->id;
        }
        $this->assertSame([1], $ids1);
    }

    public function testClonedTableSharesData(): void
    {
        $table = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('name', ColumnType::Text),
        );

        $table->insert(['id' => 1, 'name' => 'Alice']);
        $table->insert(['id' => 2, 'name' => 'Bob']);

        // Filter creates a clone
        $filtered = $table->eq('name', 'Alice');

        // Insert into original
        $table->insert(['id' => 3, 'name' => 'Carol']);

        // Clone sees the new data (shares DB)
        $this->assertSame(3, $table->count());

        // But filtered still only matches Alice
        $this->assertSame(1, $filtered->count());
    }

    // =========================================================================
    // Decimal column tests
    // =========================================================================

    public function testDecimalColumnWithScale(): void
    {
        $table = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('price', ColumnType::Decimal, typeParameters: ['scale' => 2]),
        );

        // Insert with different value types
        $table->insert(['id' => 1, 'price' => '9.99']);
        $table->insert(['id' => 2, 'price' => 24.99]);
        $table->insert(['id' => 3, 'price' => 100]);

        $rows = array_values(iterator_to_array($table->order('id ASC')));

        // All should be stored with 2 decimal places
        $this->assertSame('9.99', $rows[0]->price);
        $this->assertSame('24.99', $rows[1]->price);
        $this->assertSame('100.00', $rows[2]->price);
    }

    public function testDecimalColumnScaleRounding(): void
    {
        $table = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('amount', ColumnType::Decimal, typeParameters: ['scale' => 2]),
        );

        // Values with more precision get rounded
        $table->insert(['id' => 1, 'amount' => '9.999']);   // rounds to 10.00
        $table->insert(['id' => 2, 'amount' => '5.554']);   // rounds to 5.55
        $table->insert(['id' => 3, 'amount' => '5.555']);   // rounds to 5.56 (half up)

        $rows = array_values(iterator_to_array($table->order('id ASC')));

        $this->assertSame('10.00', $rows[0]->amount);
        $this->assertSame('5.55', $rows[1]->amount);
        $this->assertSame('5.56', $rows[2]->amount);
    }

    public function testDecimalColumnWithDecimalObject(): void
    {
        $table = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('value', ColumnType::Decimal, typeParameters: ['scale' => 4]),
        );

        // Insert using Decimal object
        $decimal = \mini\Util\Math\Decimal::of('123.456789', 6);
        $table->insert(['id' => 1, 'value' => $decimal]);

        $rows = array_values(iterator_to_array($table));
        // Rescaled from 6 to 4 decimal places
        $this->assertSame('123.4568', $rows[0]->value);
    }

    public function testDecimalColumnOrdering(): void
    {
        $table = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('amount', ColumnType::Decimal, typeParameters: ['scale' => 2]),
        );

        // Different integer widths - should sort numerically, not lexicographically
        $table->insert(['id' => 1, 'amount' => '9.99']);
        $table->insert(['id' => 2, 'amount' => '100.00']);
        $table->insert(['id' => 3, 'amount' => '10.50']);

        $rows = array_values(iterator_to_array($table->order('amount ASC')));

        // Custom DECIMAL collation ensures numeric ordering
        $this->assertSame('9.99', $rows[0]->amount);
        $this->assertSame('10.50', $rows[1]->amount);
        $this->assertSame('100.00', $rows[2]->amount);
    }

    public function testDecimalColumnOrderingDescending(): void
    {
        $table = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('price', ColumnType::Decimal, typeParameters: ['scale' => 2]),
        );

        $table->insert(['id' => 1, 'price' => '5.00']);
        $table->insert(['id' => 2, 'price' => '50.00']);
        $table->insert(['id' => 3, 'price' => '500.00']);

        $rows = array_values(iterator_to_array($table->order('price DESC')));

        $this->assertSame('500.00', $rows[0]->price);
        $this->assertSame('50.00', $rows[1]->price);
        $this->assertSame('5.00', $rows[2]->price);
    }

    public function testDecimalColumnComparison(): void
    {
        $table = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('price', ColumnType::Decimal, typeParameters: ['scale' => 2]),
        );

        $table->insert(['id' => 1, 'price' => '9.99']);
        $table->insert(['id' => 2, 'price' => '24.99']);
        $table->insert(['id' => 3, 'price' => '14.99']);

        // Filter on decimal column with DECIMAL collation
        // Only 24.99 is > 15.00 numerically
        $expensive = $table->gt('price', '15.00');
        $this->assertSame(1, $expensive->count());

        $rows = array_values(iterator_to_array($expensive));
        $this->assertSame('24.99', $rows[0]->price);
    }

    public function testDecimalColumnComparisonWithDifferentWidths(): void
    {
        $table = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('amount', ColumnType::Decimal, typeParameters: ['scale' => 2]),
        );

        $table->insert(['id' => 1, 'amount' => '5.00']);
        $table->insert(['id' => 2, 'amount' => '50.00']);
        $table->insert(['id' => 3, 'amount' => '500.00']);

        // Numeric comparison: 5 < 10 < 50 < 500
        $over10 = $table->gt('amount', '10.00');
        $this->assertSame(2, $over10->count());

        $under100 = $table->lt('amount', '100.00');
        $this->assertSame(2, $under100->count());
    }

    public function testDecimalColumnUpdate(): void
    {
        $table = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('balance', ColumnType::Decimal, typeParameters: ['scale' => 2]),
        );

        $table->insert(['id' => 1, 'balance' => '100.00']);

        // Update with float value
        $table->update($table->eq('id', 1), ['balance' => 150.50]);

        $rows = array_values(iterator_to_array($table));
        $this->assertSame('150.50', $rows[0]->balance);
    }

    public function testDecimalColumnWithZeroScale(): void
    {
        $table = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('count', ColumnType::Decimal, typeParameters: ['scale' => 0]),
        );

        $table->insert(['id' => 1, 'count' => '123.456']);

        $rows = array_values(iterator_to_array($table));
        $this->assertSame('123', $rows[0]->count);
    }

    public function testDecimalColumnWithLargeNumbers(): void
    {
        $table = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('amount', ColumnType::Decimal, typeParameters: ['scale' => 2]),
        );

        // Large numbers within float precision (~15 significant digits)
        $table->insert(['id' => 1, 'amount' => '12345678901234.56']);
        $table->insert(['id' => 2, 'amount' => '99999999999999.99']);

        $rows = array_values(iterator_to_array($table->order('id ASC')));

        $this->assertSame('12345678901234.56', $rows[0]->amount);
        $this->assertSame('99999999999999.99', $rows[1]->amount);
    }

    public function testDecimalPrecisionLimitNote(): void
    {
        // NOTE: SQLite stores DECIMAL as REAL (64-bit float), which provides
        // ~15-17 significant digits of precision. For values beyond this,
        // use a different storage backend or the Decimal class directly.
        $this->assertTrue(true);
    }
};

exit($test->run());
