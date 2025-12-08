<?php
/**
 * Test ResultSet implementation
 *
 * Tests: iteration, toArray, one, column, field, count, JSON serialization, hydration
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use mini\Test;
use mini\Database\ResultSet;

class TestUser {
    public int $id;
    public string $name;
}

class TestUserWithConstructor {
    public int $id;
    public string $name;
    public string $prefix;

    public function __construct(string $prefix = '') {
        $this->prefix = $prefix;
    }
}

$test = new class extends Test {

    private array $rows = [
        ['id' => 1, 'name' => 'Alice'],
        ['id' => 2, 'name' => 'Bob'],
    ];

    public function testIteratesOverRows(): void
    {
        $rs = new ResultSet($this->rows);

        $collected = [];
        foreach ($rs as $row) {
            $collected[] = $row;
        }

        $this->assertSame($this->rows, $collected);
    }

    public function testToArrayReturnsAllRows(): void
    {
        $rs = new ResultSet($this->rows);
        $this->assertSame($this->rows, $rs->toArray());
    }

    public function testOneReturnsFirstRow(): void
    {
        $rs = new ResultSet($this->rows);
        $this->assertSame(['id' => 1, 'name' => 'Alice'], $rs->one());
    }

    public function testOneReturnsNullForEmptyResult(): void
    {
        $rs = new ResultSet([]);
        $this->assertNull($rs->one());
    }

    public function testColumnReturnsFirstColumnValues(): void
    {
        $rs = new ResultSet($this->rows);
        $this->assertSame([1, 2], $rs->column());
    }

    public function testFieldReturnsFirstColumnOfFirstRow(): void
    {
        $rs = new ResultSet($this->rows);
        $this->assertSame(1, $rs->field());
    }

    public function testFieldReturnsNullForEmptyResult(): void
    {
        $rs = new ResultSet([]);
        $this->assertNull($rs->field());
    }

    public function testCountReturnsRowCount(): void
    {
        $rs = new ResultSet($this->rows);
        $this->assertCount(2, $rs);
    }

    public function testCountReturnsZeroForEmptyResult(): void
    {
        $rs = new ResultSet([]);
        $this->assertCount(0, $rs);
    }

    public function testJsonSerializationWorks(): void
    {
        $rs = new ResultSet($this->rows);
        $json = json_encode($rs);
        $this->assertSame('[{"id":1,"name":"Alice"},{"id":2,"name":"Bob"}]', $json);
    }

    public function testWithHydratorTransformsRows(): void
    {
        $rs = new ResultSet($this->rows);
        $hydrated = $rs->withHydrator(fn($row) => (object) $row)->toArray();

        $this->assertSame(1, $hydrated[0]->id);
        $this->assertSame('Alice', $hydrated[0]->name);
    }

    public function testWithEntityClassHydratesToEntity(): void
    {
        $rs = new ResultSet($this->rows);
        $users = $rs->withEntityClass(TestUser::class)->toArray();

        $this->assertInstanceOf(TestUser::class, $users[0]);
        $this->assertSame(1, $users[0]->id);
        $this->assertSame('Alice', $users[0]->name);
    }

    public function testWithEntityClassWithConstructorArgs(): void
    {
        $rs = new ResultSet($this->rows);
        $users = $rs->withEntityClass(TestUserWithConstructor::class, ['Mr. '])->toArray();

        $this->assertSame('Mr. ', $users[0]->prefix);
        $this->assertSame(1, $users[0]->id);
    }

    public function testImmutability(): void
    {
        $rs1 = new ResultSet($this->rows);
        $rs2 = $rs1->withHydrator(fn($row) => (object) $row);

        $this->assertFalse($rs1 === $rs2);

        // Original should still return arrays
        $first = $rs1->one();
        $this->assertTrue(is_array($first));
    }

    public function testWorksWithGenerators(): void
    {
        $generator = function() {
            yield ['id' => 1];
            yield ['id' => 2];
            yield ['id' => 3];
        };

        $rs = new ResultSet($generator());
        $this->assertSame([1, 2, 3], $rs->column());
    }
};

exit($test->run());
