<?php

namespace mini\Parsing\SQL;

/**
 * The single definition of what a datetime *text* is, for this engine.
 *
 * Mini has no date type. Datetimes are stored as text in the canonical
 * `Y-m-d`, `H:i:s` and `Y-m-d H:i:s` shapes, which sort lexicographically, so
 * comparison, ordering and grouping all work on the strings directly. That
 * makes a typed literal (`DATE '2020-01-01'`, SQL:2003 F051-01/02/03) an
 * *assertion about the format* rather than a conversion, and it makes
 * `EXTRACT(field FROM source)` (F052) a read of fields out of that text.
 *
 * Both live here rather than in their two call sites because they must agree.
 * A parser that rejects `DATE '2020-13-45'` while the evaluator happily
 * reports month 13 for the same characters arriving from a column is worse
 * than either behaviour alone: federated CSV/JSON/API rows are exactly where
 * malformed text comes from, and that is precisely the path that would have
 * skipped the check.
 *
 * The rules are stricter than SQLite's, deliberately (see CLAUDE.md, "fail
 * fast, be strict"): SQLite silently normalises `2019-02-30` to `2019-03-02`
 * and accepts hour 24, and it accepts year 0000, which the SQL standard's DATE
 * range (0001-01-01 .. 9999-12-31) excludes. Mini rejects all three rather
 * than answer a question the caller did not ask. Where SQLite returns NULL for
 * nonsense (`strftime('%m','2020-13-45')`), Mini throws, which is the same
 * "don't guess" stance one step louder.
 */
final class DatetimeText
{
    /** Human-readable format per literal keyword, used in error messages */
    public const SHAPES = [
        'DATE' => 'YYYY-MM-DD',
        'TIME' => 'HH:MM:SS[.fff]',
        'TIMESTAMP' => 'YYYY-MM-DD HH:MM:SS[.fff]',
    ];

    /**
     * Validate datetime text and split it into EXTRACT's fields.
     *
     * A DATE has an hour, minute and second of zero — the same answer SQLite
     * gives for `strftime('%H','2020-05-06')`. A TIME has no date fields at
     * all, so YEAR/MONTH/DAY come back as null and asking for one is an error
     * the caller has to raise; there is no year to invent.
     *
     * @param 'DATE'|'TIME'|'TIMESTAMP'|null $require
     *        Which shape the caller demands. Null accepts any of the three,
     *        which is what EXTRACT wants.
     * @return array{kind: string, YEAR: ?int, MONTH: ?int, DAY: ?int, HOUR: int, MINUTE: int, SECOND: int|float}
     * @throws \InvalidArgumentException with a message that reads as the tail
     *         of the caller's own sentence, so each layer can prefix its own
     *         context ("DATE literal ...", "EXTRACT(YEAR FROM ...) ...").
     */
    public static function parse(string $text, ?string $require = null): array
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text, $m)) {
            $kind = 'DATE';
            $parts = [
                'YEAR' => (int)$m[1], 'MONTH' => (int)$m[2], 'DAY' => (int)$m[3],
                'HOUR' => 0, 'MINUTE' => 0, 'SECOND' => 0,
            ];
        } elseif (preg_match('/^(\d{2}):(\d{2}):(\d{2})(\.\d+)?$/', $text, $m)) {
            $kind = 'TIME';
            $parts = [
                'YEAR' => null, 'MONTH' => null, 'DAY' => null,
                'HOUR' => (int)$m[1], 'MINUTE' => (int)$m[2],
                'SECOND' => self::seconds($m[3], $m[4] ?? ''),
            ];
        } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})(\.\d+)?$/', $text, $m)) {
            $kind = 'TIMESTAMP';
            $parts = [
                'YEAR' => (int)$m[1], 'MONTH' => (int)$m[2], 'DAY' => (int)$m[3],
                'HOUR' => (int)$m[4], 'MINUTE' => (int)$m[5],
                'SECOND' => self::seconds($m[6], $m[7] ?? ''),
            ];
        } else {
            throw new \InvalidArgumentException(self::shapeMessage($text, $require));
        }

        if ($require !== null && $kind !== $require) {
            throw new \InvalidArgumentException(self::shapeMessage($text, $require));
        }

        // The patterns fix the shape; these fix the meaning.
        if ($parts['YEAR'] !== null && !checkdate($parts['MONTH'], $parts['DAY'], $parts['YEAR'])) {
            throw new \InvalidArgumentException("'$text' is not a date on the calendar");
        }

        if ($parts['HOUR'] > 23 || $parts['MINUTE'] > 59 || $parts['SECOND'] >= 60) {
            throw new \InvalidArgumentException("'$text' has a time field out of range");
        }

        return ['kind' => $kind] + $parts;
    }

    /**
     * Seconds as an exact numeric, normalised so that equal instants are equal
     * *values*.
     *
     * `00:00:07`, `00:00:07.0` and `00:00:07.00` are the same second, so they
     * must produce one number, not an int and two floats. Deriving the type
     * from the text instead splits GROUP BY and DISTINCT keys (7 and 7.0 are
     * distinct array keys / distinct values) while `= 7` still matches all
     * three — the same pair equal in WHERE and unequal in GROUP BY, which is a
     * silently wrong aggregate.
     */
    private static function seconds(string $whole, string $fraction): int|float
    {
        if ($fraction === '') {
            return (int)$whole;
        }

        $value = (float)($whole . $fraction);

        return $value == (int)$value ? (int)$value : $value;
    }

    private static function shapeMessage(string $text, ?string $require): string
    {
        $shape = $require !== null
            ? "'" . self::SHAPES[$require] . "'"
            : "'" . implode("', '", array_slice(self::SHAPES, 0, 2)) . "' or '" . self::SHAPES['TIMESTAMP'] . "'";

        return "must be $shape, got '$text'";
    }
}
