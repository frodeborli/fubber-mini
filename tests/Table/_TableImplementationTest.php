<?php
/**
 * Abstract base test for TableInterface implementations
 *
 * Extend this class and implement createTable() to test any table backend.
 * The test data is a standard set of 5 users with id, name, age, and dept columns.
 */

namespace mini\testing;

use mini\Test;
use mini\Table\TableInterface;
use mini\Table\Set;

abstract class TableImplementationTest extends Test
{
    /**
     * Create a table with the standard test dataset
     *
     * Must return a table with these rows (keyed by id):
     *   1 => {id: 1, name: 'Alice',   age: 9,  dept: 'Engineering'}
     *   2 => {id: 2, name: 'Bob',     age: 25, dept: 'Sales'}
     *   3 => {id: 3, name: 'Åsa',     age: 35, dept: 'Engineering'}
     *   4 => {id: 4, name: 'Örjan',   age: 28, dept: 'Sales'}
     *   5 => {id: 5, name: 'Øystein', age: 22, dept: 'Marketing'}
     *
     * @return TableInterface
     */
    abstract protected function createTable(): TableInterface;

    /**
     * Standard test data for reference
     *
     * Includes international characters (Å, Ä, Ö, Ø, Æ) that sort differently
     * in Swedish vs Norwegian locale:
     * - Swedish: A-Z, Å, Ä, Ö
     * - Norwegian: A-Z, Æ, Ø, Å
     *
     * Alice's age is 9 (single digit) to verify numeric sorting vs lexical.
     */
    protected function getTestData(): array
    {
        return [
            1 => (object)['id' => 1, 'name' => 'Alice',   'age' => 9,  'dept' => 'Engineering'],
            2 => (object)['id' => 2, 'name' => 'Bob',     'age' => 25, 'dept' => 'Sales'],
            3 => (object)['id' => 3, 'name' => 'Åsa',     'age' => 35, 'dept' => 'Engineering'],
            4 => (object)['id' => 4, 'name' => 'Örjan',   'age' => 28, 'dept' => 'Sales'],
            5 => (object)['id' => 5, 'name' => 'Øystein', 'age' => 22, 'dept' => 'Marketing'],
        ];
    }

    // =========================================================================
    // Basic iteration and structure
    // =========================================================================

    public function testIteratesAllRows(): void
    {
        $table = $this->createTable();

        $ids = [];
        foreach ($table as $key => $row) {
            $ids[$key] = $row->id;
        }

        $this->assertSame([1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5], $ids);
    }

    public function testCountReturnsRowCount(): void
    {
        $table = $this->createTable();
        $this->assertSame(5, $table->count());
    }

    public function testGetColumnsReturnsColumnDefinitions(): void
    {
        $table = $this->createTable();
        $cols = array_keys($table->getColumns());

        $this->assertTrue(in_array('id', $cols));
        $this->assertTrue(in_array('name', $cols));
        $this->assertTrue(in_array('age', $cols));
        $this->assertTrue(in_array('dept', $cols));
    }

    public function testCanIterateMultipleTimes(): void
    {
        $table = $this->createTable();

        $first = [];
        foreach ($table as $row) {
            $first[] = $row->id;
        }

        $second = [];
        foreach ($table as $row) {
            $second[] = $row->id;
        }

        $this->assertSame($first, $second);
    }

    // =========================================================================
    // Limit and offset
    // =========================================================================

    public function testLimitRestrictsRowCount(): void
    {
        $table = $this->createTable()->limit(3);

        $this->assertSame(3, $table->count());
    }

    public function testOffsetSkipsRows(): void
    {
        $table = $this->createTable()->offset(2);

        $this->assertSame(3, $table->count());
    }

    public function testLimitAndOffsetCombined(): void
    {
        $table = $this->createTable()->offset(1)->limit(2);

        $this->assertSame(2, $table->count());
    }

