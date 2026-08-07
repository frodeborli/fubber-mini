<?php
/**
 * Typed datetime literals and EXTRACT, executed
 *
 * SQL:2003 F051-01/02/03 (DATE/TIME/TIMESTAMP literals) and F052 (EXTRACT).
 *
 * VirtualDatabase has no date type: datetimes are stored as text in the
 * canonical `Y-m-d H:i:s` shape - see the 'timestamps' fixture, which this
 * test reproduces. That format sorts lexicographically, so comparison and
 * ordering against a typed literal are ordinary string comparison, and EXTRACT
 * reads its fields straight out of the text.
 *
 * The expected values are SQLite's, which is this engine's reference
 * implementation. SQLite has neither construct, so the reference answers come
 * from its equivalent: EXTRACT(MONTH FROM x) is
 * `CAST(strftime('%m', x) AS INTEGER)`, and a typed literal is the quoted
 * string. Notably `strftime('%H','2020-05-06')` is 0, so a date with no time
 * part extracts an hour of zero rather than failing.
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Database\VirtualDatabase;
use mini\Parsing\SQL\SqlSyntaxException;
use mini\Table\ColumnDef;
use mini\Table\GeneratorTable;
use mini\Table\Types\ColumnType;
use mini\Table\Types\IndexType;
use mini\Test;

$test = new class extends Test {

    private VirtualDatabase $vdb;

    protected function setUp(): void
    {
        // Same shape and data as the 'timestamps' demo fixture, trimmed to a
        // size a test can reason about: row $i is $i days and $i hours after
        // 2020-01-01, so ids 1..40 span January and February 2020.
        $this->vdb = new VirtualDatabase();
        $this->vdb->registerTable('timestamps', new GeneratorTable(
            function () {
                $base = new DateTimeImmutable('2020-01-01 00:00:00');
                for ($i = 1; $i <= 40; $i++) {
                    $dt = $base->modify("+$i days +$i hours");
                    yield $i => (object) [
                        'id' => $i,
                        'value' => $i,
                        'label' => $dt->format('Y-m-d H:i:s'),
                        'created_at' => $dt->format('Y-m-d H:i:s'),
                    ];
                }
            },
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('value', ColumnType::Int),
            new ColumnDef('label', ColumnType::Text),
            new ColumnDef('created_at', ColumnType::Text),
        ));

        // The same instant spelled four ways. A federated source writes
        // whatever it writes, so these all turn up in one column.
        $this->vdb->registerTable('moments', self::textTable('ts', [
            '2020-01-01 00:00:07',
            '2020-01-01 00:00:07.0',
            '2020-01-01 00:00:07.00',
            '2020-01-01 00:00:07.5',
        ]));

        // Text that looks like a datetime and is not one. CSV and JSON tables
        // have no type system to stop this reaching the evaluator.
        $this->vdb->registerTable('junk', self::textTable('ts', [
            '2020-13-45',
            '2020-01-01 00:00:99',
            '25:99:99',
            '0000-01-01',
            '2019-02-30',
        ]));
    }

    /** @param string[] $values */
    private static function textTable(string $column, array $values): GeneratorTable
    {
        return new GeneratorTable(
            function () use ($column, $values) {
                foreach ($values as $i => $value) {
                    yield $i + 1 => (object) ['id' => $i + 1, $column => $value];
                }
            },
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef($column, ColumnType::Text),
        );
    }

    /** @return object[] */
    private function rows(string $sql): array
    {
        return iterator_to_array($this->vdb->query($sql), false);
    }

    private function scalar(string $sql): mixed
    {
        $rows = $this->rows($sql);
        $this->assertCount(1, $rows, $sql);
        return array_values(get_object_vars($rows[0]))[0];
    }

    public function testTypedLiteralsEvaluateToTheirNormalisedText(): void
    {
        $this->assertSame('2020-01-01', $this->scalar("SELECT DATE '2020-01-01' AS d"));
        $this->assertSame('13:45:00', $this->scalar("SELECT TIME '13:45:00' AS t"));
        $this->assertSame(
            '2020-01-01 13:45:00',
            $this->scalar("SELECT TIMESTAMP '2020-01-01 13:45:00' AS ts")
        );
    }

    public function testExtractReturnsIntegersNotZeroPaddedText(): void
    {
        // sqlite3: SELECT CAST(strftime('%Y','2020-05-06 13:45:07') AS INTEGER), ...
        //   -> 2020|5|6|13|45|7
        $rows = $this->rows(
            "SELECT EXTRACT(YEAR FROM TIMESTAMP '2020-05-06 13:45:07') AS y,
                    EXTRACT(MONTH FROM TIMESTAMP '2020-05-06 13:45:07') AS mo,
                    EXTRACT(DAY FROM TIMESTAMP '2020-05-06 13:45:07') AS d,
                    EXTRACT(HOUR FROM TIMESTAMP '2020-05-06 13:45:07') AS h,
                    EXTRACT(MINUTE FROM TIMESTAMP '2020-05-06 13:45:07') AS mi,
                    EXTRACT(SECOND FROM TIMESTAMP '2020-05-06 13:45:07') AS s"
        );

        $this->assertCount(1, $rows);
        $this->assertSame(2020, $rows[0]->y);
        $this->assertSame(5, $rows[0]->mo, 'MONTH must be 5, not the string "05"');
        $this->assertSame(6, $rows[0]->d);
        $this->assertSame(13, $rows[0]->h);
        $this->assertSame(45, $rows[0]->mi);
        $this->assertSame(7, $rows[0]->s, 'SECOND must be 7, not the string "07"');
    }

    public function testExtractOnDateAndTimeOnlySources(): void
    {
        $this->assertSame(2020, $this->scalar("SELECT EXTRACT(YEAR FROM DATE '2020-05-06') AS y"));

        // sqlite3: SELECT CAST(strftime('%H','2020-05-06') AS INTEGER) -> 0
        $this->assertSame(0, $this->scalar("SELECT EXTRACT(HOUR FROM DATE '2020-05-06') AS h"));

        $this->assertSame(13, $this->scalar("SELECT EXTRACT(HOUR FROM TIME '13:45:07') AS h"));
        $this->assertSame(45, $this->scalar("SELECT EXTRACT(MINUTE FROM TIME '13:45:07') AS m"));
    }

    public function testExtractSecondKeepsAFractionWhenTheSourceHasOne(): void
    {
        $this->assertSame(7.25, $this->scalar("SELECT EXTRACT(SECOND FROM TIMESTAMP '2020-05-06 13:45:07.25') AS s"));
    }

    public function testExtractSecondTypeComesFromTheValueNotTheSpelling(): void
    {
        // '07', '07.0' and '07.00' are one second, so they must be one number.
        // Taking the type from the text instead yields int 7 for the first and
        // float 7.0 for the other two, which are distinct GROUP BY and
        // DISTINCT keys while still comparing equal in WHERE.
        foreach (['07', '07.0', '07.00', '07.000000'] as $spelling) {
            $this->assertSame(
                7,
                $this->scalar("SELECT EXTRACT(SECOND FROM TIMESTAMP '2020-01-01 00:00:$spelling') AS s"),
                "seconds spelled '$spelling'"
            );
        }

        // A real fraction still survives - SECOND is an exact numeric.
        $this->assertSame(7.5, $this->scalar("SELECT EXTRACT(SECOND FROM TIMESTAMP '2020-01-01 00:00:07.5') AS s"));
    }

    public function testExtractSecondGroupsEqualInstantsTogether(): void
    {
        // sqlite3 over the same four rows, using the fractional-seconds view
        // that matches the standard's exact numeric result:
        //   SELECT strftime('%f',ts), COUNT(*) FROM m GROUP BY 1 ORDER BY 1
        //   -> 07.000|3, 07.500|1
        $rows = $this->rows(
            'SELECT EXTRACT(SECOND FROM ts) AS s, COUNT(*) AS n
             FROM moments GROUP BY EXTRACT(SECOND FROM ts) ORDER BY s'
        );
        $this->assertCount(2, $rows, 'equal instants must collapse into one group');
        $this->assertSame(7, $rows[0]->s);
        $this->assertSame(3, $rows[0]->n);
        $this->assertSame(7.5, $rows[1]->s);
        $this->assertSame(1, $rows[1]->n);

        $distinct = $this->rows('SELECT DISTINCT EXTRACT(SECOND FROM ts) AS s FROM moments ORDER BY s');
        $this->assertCount(2, $distinct);

        // The point of the whole test: WHERE and GROUP BY have to agree about
        // which rows are the same second. Three rows match `= 7`, and those
        // three are exactly the first group above.
        $matched = $this->rows('SELECT id FROM moments WHERE EXTRACT(SECOND FROM ts) = 7');
        $this->assertCount(3, $matched);
    }

    public function testExtractRejectsStoredTextThatIsNotADatetime(): void
    {
        // Shape alone is not meaning. Every one of these is a string SQLite
        // refuses to read too - strftime('%m','2020-13-45'),
        // strftime('%S','2020-01-01 00:00:99') and strftime('%H','25:99:99')
        // are all NULL - and the parser already rejects the identical text
        // written as a literal. Reading it out of a column must not be the one
        // path where month 13 gets through.
        foreach ([
            '2020-13-45' => 'MONTH',
            '2020-01-01 00:00:99' => 'SECOND',
            '25:99:99' => 'HOUR',
            // Stricter than SQLite on purpose: SQLite normalises 2019-02-30 to
            // 2019-03-02 and accepts year 0000, which the standard's DATE
            // range starts after. Guessing is the thing being avoided.
            '0000-01-01' => 'YEAR',
            '2019-02-30' => 'DAY',
        ] as $text => $field) {
            $this->assertThrows(
                fn() => $this->rows("SELECT EXTRACT($field FROM ts) AS v FROM junk WHERE ts = '$text'"),
                \RuntimeException::class,
                "EXTRACT($field FROM '$text') must not answer"
            );
        }
    }

    public function testTheParserAndTheEvaluatorAgreeAboutValidity(): void
    {
        // One rule set, two entry points. If these ever diverge, the engine is
        // strict about text it can see at parse time and credulous about the
        // same text arriving from a federated table.
        // Note: never pass \Throwable::class to assertThrows - the harness
        // catches its own AssertionError, so that spelling can never fail.
        foreach (['2020-13-45', '0000-01-01', '2019-02-30'] as $text) {
            $this->assertThrows(
                fn() => $this->rows("SELECT DATE '$text' AS d"),
                SqlSyntaxException::class,
                "parser accepted DATE '$text'"
            );
            $this->assertThrows(
                fn() => $this->rows("SELECT EXTRACT(YEAR FROM ts) AS v FROM junk WHERE ts = '$text'"),
                \RuntimeException::class,
                "evaluator accepted '$text' out of a column"
            );
        }
    }

    public function testExtractOfNullIsNull(): void
    {
        $this->assertNull($this->scalar('SELECT EXTRACT(YEAR FROM NULL) AS y'));
        $this->assertNull($this->scalar("SELECT EXTRACT(YEAR FROM NULLIF('a', 'a')) AS y"));
    }

    public function testExtractRejectsAValueThatHasNoSuchField(): void
    {
        // A time has no year. With no date type to catch this at compile time,
        // the honest answer is to fail rather than invent a year.
        $this->assertThrows(
            fn() => $this->rows("SELECT EXTRACT(YEAR FROM TIME '13:45:00') AS y"),
            \RuntimeException::class
        );

        $this->assertThrows(
            fn() => $this->rows("SELECT EXTRACT(YEAR FROM 'banana') AS y"),
            \RuntimeException::class
        );
    }

    public function testTypedLiteralComparesAgainstStoredText(): void
    {
        // Row $i is 2020-01-01 + $i days + $i hours, so ids 1..3 are before
        // 2020-01-05 and id 4 (2020-01-05 04:00:00) is not.
        $rows = $this->rows(
            "SELECT id FROM timestamps WHERE created_at < TIMESTAMP '2020-01-05 00:00:00' ORDER BY id"
        );
        $this->assertSame([1, 2, 3], array_column($rows, 'id'));
    }

    public function testExtractInWhere(): void
    {
        // sqlite3 equivalent: WHERE CAST(strftime('%m',created_at) AS INTEGER) = 2
        // -> 11 rows for ids 1..40 (2020-02-01 .. 2020-02-11)
        $rows = $this->rows('SELECT id FROM timestamps WHERE EXTRACT(MONTH FROM created_at) = 2 ORDER BY id');
        $this->assertCount(11, $rows);
        $this->assertSame(30, $rows[0]->id);
        $this->assertSame(40, $rows[10]->id);
    }

    public function testExtractInGroupBy(): void
    {
        $rows = $this->rows(
            'SELECT EXTRACT(MONTH FROM created_at) AS m, COUNT(*) AS n
             FROM timestamps GROUP BY EXTRACT(MONTH FROM created_at) ORDER BY m'
        );

        // sqlite3: SELECT CAST(strftime('%m',created_at) AS INTEGER) m, COUNT(*)
        //          FROM ts GROUP BY 1 ORDER BY 1  -> 1|29, 2|11
        $this->assertCount(2, $rows);
        $this->assertSame(1, $rows[0]->m);
        $this->assertSame(29, $rows[0]->n);
        $this->assertSame(2, $rows[1]->m);
        $this->assertSame(11, $rows[1]->n);
    }

    public function testExtractInOrderBy(): void
    {
        // Ordering by day of month, not by the stored string, so the three
        // highest days (31st, 30th, 29th of January) come first even though
        // February rows sort later as text.
        //
        // sqlite3: SELECT id, CAST(strftime('%d',created_at) AS INTEGER) d FROM ts
        //          ORDER BY 2 DESC, id LIMIT 3   ->  29|31, 28|30, 27|29
        $rows = $this->rows(
            'SELECT id, created_at FROM timestamps ORDER BY EXTRACT(DAY FROM created_at) DESC, id LIMIT 3'
        );
        $this->assertSame([29, 28, 27], array_column($rows, 'id'));
        $this->assertSame(
            [31, 30, 29],
            array_map(fn($r) => (int)substr($r->created_at, 8, 2), $rows)
        );
    }

    public function testTypedLiteralInOrderByAndGroupByPositions(): void
    {
        // A constant is a legal (if pointless) sort key and grouping key; what
        // matters is that the typed literal is accepted wherever an expression
        // is, not just in a SELECT list.
        $rows = $this->rows(
            "SELECT COUNT(*) AS n FROM timestamps GROUP BY DATE '2020-01-01' ORDER BY DATE '2020-01-01'"
        );
        $this->assertCount(1, $rows);
        $this->assertSame(40, $rows[0]->n);
    }

    public function testExtractComposesWithOtherExpressions(): void
    {
        $this->assertSame(
            2021,
            $this->scalar("SELECT EXTRACT(YEAR FROM DATE '2020-05-06') + 1 AS y")
        );
        $this->assertSame(
            'first half',
            $this->scalar(
                "SELECT CASE WHEN EXTRACT(MONTH FROM DATE '2020-05-06') <= 6
                        THEN 'first half' ELSE 'second half' END AS half"
            )
        );
    }

    public function testExistingSubstrSpellingStillWorks(): void
    {
        // Guard against the datetime grammar disturbing its neighbours
        $this->assertSame('2020-01', $this->scalar("SELECT SUBSTR('2020-01-02 01:00:00', 1, 7) AS m"));
        $this->assertSame(1, $this->scalar('SELECT MIN(id) AS m FROM timestamps'));
    }
};

exit($test->run());
