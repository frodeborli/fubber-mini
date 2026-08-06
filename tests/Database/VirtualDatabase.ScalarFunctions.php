<?php
/**
 * Scalar functions: pluggable registry + the standard library on top of it
 *
 * Scalar functions used to be a hardcoded `match ($name)` inside
 * ExpressionEvaluator, so an application could add an aggregate
 * (createAggregate) but not a plain function. They now live in a registry that
 * VirtualDatabase::createFunction() writes to, and the built-ins are registered
 * through that same registry by StandardFunctions.
 *
 * These tests pin both halves: the registry contract (register, override,
 * arity enforcement, unknown function) and every built-in still answering
 * exactly what it answered before the move.
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Test;
use mini\Database\VirtualDatabase;
use mini\Table\InMemoryTable;
use mini\Table\ColumnDef;
use mini\Table\Types\ColumnType;
use mini\Table\Types\IndexType;

$test = new class extends Test {

    private function vdb(): VirtualDatabase
    {
        $table = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('name', ColumnType::Text),
        );
        $table->insert(['id' => 1, 'name' => 'alice']);

        $vdb = new VirtualDatabase();
        $vdb->registerTable('users', $table);
        return $vdb;
    }

    /** Evaluate a scalar expression against the single fixture row */
    private function eval(VirtualDatabase $vdb, string $expression): mixed
    {
        $rows = iterator_to_array($vdb->query("SELECT $expression AS v FROM users WHERE id = 1"));
        return $rows[0]->v;
    }

    // ── Registry ─────────────────────────────────────────────────────────

    public function testCreateFunctionRegistersAScalarFunction(): void
    {
        $vdb = $this->vdb();
        $vdb->createFunction('SLUGIFY', fn(?string $s) => $s === null ? null : str_replace(' ', '-', $s), 1);

        $this->assertSame('a-b', $this->eval($vdb, "SLUGIFY('a b')"));
    }

    public function testCreateFunctionIsCaseInsensitive(): void
    {
        $vdb = $this->vdb();
        $vdb->createFunction('shout', fn(string $s) => strtoupper($s) . '!', 1);

        $this->assertSame('HI!', $this->eval($vdb, "SHOUT('hi')"));
        $this->assertSame('HI!', $this->eval($vdb, "ShOuT('hi')"));
    }

    public function testCreateFunctionReturnsTrueLikeCreateAggregate(): void
    {
        $vdb = $this->vdb();
        $this->assertTrue($vdb->createFunction('NOOP', fn() => 1, 0));
    }

    public function testUserFunctionOverridesBuiltin(): void
    {
        $vdb = $this->vdb();
        $this->assertSame('ALICE', $this->eval($vdb, 'UPPER(name)'));

        // Deliberate, documented behaviour: one registry, last registration wins
        $vdb->createFunction('UPPER', fn(string $s) => 'overridden', 1);
        $this->assertSame('overridden', $this->eval($vdb, 'UPPER(name)'));
    }

    public function testOverrideIsPerDatabaseInstance(): void
    {
        $vdb = $this->vdb();
        $vdb->createFunction('LOWER', fn(string $s) => 'nope', 1);

        $this->assertSame('nope', $this->eval($vdb, 'LOWER(name)'));
        $this->assertSame('alice', $this->eval($this->vdb(), 'LOWER(name)'));
    }

    public function testVariadicFunctionAcceptsAnyArgumentCount(): void
    {
        $vdb = $this->vdb();
        $vdb->createFunction('JOINED', fn(...$args) => implode('/', $args), -1);

        $this->assertSame('a', $this->eval($vdb, "JOINED('a')"));
        $this->assertSame('a/b/c', $this->eval($vdb, "JOINED('a', 'b', 'c')"));
    }

    public function testWrongArgumentCountFailsFast(): void
    {
        $vdb = $this->vdb();
        $vdb->createFunction('PAIR', fn($a, $b) => "$a$b", 2);

        try {
            $this->eval($vdb, "PAIR('a')");
            $this->fail('Expected an arity error');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('PAIR()', $e->getMessage());
            $this->assertStringContainsString('expects 2 arguments, 1 given', $e->getMessage());
        }
    }

    public function testBuiltinArityIsEnforcedToo(): void
    {
        $vdb = $this->vdb();

        try {
            $this->eval($vdb, "UPPER('a', 'b')");
            $this->fail('Expected an arity error');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('UPPER() expects 1 argument, 2 given', $e->getMessage());
        }
    }

    public function testUnknownFunctionFailsFast(): void
    {
        $vdb = $this->vdb();

        try {
            $this->eval($vdb, 'NOSUCHFUNCTION(name)');
            $this->fail('Expected an unknown-function error');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Unknown function: NOSUCHFUNCTION', $e->getMessage());
        }
    }

    // ── The standard library, after the move out of core ─────────────────

    public function testStringFunctions(): void
    {
        $vdb = $this->vdb();

        $this->assertSame('ALICE', $this->eval($vdb, 'UPPER(name)'));
        $this->assertSame('alice', $this->eval($vdb, "LOWER('ALICE')"));
        $this->assertSame(5, $this->eval($vdb, 'LENGTH(name)'));
        $this->assertSame(5, $this->eval($vdb, 'LEN(name)'));
        $this->assertSame('abc', $this->eval($vdb, "CONCAT('a', 'b', 'c')"));
        $this->assertSame('abc', $this->eval($vdb, "CONCAT('a', NULL, 'bc')"));
        $this->assertSame('a-c', $this->eval($vdb, "REPLACE('abc', 'b', '-')"));
        $this->assertSame(2, $this->eval($vdb, "INSTR('alice', 'li')"));
        $this->assertSame(0, $this->eval($vdb, "INSTR('alice', 'zz')"));
    }

    public function testTrimFunctions(): void
    {
        $vdb = $this->vdb();

        $this->assertSame('x', $this->eval($vdb, "TRIM('  x  ')"));
        $this->assertSame('x  ', $this->eval($vdb, "LTRIM('  x  ')"));
        $this->assertSame('  x', $this->eval($vdb, "RTRIM('  x  ')"));
        $this->assertSame('b', $this->eval($vdb, "TRIM('aba', 'a')"));
        // An empty character set removes nothing
        $this->assertSame('aba', $this->eval($vdb, "TRIM('aba', '')"));
        // A supplied-but-NULL character set is UNKNOWN
        $this->assertNull($this->eval($vdb, "TRIM('aba', NULL)"));
    }

    public function testSubstrSemantics(): void
    {
        $vdb = $this->vdb();

        $this->assertSame('bcd', $this->eval($vdb, "SUBSTR('abcdef', 2, 3)"));
        $this->assertSame('bcdef', $this->eval($vdb, "SUBSTR('abcdef', 2)"));
        // Negative start counts back from the end
        $this->assertSame('ef', $this->eval($vdb, "SUBSTR('abcdef', -2)"));
        // Negative length selects the characters preceding the start
        $this->assertSame('bc', $this->eval($vdb, "SUBSTR('abcdef', 4, -2)"));
        // Out-of-range positions contribute nothing rather than shifting
        $this->assertSame('a', $this->eval($vdb, "SUBSTR('abcdef', 0, 2)"));
        $this->assertSame('', $this->eval($vdb, "SUBSTR('abcdef', 10, 2)"));
        $this->assertNull($this->eval($vdb, "SUBSTR('abcdef', NULL)"));
        $this->assertNull($this->eval($vdb, "SUBSTR('abcdef', 1, NULL)"));
        $this->assertSame('bcd', $this->eval($vdb, "SUBSTRING('abcdef', 2, 3)"));
    }

    public function testNumericFunctions(): void
    {
        $vdb = $this->vdb();

        $this->assertSame(5, $this->eval($vdb, 'ABS(-5)'));
        $this->assertSame(5.0, $this->eval($vdb, 'ABS(-5.0)'));
        $this->assertSame(3.0, $this->eval($vdb, 'ROUND(3.4)'));
        $this->assertSame(3.14, $this->eval($vdb, 'ROUND(3.14159, 2)'));
        $this->assertNull($this->eval($vdb, 'ROUND(3.14159, NULL)'));
        $this->assertSame(3.0, $this->eval($vdb, 'FLOOR(3.9)'));
        $this->assertSame(4.0, $this->eval($vdb, 'CEIL(3.1)'));
        $this->assertSame(4.0, $this->eval($vdb, 'CEILING(3.1)'));
        // Numeric strings coerce (keeping their int/float-ness); anything else
        // is an SQL-level error
        $this->assertSame(5, $this->eval($vdb, "ABS(' -5 ')"));
        $this->assertSame(5.5, $this->eval($vdb, "ABS(' -5.5 ')"));
    }

    public function testNumericFunctionRejectsNonNumericArgument(): void
    {
        $vdb = $this->vdb();

        try {
            $this->eval($vdb, 'ABS(name)');
            $this->fail('Expected a numeric-argument error');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ABS() expects a numeric argument', $e->getMessage());
        }
    }

    public function testNullHandlingFunctions(): void
    {
        $vdb = $this->vdb();

        $this->assertSame('b', $this->eval($vdb, "COALESCE(NULL, 'b', 'c')"));
        $this->assertNull($this->eval($vdb, 'COALESCE(NULL, NULL)'));
        $this->assertNull($this->eval($vdb, "NULLIF('a', 'a')"));
        $this->assertSame('a', $this->eval($vdb, "NULLIF('a', 'b')"));
        $this->assertSame('a', $this->eval($vdb, "NULLIF('a', NULL)"));
        $this->assertSame('b', $this->eval($vdb, "IFNULL(NULL, 'b')"));
        $this->assertSame('a', $this->eval($vdb, "IFNULL('a', 'b')"));
        $this->assertSame('b', $this->eval($vdb, "NVL(NULL, 'b')"));
    }

    public function testNullArgumentPropagates(): void
    {
        $vdb = $this->vdb();

        $this->assertNull($this->eval($vdb, 'UPPER(NULL)'));
        $this->assertNull($this->eval($vdb, 'LOWER(NULL)'));
        $this->assertNull($this->eval($vdb, 'LENGTH(NULL)'));
        $this->assertNull($this->eval($vdb, 'TRIM(NULL)'));
        $this->assertNull($this->eval($vdb, 'SUBSTR(NULL, 1)'));
        $this->assertNull($this->eval($vdb, "REPLACE(NULL, 'a', 'b')"));
        $this->assertNull($this->eval($vdb, "INSTR(NULL, 'a')"));
        $this->assertNull($this->eval($vdb, 'ABS(NULL)'));
        $this->assertNull($this->eval($vdb, 'ROUND(NULL)'));
        $this->assertNull($this->eval($vdb, 'FLOOR(NULL)'));
        $this->assertNull($this->eval($vdb, 'CEIL(NULL)'));
        // CONCAT is the exception: it treats NULL as the empty string
        $this->assertSame('', $this->eval($vdb, 'CONCAT(NULL)'));
    }

    // ── SQL:2003 spellings added on top of the same implementations ──────

    public function testCharacterLengthSpellings(): void
    {
        $vdb = $this->vdb();

        $this->assertSame(5, $this->eval($vdb, 'CHAR_LENGTH(name)'));
        $this->assertSame(5, $this->eval($vdb, 'CHARACTER_LENGTH(name)'));
        $this->assertSame(5, $this->eval($vdb, 'OCTET_LENGTH(name)'));
        $this->assertNull($this->eval($vdb, 'CHAR_LENGTH(NULL)'));
    }

    public function testPositionSyntax(): void
    {
        $vdb = $this->vdb();

        // POSITION(x IN y) is INSTR(y, x) - note the swapped argument order
        $this->assertSame(2, $this->eval($vdb, "POSITION('li' IN name)"));
        $this->assertSame(0, $this->eval($vdb, "POSITION('zz' IN name)"));
        $this->assertSame(1, $this->eval($vdb, "POSITION('' IN name)"));
        $this->assertNull($this->eval($vdb, "POSITION('a' IN NULL)"));
        // The substring may be any expression
        $this->assertSame(3, $this->eval($vdb, "POSITION(UPPER('i') IN 'ALICE')"));
    }

    public function testSubstringFromForSyntax(): void
    {
        $vdb = $this->vdb();

        $this->assertSame('bcd', $this->eval($vdb, "SUBSTRING('abcdef' FROM 2 FOR 3)"));
        $this->assertSame('bcdef', $this->eval($vdb, "SUBSTRING('abcdef' FROM 2)"));
        $this->assertSame('lic', $this->eval($vdb, 'SUBSTRING(name FROM 2 FOR 3)'));
        // The comma spelling keeps working
        $this->assertSame('bcd', $this->eval($vdb, "SUBSTR('abcdef', 2, 3)"));
    }

    public function testTrimSpecificationSyntax(): void
    {
        $vdb = $this->vdb();

        $this->assertSame('x', $this->eval($vdb, "TRIM(BOTH FROM '  x  ')"));
        $this->assertSame('x', $this->eval($vdb, "TRIM(FROM '  x  ')"));
        $this->assertSame('x  ', $this->eval($vdb, "TRIM(LEADING FROM '  x  ')"));
        $this->assertSame('  x', $this->eval($vdb, "TRIM(TRAILING FROM '  x  ')"));
        $this->assertSame('x', $this->eval($vdb, "TRIM(BOTH 'a' FROM 'axa')"));
        $this->assertSame('xa', $this->eval($vdb, "TRIM(LEADING 'a' FROM 'axa')"));
        $this->assertSame('ax', $this->eval($vdb, "TRIM(TRAILING 'a' FROM 'axa')"));
        $this->assertSame('x', $this->eval($vdb, "TRIM('a' FROM 'axa')"));
        // The plain and comma spellings keep working
        $this->assertSame('x', $this->eval($vdb, "TRIM('  x  ')"));
        $this->assertSame('x', $this->eval($vdb, "TRIM('axa', 'a')"));
    }
};

exit($test->run());