    public function testGetLimitReturnsCurrentLimit(): void
    {
        $table = $this->createTable();
        $this->assertNull($table->getLimit());

        $limited = $table->limit(3);
        $this->assertSame(3, $limited->getLimit());
    }

    public function testGetOffsetReturnsCurrentOffset(): void
    {
        $table = $this->createTable();
        $this->assertSame(0, $table->getOffset());

        $offset = $table->offset(2);
        $this->assertSame(2, $offset->getOffset());
    }

    // =========================================================================
    // Equality filter (eq)
    // =========================================================================

    public function testEqFiltersToMatchingRows(): void
    {
        $table = $this->createTable()->eq('name', 'Alice');

        $ids = [];
        foreach ($table as $row) {
            $ids[] = $row->id;
        }

        $this->assertSame([1], $ids);
    }

    public function testEqWithNoMatchesReturnsEmpty(): void
    {
        $table = $this->createTable()->eq('name', 'NonExistent');

        $this->assertSame(0, $table->count());
    }

    public function testEqWithMultipleMatches(): void
    {
        $table = $this->createTable()->eq('dept', 'Sales');

        $ids = [];
        foreach ($table as $row) {
            $ids[] = $row->id;
        }

        $this->assertSame([2, 4], $ids);
    }

    public function testEqWithNullValue(): void
    {
        // Our test data has no nulls, so this should return empty
        $table = $this->createTable()->eq('name', null);

        $this->assertSame(0, $table->count());
    }

    // =========================================================================
    // Comparison filters (lt, lte, gt, gte)
    // =========================================================================

    public function testLtFiltersToLessThan(): void
    {
        $table = $this->createTable()->lt('age', 25);

        $ids = [];
        foreach ($table as $row) {
            $ids[] = $row->id;
        }

        $this->assertSame([1, 5], $ids); // Alice is 9, Øystein is 22
    }

    public function testLteFiltersToLessThanOrEqual(): void
    {
        $table = $this->createTable()->lte('age', 25);

        $ids = [];
        foreach ($table as $row) {
            $ids[] = $row->id;
        }

        $this->assertSame([1, 2, 5], $ids); // Alice is 9, Bob is 25, Øystein is 22
    }

    public function testGtFiltersToGreaterThan(): void
    {
        $table = $this->createTable()->gt('age', 25);

        $ids = [];
        foreach ($table as $row) {
            $ids[] = $row->id;
        }

        $this->assertSame([3, 4], $ids); // Åsa is 35, Örjan is 28
    }

    public function testGteFiltersToGreaterThanOrEqual(): void
    {
        $table = $this->createTable()->gte('age', 25);

        $ids = [];
        foreach ($table as $row) {
            $ids[] = $row->id;
        }

        $this->assertSame([2, 3, 4], $ids); // Bob is 25, Åsa is 35, Örjan is 28
    }

    // =========================================================================
    // IN filter
    // =========================================================================

    public function testInFiltersToSetMembers(): void
    {
        $table = $this->createTable()->in('id', new Set('id', [1, 3, 5]));

        $ids = [];
        foreach ($table as $row) {
            $ids[] = $row->id;
        }

        $this->assertSame([1, 3, 5], $ids);
    }

    public function testInWithEmptySetReturnsEmpty(): void
    {
        $table = $this->createTable()->in('id', new Set('id', []));

        $this->assertSame(0, $table->count());
    }

    // =========================================================================
    // LIKE filter
    // =========================================================================

    public function testLikeWithPrefixWildcard(): void
    {
        $table = $this->createTable()->like('name', 'A%');

        $names = [];
        foreach ($table as $row) {
            $names[] = $row->name;
        }

        $this->assertSame(['Alice'], $names);
    }

    public function testLikeWithSuffixWildcard(): void
    {
        $table = $this->createTable()->like('name', '%a');

        $names = [];
        foreach ($table as $row) {
            $names[] = $row->name;
        }

        // Åsa ends with 'a'
        $this->assertSame(['Åsa'], $names);
    }

