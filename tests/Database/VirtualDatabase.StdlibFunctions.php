<?php
/**
 * The scalar standard library: maths and string padding
 *
 * MOD, POWER, SQRT, LN, EXP, SIGN, REPEAT, REVERSE, LPAD and RPAD used to be
 * "Unknown function" errors. They are registered through the same public
 * VirtualDatabase::createFunction() path as everything else in
 * StandardFunctions, so these tests are as much a check on that registry
 * staying usable as on the functions themselves.
 *
 * The interesting content is the edge cases. Every one of these functions has
 * inputs with no answer (SQRT(-1), LN(0), MOD(x, 0)) or no representable
 * answer (EXP(1000)), and the two must not be conflated: undefined is NULL,
 * unrepresentable raises. Values cross-checked against sqlite3 3.45 unless
 * noted.
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

    private function eval(VirtualDatabase $vdb, string $expression): mixed
    {
        $rows = iterator_to_array($vdb->query("SELECT $expression AS v FROM users WHERE id = 1"));
        return $rows[0]->v;
    }

    /** Assert that evaluating $expression raises with $needle in the message */
    private function assertFails(VirtualDatabase $vdb, string $expression, string $needle): void
    {
        try {
            $this->eval($vdb, $expression);
            $this->fail("Expected $expression to fail");
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());
        }
    }

    // ── MOD ──────────────────────────────────────────────────────────────

    public function testMod(): void
    {
        $vdb = $this->vdb();

        // Integers in, integer out (SQL:2003, MySQL, PostgreSQL, Oracle).
        // sqlite3 answers 1.0 here because its mod() is an fmod() wrapper.
        $this->assertSame(1, $this->eval($vdb, 'MOD(7, 3)'));
        $this->assertSame(0, $this->eval($vdb, 'MOD(9, 3)'));

        // Truncated division: the sign follows the dividend
        $this->assertSame(-1, $this->eval($vdb, 'MOD(-7, 3)'));
        $this->assertSame(1, $this->eval($vdb, 'MOD(7, -3)'));

        // A float operand gives a float, exactly as sqlite3 does
        $this->assertSame(1.5, $this->eval($vdb, 'MOD(7.5, 2)'));
    }

    public function testModByZeroIsNull(): void
    {
        $vdb = $this->vdb();

        // Consistent with sqlite3 and with the engine's own % and / operators,
        // which already answer NULL rather than raising
        $this->assertNull($this->eval($vdb, 'MOD(5, 0)'));
        $this->assertNull($this->eval($vdb, 'MOD(5.0, 0.0)'));
    }

    // ── POWER ────────────────────────────────────────────────────────────

    public function testPower(): void
    {
        $vdb = $this->vdb();

        // Approximate numeric even for integer operands, as in sqlite3
        $this->assertSame(1024.0, $this->eval($vdb, 'POWER(2, 10)'));
        $this->assertSame(4.0, $this->eval($vdb, 'POWER(-2, 2)'));
        $this->assertSame(0.5, $this->eval($vdb, 'POWER(2, -1)'));
        $this->assertSame(1.0, $this->eval($vdb, 'POWER(0, 0)'));
        // POW is sqlite3's and MySQL's alias for the same function
        $this->assertSame(8.0, $this->eval($vdb, 'POW(2, 3)'));
    }

    public function testPowerWithoutARealResultIsNull(): void
    {
        $vdb = $this->vdb();

        // No real cube root of a negative via a fractional exponent (sqlite3
        // agrees: power(-8, 1.0/3) is NULL)
        $this->assertNull($this->eval($vdb, 'POWER(-8, 0.5)'));
        // 0 to a negative power is a division by zero. sqlite3 returns Inf
        // here; NULL keeps it consistent with MOD(x, 0) and with x / 0.
        $this->assertNull($this->eval($vdb, 'POWER(0, -1)'));
    }

    public function testPowerOverflowRaisesRatherThanReturningInf(): void
    {
        // sqlite3 returns Inf. An unrepresentable result is not an unknown
        // one, so it is neither NULL nor a float that poisons every aggregate
        // it reaches.
        $this->assertFails($this->vdb(), 'POWER(10, 400)', 'POWER() overflowed');
    }

    // ── SQRT / LN / EXP ──────────────────────────────────────────────────

    public function testSqrt(): void
    {
        $vdb = $this->vdb();

        $this->assertSame(2.0, $this->eval($vdb, 'SQRT(4)'));
        $this->assertSame(0.0, $this->eval($vdb, 'SQRT(0)'));
        $this->assertSame(1.5, $this->eval($vdb, 'SQRT(2.25)'));
        // Negative input has no real square root; sqlite3 agrees
        $this->assertNull($this->eval($vdb, 'SQRT(-1)'));
    }

    public function testLn(): void
    {
        $vdb = $this->vdb();

        $this->assertSame(0.0, $this->eval($vdb, 'LN(1)'));
        $this->assertSame(1.0, $this->eval($vdb, 'LN(EXP(1))'));
        // Outside the domain. ln(0) tends to -INF and ln(-1) is not real;
        // sqlite3 answers NULL for both and so do we.
        $this->assertNull($this->eval($vdb, 'LN(0)'));
        $this->assertNull($this->eval($vdb, 'LN(-1)'));
    }

    public function testPlainLogIsDeliberatelyNotRegistered(): void
    {
        // One-argument LOG is base 10 in sqlite3 and PostgreSQL but base e in
        // MySQL. Rather than pick a base for the caller, refuse the spelling.
        $this->assertFails($this->vdb(), 'LOG(100)', 'Unknown function: LOG');
    }

    public function testExp(): void
    {
        $vdb = $this->vdb();

        $this->assertSame(1.0, $this->eval($vdb, 'EXP(0)'));
        $this->assertSame(round(M_E, 10), round($this->eval($vdb, 'EXP(1)'), 10));
        // Underflow to zero is an ordinary answer, not an error (sqlite3 agrees)
        $this->assertSame(0.0, $this->eval($vdb, 'EXP(-1000)'));
    }

    public function testExpOverflowRaisesRatherThanReturningInf(): void
    {
        $this->assertFails($this->vdb(), 'EXP(1000)', 'EXP() overflowed');
    }

    // ── SIGN ─────────────────────────────────────────────────────────────

    public function testSign(): void
    {
        $vdb = $this->vdb();

        // Integer -1/0/1 whatever the input type, as in sqlite3
        $this->assertSame(-1, $this->eval($vdb, 'SIGN(-5)'));
        $this->assertSame(0, $this->eval($vdb, 'SIGN(0)'));
        $this->assertSame(1, $this->eval($vdb, 'SIGN(3.2)'));
        $this->assertSame(-1, $this->eval($vdb, 'SIGN(-0.0001)'));
    }

    // ── String helpers ───────────────────────────────────────────────────

    public function testRepeat(): void
    {
        $vdb = $this->vdb();

        $this->assertSame('ababab', $this->eval($vdb, "REPEAT('ab', 3)"));
        $this->assertSame('ab', $this->eval($vdb, "REPEAT('ab', 1)"));
        $this->assertSame('', $this->eval($vdb, "REPEAT('ab', 0)"));
        $this->assertSame('', $this->eval($vdb, "REPEAT('ab', -2)"));
        $this->assertSame('', $this->eval($vdb, "REPEAT('', 5)"));
    }

    public function testRepeatRefusesToExhaustTheProcess(): void
    {
        // A length arriving as data must not turn into an uncatchable
        // out-of-memory fatal
        $this->assertFails($this->vdb(), "REPEAT('x', 900000000)", 'over the');
    }

    /**
     * The guard must not be defeated by a length that int cannot hold.
     *
     * Casting an out-of-range float to int in PHP wraps to an
     * implementation-defined value, and across most of the range above
     * PHP_INT_MAX it wraps *negative* - (int)1.0E+19 is -8446744073709551616.
     * A guard that narrows first therefore sees "repeat a negative number of
     * times", returns '' and lets the query carry on with a wrong answer,
     * while leaking a PHP cast warning into the error log on every such row.
     *
     * 2e19 happens to wrap positive and would raise either way, so it is the
     * odd-magnitude cases below that actually pin the behaviour down.
     * sqlite3 saturates CAST(1e19 AS INTEGER) to INT64_MAX rather than
     * wrapping, so it has no equivalent hole.
     */
    public function testRepeatRefusesLengthsThatDoNotFitInAnInt(): void
    {
        $vdb = $this->vdb();

        $this->assertFails($vdb, "REPEAT('x', 1e19)", 'over the');
        $this->assertFails($vdb, "REPEAT('x', 9.3e18)", 'over the');
        $this->assertFails($vdb, "REPEAT('x', 1.8e19)", 'over the');
        $this->assertFails($vdb, "REPEAT('x', 2e19)", 'over the');
        // An int at the top of the range must not overflow into a float and
        // slip past either
        $this->assertFails($vdb, "REPEAT('x', 9223372036854775807)", 'over the');

        // Repeating nothing costs nothing, however many times
        $this->assertSame('', $this->eval($vdb, "REPEAT('', 1e19)"));
    }

    public function testReverse(): void
    {
        $vdb = $this->vdb();

        $this->assertSame('cba', $this->eval($vdb, "REVERSE('abc')"));
        $this->assertSame('ecila', $this->eval($vdb, 'REVERSE(name)'));
        $this->assertSame('', $this->eval($vdb, "REVERSE('')"));
    }

    public function testPad(): void
    {
        $vdb = $this->vdb();

        $this->assertSame('   hi', $this->eval($vdb, "LPAD('hi', 5)"));
        $this->assertSame('hi   ', $this->eval($vdb, "RPAD('hi', 5)"));
        // The pad string repeats and is clipped to fill exactly
        $this->assertSame('xyxhi', $this->eval($vdb, "LPAD('hi', 5, 'xy')"));
        $this->assertSame('hixyx', $this->eval($vdb, "RPAD('hi', 5, 'xy')"));
        // Already long enough: truncated to exactly LEN, keeping the front
        $this->assertSame('h', $this->eval($vdb, "LPAD('hi', 1, 'x')"));
        $this->assertSame('h', $this->eval($vdb, "RPAD('hi', 1, 'x')"));
        $this->assertSame('hi', $this->eval($vdb, "LPAD('hi', 2, 'x')"));
        // Nothing to pad with, and nothing to truncate
        $this->assertSame('hi', $this->eval($vdb, "LPAD('hi', 9, '')"));
        // Non-positive length is the empty string
        $this->assertSame('', $this->eval($vdb, "LPAD('hi', 0)"));
        $this->assertSame('', $this->eval($vdb, "RPAD('hi', -3)"));
    }

    public function testPadArityIsCheckedByTheFunction(): void
    {
        $vdb = $this->vdb();

        // LPAD is registered variadic (2 or 3 args), so the evaluator cannot
        // check its arity - the function does, and must
        $this->assertFails($vdb, "LPAD('hi')", 'LPAD() expects 2 or 3 arguments, 1 given');
        $this->assertFails($vdb, "LPAD('hi', 5, 'x', 'y')", 'LPAD() expects 2 or 3 arguments, 4 given');
    }

    public function testPadRefusesToExhaustTheProcess(): void
    {
        $this->assertFails($this->vdb(), "LPAD('x', 900000000)", 'over the');
    }

    /** @see testRepeatRefusesLengthsThatDoNotFitInAnInt - same hole, same fix */
    public function testPadRefusesLengthsThatDoNotFitInAnInt(): void
    {
        $vdb = $this->vdb();

        $this->assertFails($vdb, "LPAD('x', 1e19)", 'over the');
        $this->assertFails($vdb, "LPAD('x', 1.8e19)", 'over the');
        $this->assertFails($vdb, "RPAD('x', 1e19)", 'over the');
        $this->assertFails($vdb, "RPAD('x', 9.3e18)", 'over the');

        // The width is checked before the pad string is looked at, because
        // the result is LEN bytes wide whatever is padding it
        $this->assertFails($vdb, "LPAD('x', 1e19, '')", 'over the');
    }

    /**
     * The refusal must name the size it refused, readably.
     *
     * A float interpolated straight into the message prints as "1.0E+19",
     * which reads like a typo rather than a length.
     */
    public function testOversizeMessageReportsTheRequestedSize(): void
    {
        $this->assertFails(
            $this->vdb(),
            "REPEAT('x', 1e19)",
            'REPEAT() would build a string of 10000000000000000000 bytes'
        );
    }

    // ── NULL propagation, uniformly ──────────────────────────────────────

    public function testNullArgumentPropagates(): void
    {
        $vdb = $this->vdb();

        $this->assertNull($this->eval($vdb, 'MOD(NULL, 3)'));
        $this->assertNull($this->eval($vdb, 'MOD(3, NULL)'));
        $this->assertNull($this->eval($vdb, 'POWER(NULL, 2)'));
        $this->assertNull($this->eval($vdb, 'POWER(2, NULL)'));
        $this->assertNull($this->eval($vdb, 'SQRT(NULL)'));
        $this->assertNull($this->eval($vdb, 'LN(NULL)'));
        $this->assertNull($this->eval($vdb, 'EXP(NULL)'));
        $this->assertNull($this->eval($vdb, 'SIGN(NULL)'));
        $this->assertNull($this->eval($vdb, 'REPEAT(NULL, 3)'));
        $this->assertNull($this->eval($vdb, "REPEAT('a', NULL)"));
        $this->assertNull($this->eval($vdb, 'REVERSE(NULL)'));
        $this->assertNull($this->eval($vdb, 'LPAD(NULL, 5)'));
        $this->assertNull($this->eval($vdb, "LPAD('a', NULL)"));
        // A supplied-but-NULL pad string is UNKNOWN, as with TRIM's charset
        $this->assertNull($this->eval($vdb, "LPAD('a', 5, NULL)"));
        $this->assertNull($this->eval($vdb, "RPAD('a', 5, NULL)"));
    }

    // ── Non-numeric arguments fail fast, as ABS already did ──────────────

    public function testNonNumericArgumentFailsFast(): void
    {
        $vdb = $this->vdb();

        // sqlite3 quietly answers NULL for sign('abc'). This engine already
        // raises for ABS('abc'), and the new functions match their neighbours.
        $this->assertFails($vdb, 'SIGN(name)', 'SIGN() expects a numeric argument');
        $this->assertFails($vdb, 'SQRT(name)', 'SQRT() expects a numeric argument');
        $this->assertFails($vdb, 'MOD(name, 2)', 'MOD() expects a numeric argument');
        // ...but numeric strings still coerce
        $this->assertSame(1, $this->eval($vdb, "MOD('7', '3')"));
        $this->assertSame(2.0, $this->eval($vdb, "SQRT('4')"));
    }

    // ── They compose like any other expression ───────────────────────────

    public function testUsableInWhereAndOrderBy(): void
    {
        $table = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('n', ColumnType::Int),
        );
        foreach ([1 => 10, 2 => 7, 3 => 4] as $id => $n) {
            $table->insert(['id' => $id, 'n' => $n]);
        }
        $vdb = new VirtualDatabase();
        $vdb->registerTable('nums', $table);

        $rows = iterator_to_array($vdb->query(
            'SELECT id FROM nums WHERE MOD(n, 2) = 0 ORDER BY SQRT(n) DESC'
        ));
        $this->assertSame([1, 3], array_map(fn($r) => $r->id, $rows));
    }

    public function testUserOverrideStillWinsOverTheNewBuiltins(): void
    {
        $vdb = $this->vdb();
        $this->assertSame(2.0, $this->eval($vdb, 'SQRT(4)'));

        $vdb->createFunction('SQRT', fn($x) => 'nope', 1);
        $this->assertSame('nope', $this->eval($vdb, 'SQRT(4)'));
    }
};

exit($test->run());