    public function testLikeWithMiddleWildcard(): void
    {
        $table = $this->createTable()->like('name', '%i%');

        $names = [];
        foreach ($table as $row) {
            $names[] = $row->name;
        }

        // Alice has 'i', Øystein has 'i'
        $this->assertSame(['Alice', 'Øystein'], $names);
    }

    public function testLikeWithSingleCharWildcard(): void
    {
        $table = $this->createTable()->like('name', 'Bo_');

        $names = [];
        foreach ($table as $row) {
            $names[] = $row->name;
        }

        $this->assertSame(['Bob'], $names);
    }

    public function testLikeWithPrefixAndSuffix(): void
    {
        $table = $this->createTable()->like('name', 'A%e');

        $names = [];
        foreach ($table as $row) {
            $names[] = $row->name;
        }

        // Alice starts with A and ends with e
        $this->assertSame(['Alice'], $names);
    }

    public function testLikeIsCaseInsensitive(): void
    {
        $table = $this->createTable()->like('name', 'alice');

        $names = [];
        foreach ($table as $row) {
            $names[] = $row->name;
        }

        // LIKE is case-insensitive by default
        $this->assertSame(['Alice'], $names);
    }

    public function testLikeCaseInsensitiveWithWildcard(): void
    {
        $table = $this->createTable()->like('name', 'ALICE%');

        $names = [];
        foreach ($table as $row) {
            $names[] = $row->name;
        }

        $this->assertSame(['Alice'], $names);
    }

    // =========================================================================
    // Chained filters
    // =========================================================================

    public function testChainedFiltersApplyAll(): void
    {
        $table = $this->createTable()
            ->gt('age', 24)
            ->lt('age', 32);

        $ids = [];
        foreach ($table as $row) {
            $ids[] = $row->id;
        }

        // Bob (25), Örjan (28) - all between 24 and 32
        $this->assertSame([2, 4], $ids);
    }

    public function testFilterThenLimit(): void
    {
        $table = $this->createTable()
            ->eq('dept', 'Engineering')
            ->limit(1);

        $this->assertSame(1, $table->count());
    }

    // =========================================================================
    // Column projection
    // =========================================================================

    public function testColumnsProjectsToSubset(): void
    {
        $table = $this->createTable()->columns('id', 'name');

        foreach ($table as $row) {
            $props = array_keys((array)$row);
            $this->assertSame(['id', 'name'], $props);
            break;
        }
    }

    public function testColumnsUpdatesGetColumns(): void
    {
        $table = $this->createTable()->columns('id', 'name');
        $cols = array_keys($table->getColumns());

        $this->assertSame(['id', 'name'], $cols);
    }

    // =========================================================================
    // Set membership (has)
    // =========================================================================

    public function testHasReturnsTrueForExistingMember(): void
    {
        $table = $this->createTable()->columns('id');

        $this->assertTrue($table->has((object)['id' => 1]));
        $this->assertTrue($table->has((object)['id' => 5]));
    }

    public function testHasReturnsFalseForNonExistingMember(): void
    {
        $table = $this->createTable()->columns('id');

        $this->assertFalse($table->has((object)['id' => 99]));
    }

    // =========================================================================
    // Union
    // =========================================================================

    public function testUnionCombinesTwoTables(): void
    {
        $table = $this->createTable();
        $engineering = $table->eq('dept', 'Engineering');
        $marketing = $table->eq('dept', 'Marketing');

        $union = $engineering->union($marketing);

        $ids = [];
        foreach ($union as $row) {
            $ids[] = $row->id;
        }

        // Alice, Charlie (Engineering) + Eve (Marketing)
        $this->assertSame([1, 3, 5], $ids);
    }

    public function testUnionDeduplicatesByRowId(): void
    {
        $table = $this->createTable();
        $all = $table;
        $engineering = $table->eq('dept', 'Engineering');

        $union = $all->union($engineering);

        // Should still be 5 rows, not 7
        $this->assertSame(5, $union->count());
    }

    // =========================================================================
    // Except
    // =========================================================================

    public function testExceptRemovesMatchingRows(): void
    {
        $table = $this->createTable();
        $sales = $table->eq('dept', 'Sales');

        $notSales = $table->except($sales);

        $ids = [];
        foreach ($notSales as $row) {
            $ids[] = $row->id;
        }

        // Alice, Charlie (Engineering) + Eve (Marketing)
        $this->assertSame([1, 3, 5], $ids);
    }

    public function testExceptWithSetInterface(): void
    {
        $table = $this->createTable();
        $excludeIds = new Set('id', [2, 4]);

        $result = $table->except($excludeIds);

        $ids = [];
        foreach ($result as $row) {
            $ids[] = $row->id;
        }

        $this->assertSame([1, 3, 5], $ids);
    }

    // =========================================================================
    // Ordering
    // =========================================================================

    public function testOrderByNumericColumnAsc(): void
    {
        $table = $this->createTable()->order('age ASC');

        $ages = [];
        foreach ($table as $row) {
            $ages[] = $row->age;
        }

        $this->assertSame([9, 22, 25, 28, 35], $ages);
    }

    public function testOrderByNumericColumnDesc(): void
    {
        $table = $this->createTable()->order('age DESC');

        $ages = [];
        foreach ($table as $row) {
            $ages[] = $row->age;
        }

        $this->assertSame([35, 28, 25, 22, 9], $ages);
    }

    public function testOrderByStringColumn(): void
    {
        $table = $this->createTable()->order('name ASC');

        $names = [];
        foreach ($table as $row) {
            $names[] = $row->name;
        }

        // Verify sorting happened (specific order depends on collator locale)
        $this->assertSame(5, count($names));
        $this->assertSame('Alice', $names[0]); // A sorts first in most locales
    }

    public function testOrderByMultipleColumns(): void
    {
        $table = $this->createTable()->order('dept ASC, age ASC');

        $result = [];
        foreach ($table as $row) {
            $result[] = $row->dept . ':' . $row->age;
        }

        // Engineering: Alice(9), Åsa(35)
        // Marketing: Øystein(22)
        // Sales: Bob(25), Örjan(28)
        $this->assertSame([
            'Engineering:9',
            'Engineering:35',
            'Marketing:22',
            'Sales:25',
            'Sales:28',
        ], $result);
    }

    public function testOrderWithLimitAndOffset(): void
    {
        $table = $this->createTable()->order('age ASC')->offset(1)->limit(3);

        $ages = [];
        foreach ($table as $row) {
            $ages[] = $row->age;
        }

        // Ages sorted: [9, 22, 25, 28, 35], skip 1, take 3 = [22, 25, 28]
        $this->assertSame([22, 25, 28], $ages);
    }

    public function testOrderAfterFilter(): void
    {
        $table = $this->createTable()
            ->eq('dept', 'Engineering')
            ->order('age ASC');

        $ages = [];
        foreach ($table as $row) {
            $ages[] = $row->age;
        }

        // Engineering: Alice(9), Åsa(35) - ordered by age
        $this->assertSame([9, 35], $ages);
    }

    public function testClearOrderWithNull(): void
    {
        $table = $this->createTable()->order('age ASC')->order(null);

        // After clearing order, should return to original order
        // Just verify it doesn't throw and returns rows
        $this->assertSame(5, $table->count());
    }

    public function testOrderByMultipleColumnsDescAsc(): void
    {
        $table = $this->createTable()->order('dept DESC, age ASC');

        $result = [];
        foreach ($table as $row) {
            $result[] = $row->dept . ':' . $row->age;
        }

        // Sales: Bob(25), Örjan(28)
        // Marketing: Øystein(22)
        // Engineering: Alice(9), Åsa(35)
        $this->assertSame([
            'Sales:25',
            'Sales:28',
            'Marketing:22',
            'Engineering:9',
            'Engineering:35',
        ], $result);
    }

    public function testOrderByMultipleColumnsDescDesc(): void
    {
        $table = $this->createTable()->order('dept DESC, age DESC');

        $result = [];
        foreach ($table as $row) {
            $result[] = $row->dept . ':' . $row->age;
        }

        // Sales DESC by age: Örjan(28), Bob(25)
        // Marketing: Øystein(22)
        // Engineering DESC by age: Åsa(35), Alice(9)
        $this->assertSame([
            'Sales:28',
            'Sales:25',
            'Marketing:22',
            'Engineering:35',
            'Engineering:9',
        ], $result);
    }

    public function testOrderParsesLowercaseAsc(): void
    {
        $table = $this->createTable()->order('age asc');

        $ages = [];
        foreach ($table as $row) {
            $ages[] = $row->age;
        }

        $this->assertSame([9, 22, 25, 28, 35], $ages);
    }

    public function testOrderParsesMixedCaseDesc(): void
    {
        $table = $this->createTable()->order('age DeSc');

        $ages = [];
        foreach ($table as $row) {
            $ages[] = $row->age;
        }

        $this->assertSame([35, 28, 25, 22, 9], $ages);
    }

    public function testOrderParsesColumnWithoutDirection(): void
    {
        // Column without direction defaults to ASC
        $table = $this->createTable()->order('age');

        $ages = [];
        foreach ($table as $row) {
            $ages[] = $row->age;
        }

        $this->assertSame([9, 22, 25, 28, 35], $ages);
    }

    public function testOrderParsesMultipleMixedDirections(): void
    {
        // Mix of explicit and implicit directions
        $table = $this->createTable()->order('dept, age DESC');

        $result = [];
        foreach ($table as $row) {
            $result[] = $row->dept . ':' . $row->age;
        }

        // Engineering ASC (default), age DESC: Åsa(35), Alice(9)
        // Marketing: Øystein(22)
        // Sales, age DESC: Örjan(28), Bob(25)
        $this->assertSame([
            'Engineering:35',
            'Engineering:9',
            'Marketing:22',
            'Sales:28',
            'Sales:25',
        ], $result);
    }

    public function testOrderParsesWithExtraWhitespace(): void
    {
        $table = $this->createTable()->order('  dept  ASC  ,  age   DESC  ');

        $result = [];
        foreach ($table as $row) {
            $result[] = $row->dept . ':' . $row->age;
        }

        $this->assertSame([
            'Engineering:35',
            'Engineering:9',
            'Marketing:22',
            'Sales:28',
            'Sales:25',
        ], $result);
    }

    public function testOrderWithEmptyStringClearsOrder(): void
    {
        $table = $this->createTable()->order('age ASC')->order('');

        // After clearing order, should return to original order
        $this->assertSame(5, $table->count());
    }

    // =========================================================================
    // Immutability
    // =========================================================================

    public function testFilterDoesNotMutateOriginal(): void
    {
        $table = $this->createTable();
        $filtered = $table->eq('name', 'Alice');

        $this->assertSame(5, $table->count());
        $this->assertSame(1, $filtered->count());
    }

    public function testLimitDoesNotMutateOriginal(): void
    {
        $table = $this->createTable();
        $limited = $table->limit(2);

        $this->assertSame(5, $table->count());
        $this->assertSame(2, $limited->count());
    }

    public function testOrderDoesNotMutateOriginal(): void
    {
        $table = $this->createTable();
        $ordered = $table->order('age DESC');

        // Original should maintain its order
        $originalFirst = null;
        foreach ($table as $row) {
            $originalFirst = $row->id;
            break;
        }

        $orderedFirst = null;
        foreach ($ordered as $row) {
            $orderedFirst = $row->id;
            break;
        }

        // Åsa (id 3, age 35) should be first in DESC order
        $this->assertSame(3, $orderedFirst);
        // Original first should be 1 (Alice)
        $this->assertSame(1, $originalFirst);
    }
}
