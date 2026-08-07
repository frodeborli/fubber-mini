# VirtualDatabase — Supported SQL

VirtualDatabase runs a **practical subset of SQL**, not standard-conformant
SQL. It is not a SQL:2003 implementation and does not aim to become one.

Read that as a deliberate scope, not an apology. The engine exists to run
*sensible* SQL over heterogeneous sources from PHP — a CSV file, a JSON
document, a remote API and a database table joined in one query. It is not
built to be pushed to the edge, and it is not a general-purpose RDBMS: for a
workload that needs window frames, `MERGE` and `GROUPING SETS`, use a database
that has them, and register it as a source.

Where a construct has both a standard and a SQLite spelling, **both are
accepted and mean the same thing** — `SUBSTRING(x FROM y FOR z)` and
`SUBSTR(x, y, z)`, `CHARACTER_LENGTH(x)` and `LENGTH(x)`, `POSITION(x IN y)`
and `INSTR(y, x)`, `MOD(a, b)` and `a % b`. SQLite remains this engine's
reference implementation for *semantics* — the test suite differentially
cross-checks against `sqlite3` — so where the standard leaves a corner to the
implementation (`CAST` of a non-numeric string, string indexing from 1), the
engine answers the way SQLite does.

Scalar functions are **pluggable**: the built-ins below are registered through
the same public `createFunction()` API available to you, so an unsupported
function is usually a few lines of PHP away rather than a wall. Aggregates
likewise via `createAggregate()`. See `src/Database/Virtual/README.md`.

The engine also enforces deliberate limits (joined tables per query, recursion
depth, buffered writes) so that a runaway query fails with an actionable error
instead of consuming the process. See `mini\Database\Limits`.

This file catalogues the **query language**. Statements that change data —
`INSERT`, `UPDATE`, `DELETE`, `CREATE TABLE`, `CREATE INDEX`, `DROP TABLE`,
with `IF EXISTS` / `IF NOT EXISTS` — are parsed and executed, but only through
`VirtualDatabase::exec()`; `query()` accepts `SELECT` (and `VALUES`, and
`WITH`) only, and rejects anything else rather than running it for its side
effects.

## Working

```sql
-- Basic SELECT
SELECT * FROM users
SELECT name, email FROM users
SELECT name AS username FROM users
SELECT DISTINCT category FROM products
SELECT price * 2 AS double_price FROM products
SELECT users.name FROM users
SELECT * FROM users u WHERE u.active = 1
SELECT 1 AS n                              -- no FROM clause
VALUES (1, 'a'), (2, 'b')                  -- table value constructor as a query

-- WHERE operators
SELECT * FROM users WHERE active = 1
SELECT * FROM products WHERE price > 10
SELECT * FROM products WHERE price <> 9.99
SELECT * FROM products WHERE price != 9.99
SELECT * FROM products WHERE price >= 10 AND stock < 100
SELECT * FROM users WHERE role = 'admin' OR active = 0
SELECT * FROM products WHERE name = 'Widget' AND (price < 10 OR stock > 50)
SELECT * FROM products WHERE NOT price > 10
SELECT * FROM products WHERE category IN ('gadgets', 'tools')
SELECT * FROM products WHERE category NOT IN ('gadgets')
SELECT * FROM products WHERE price BETWEEN 10 AND 20
SELECT * FROM products WHERE price NOT BETWEEN 10 AND 20
SELECT * FROM users WHERE name LIKE 'A%'
SELECT * FROM users WHERE name NOT LIKE 'A%'
SELECT * FROM users WHERE name LIKE 'A!%%' ESCAPE '!'   -- SQL:2003 E061-05
SELECT * FROM products WHERE stock IS NULL
SELECT * FROM products WHERE stock IS NOT NULL

-- IS [NOT] DISTINCT FROM (SQL:2003 T151) - null-safe comparison, never UNKNOWN
SELECT * FROM contacts WHERE email IS DISTINCT FROM notes
SELECT * FROM contacts WHERE email IS NOT DISTINCT FROM notes

-- Row value constructors (SQL:2003 F641) - comparison and IN
SELECT * FROM orders WHERE (user_id, product_id) = (1, 1)
SELECT * FROM orders WHERE (user_id, product_id) < (2, 1)
SELECT * FROM orders WHERE (user_id, product_id) IN ((1, 1), (2, 2))
--
-- A row value is only an operand of a comparison or IN. `SELECT (1, 2)` and
-- comparing a row against a scalar are both errors, not coercions. The
-- degree of both sides must match.

-- Aggregates (whole table)
SELECT COUNT(*) FROM users
SELECT COUNT(DISTINCT category) FROM products
SELECT SUM(price), AVG(price), MIN(price), MAX(price) FROM products

-- GROUP BY / HAVING
SELECT category, COUNT(*) FROM products GROUP BY category
SELECT category, SUM(price) AS total FROM products GROUP BY category ORDER BY total DESC
SELECT role, COUNT(*) AS cnt FROM users GROUP BY role HAVING cnt > 1
SELECT user_id, status, COUNT(*) FROM orders GROUP BY user_id, status

-- ORDER BY / LIMIT
SELECT * FROM products ORDER BY price DESC
SELECT * FROM products ORDER BY category, price DESC
SELECT * FROM products ORDER BY price * stock DESC  -- expressions
SELECT price * stock AS value FROM products ORDER BY value   -- aliases
SELECT id, name FROM users ORDER BY 2              -- select-list ordinals
SELECT * FROM contacts ORDER BY notes NULLS LAST   -- SQL:2003 F855
SELECT * FROM contacts ORDER BY notes DESC NULLS FIRST
--
-- Without a NULLS clause, NULL sorts below every value - first ascending,
-- last descending, the same choice SQLite makes. The explicit clause
-- overrides that absolutely: NULLS FIRST is not flipped by DESC.
--
-- One restriction: a set operation (UNION/INTERSECT/EXCEPT) sorts through the
-- table backend, whose order spec cannot express null ordering, so
-- `... UNION ... ORDER BY x NULLS LAST` is a fail-fast error rather than a
-- sort that quietly ignores the clause. Wrap it in a derived table.
SELECT * FROM products LIMIT 2
SELECT * FROM products LIMIT 2 OFFSET 1
SELECT * FROM products ORDER BY price DESC LIMIT 2
SELECT * FROM products FETCH FIRST 2 ROWS ONLY              -- SQL:2008 F856/F857
SELECT * FROM products OFFSET 1 ROWS FETCH NEXT 2 ROWS ONLY

-- JOINs (all types)
SELECT * FROM users u CROSS JOIN products p
SELECT u.name, o.total FROM users u INNER JOIN orders o ON u.id = o.user_id
SELECT u.name, o.total FROM users u LEFT JOIN orders o ON u.id = o.user_id
SELECT u.name, o.total FROM users u RIGHT JOIN orders o ON u.id = o.user_id
SELECT u.name, o.total FROM users u FULL JOIN orders o ON u.id = o.user_id
SELECT u.name, p.name, o.qty FROM users u JOIN orders o ON ... JOIN products p ON ...
-- The OUTER keyword is optional and accepted: LEFT/RIGHT/FULL OUTER JOIN.

-- Named and implicit join columns (SQL:2003 7.7)
SELECT * FROM emp JOIN dept USING (dept_id)        -- dept_id exposed once
SELECT * FROM emp LEFT JOIN dept USING (dept_id)   -- and coalesced across the join
SELECT * FROM emp NATURAL JOIN dept                -- USING every common column
SELECT * FROM emp NATURAL LEFT JOIN dept
--
-- Three deliberate deviations, all fail-fast:
--
-- 1. NATURAL JOIN with no columns in common is an error, not a cross join.
--    The join keys of a NATURAL JOIN are invisible in the query text, so a
--    silent cartesian product arrives with no syntactic warning at all.
--    Write CROSS JOIN when a cross join is what you mean.
--
-- 2. A merged join column has no qualified spelling. It is exposed once,
--    under the bare column name, and its value is the coalesce of both
--    operands - which on an unmatched row of an outer join is neither
--    operand's own value. SQLite and PostgreSQL keep `emp.dept_id` and
--    `dept.dept_id` addressable alongside it; this engine builds join rows
--    out of qualified column names and cannot carry a column that `SELECT *`
--    must not show, so it rejects those references in every clause rather
--    than answering with the coalesced value:
--
--      SELECT dept.dept_id FROM emp LEFT JOIN dept USING (dept_id)
--      -- Column 'dept.dept_id' is not available after a NATURAL/USING join
--
--    The ban is on the *name*, whatever qualifier precedes it, so a third
--    table with a column of the same name is unreachable after the merge too.
--
--    Columns that were not merged keep their qualified names, so the
--    unmatched-row idiom is still expressible on any other column of the
--    null-extended side:
--
--      SELECT emp.emp_id, dept.dept IS NULL AS unmatched
--      FROM emp LEFT JOIN dept USING (dept_id)
--
-- 3. CROSS JOIN takes no join specification. SQL:2003 gives <cross join> no
--    <join specification>, and SQLite accepts one only because it treats
--    CROSS JOIN as an inner join with a planner hint. Accepting `CROSS JOIN b
--    ON ...` here would mean ignoring the clause and answering a cartesian
--    product to a query that asked for a join, so both spellings are a parse
--    error: write JOIN ... ON or JOIN ... USING.

-- Subqueries (all forms)
SELECT * FROM users WHERE id IN (SELECT user_id FROM orders)
SELECT * FROM users WHERE id NOT IN (SELECT user_id FROM orders)
SELECT * FROM users WHERE EXISTS (SELECT 1 FROM orders WHERE orders.user_id = users.id)
SELECT * FROM products WHERE NOT EXISTS (SELECT 1 FROM orders WHERE ...)
SELECT * FROM products WHERE price > ALL (SELECT price FROM products WHERE category = 'tools')
SELECT * FROM products WHERE price > ANY (SELECT price FROM products WHERE category = 'tools')
SELECT * FROM users WHERE id = SOME (SELECT user_id FROM orders)   -- SOME = ANY

-- Scalar subqueries
SELECT * FROM products WHERE price > (SELECT AVG(price) FROM products)
SELECT (SELECT MAX(price) FROM products) AS max_price
SELECT *, (SELECT COUNT(*) FROM orders WHERE orders.user_id = users.id) FROM users

-- Derived tables
SELECT * FROM (SELECT * FROM products) AS sub
SELECT u.name, o.total FROM users u JOIN (SELECT user_id, SUM(total) AS total FROM orders GROUP BY user_id) o ON u.id = o.user_id
SELECT * FROM (VALUES (1, 'a'), (2, 'b')) AS v(n, s)   -- VALUES as a table
SELECT * FROM (SELECT id, name FROM users) AS t(a, b)  -- derived column list
-- A derived column list must name every column exactly once; a duplicate name
-- would make the earlier column unreachable, so it is rejected at parse time.

-- Nested subqueries (3+ levels)
SELECT * FROM users WHERE id IN (SELECT user_id FROM orders WHERE id IN (SELECT order_id FROM order_items WHERE product_id = 101))

-- CASE WHEN
SELECT CASE WHEN price > 10 THEN 'expensive' ELSE 'cheap' END FROM products
SELECT CASE role WHEN 'admin' THEN 'Administrator' ELSE 'User' END FROM users
SELECT name FROM products WHERE CASE WHEN price < 10 THEN 'cheap' ELSE 'expensive' END = 'cheap'

-- CAST (SQL:2003 E021-... / F201)
-- Type names are matched by affinity the way SQLite does it, so a length or
-- precision is accepted and ignored, and a type name with no numeric or
-- character affinity (DATE, BOOLEAN) lands on NUMERIC. Conversion follows
-- SQLite: CAST('12abc' AS INTEGER) is 12, CAST('abc' AS INTEGER) is 0,
-- CAST(1.7 AS INTEGER) truncates towards zero, NULL casts to NULL.
SELECT CAST('12' AS INTEGER), CAST(12.7 AS INT)
SELECT CAST(price AS VARCHAR(20)), CAST(price AS TEXT) FROM products
SELECT CAST('12.34' AS DECIMAL(10, 2)), CAST('12.5' AS REAL)
SELECT * FROM products WHERE CAST(price AS INTEGER) = 9

-- String functions
SELECT UPPER(name), LOWER(name), LENGTH(name) FROM users
SELECT CHAR_LENGTH(name), CHARACTER_LENGTH(name), OCTET_LENGTH(name) FROM users
SELECT CONCAT(name, ' - ', email) FROM users
SELECT SUBSTR(name, 1, 3), SUBSTRING(name, 1, 3) FROM products
SELECT SUBSTRING(name FROM 2 FOR 3), SUBSTRING(name FROM 2) FROM products
SELECT TRIM('  hello  '), LTRIM(name), RTRIM(name) FROM products
SELECT TRIM(BOTH ' ' FROM name), TRIM(LEADING 'x' FROM name), TRIM(TRAILING 'x' FROM name) FROM products
SELECT POSITION('@' IN email), INSTR(email, '@') FROM users
SELECT REPLACE(email, '@old.com', '@new.com') FROM users
SELECT REPEAT(name, 2), REVERSE(name), LPAD(name, 10, '.'), RPAD(name, 10, '.') FROM users
--
-- One caveat: LENGTH, CHAR_LENGTH and CHARACTER_LENGTH count *bytes*, not
-- characters, so on non-ASCII text they agree with OCTET_LENGTH rather than
-- with the standard's character count.

-- Numeric functions
SELECT ABS(-5), ROUND(price, 0), CEIL(price), CEILING(price), FLOOR(price) FROM products
SELECT MOD(7, 3), SIGN(-4), POWER(2, 10), POW(2, 3), SQRT(16), EXP(1), LN(1)

-- Null handling
SELECT COALESCE(NULL, name), IFNULL(NULL, 'default'), NULLIF(1, 1) FROM users

-- String concatenation and modulo
SELECT name || ' - ' || email FROM users
SELECT id % 2 AS is_odd FROM users

-- Typed datetime literals (SQL:2003 F051-01/02/03)
-- Datetimes are stored as TEXT; a typed literal asserts the format and
-- evaluates to the string. Malformed or impossible values are rejected at
-- parse time (DATE '2020-02-30' and TIME '25:00:00' are errors, not guesses).
SELECT DATE '2020-01-01', TIME '13:45:00', TIMESTAMP '2020-01-01 13:45:00'
SELECT * FROM events WHERE created_at < TIMESTAMP '2020-01-05 00:00:00'

-- Current datetime (SQL:2003 F051-04/05/06)
SELECT CURRENT_DATE, CURRENT_TIME, CURRENT_TIMESTAMP

-- EXTRACT (SQL:2003 F052) - YEAR, MONTH, DAY, HOUR, MINUTE, SECOND
-- Works in SELECT, WHERE, GROUP BY and ORDER BY. Returns integers, not
-- zero-padded text; SECOND is exact numeric and keeps a non-zero fraction.
-- Any other field (TIMEZONE_HOUR, WEEK, ...) is a fail-fast error.
-- Evaluated by VirtualDatabase only: it is rendered verbatim, and SQLite and
-- friends reject the standard EXTRACT(field FROM x) spelling.
SELECT EXTRACT(YEAR FROM created_at) FROM events
SELECT EXTRACT(MONTH FROM created_at) AS m, COUNT(*) FROM events GROUP BY EXTRACT(MONTH FROM created_at)

-- UNION / INTERSECT / EXCEPT
SELECT name FROM users UNION SELECT name FROM products
SELECT 1 AS n UNION ALL SELECT 2 AS n
SELECT id FROM users INTERSECT SELECT user_id FROM orders
SELECT id FROM users EXCEPT SELECT user_id FROM orders

-- Window functions (SQL:2003)
SELECT name, ROW_NUMBER() OVER (ORDER BY id) AS rn FROM users
SELECT name, RANK() OVER (PARTITION BY category ORDER BY price DESC) AS rank FROM products
SELECT name, DENSE_RANK() OVER (ORDER BY role) AS dr FROM users
SELECT name, ROW_NUMBER() OVER (ORDER BY email NULLS LAST) AS rn FROM users
--
-- A window ORDER BY takes the same null ordering as a statement ORDER BY,
-- and orders NULLs the same way by default (below every value).

-- CTEs (Common Table Expressions) - SQL:2003
WITH active_users AS (SELECT * FROM users WHERE active = 1) SELECT * FROM active_users
WITH cte1 AS (...), cte2 AS (SELECT * FROM cte1 WHERE ...) SELECT * FROM cte2  -- chained CTEs
WITH v AS (VALUES (1), (2)) SELECT * FROM v
WITH RECURSIVE nums AS (SELECT 1 AS n UNION ALL SELECT n + 1 FROM nums WHERE n < 10) SELECT * FROM nums
```

## Not Supported

Verified by probing the engine (2026-08-07), and re-verified on every test
run: `tests/Database/VirtualDatabase.StatusDocument.php` executes every entry
below and fails the build if one of them succeeds. Each fails with a parse
error, an "unknown function" error, or an explicit fail-fast refusal — **none
of them silently returns a wrong answer**, which is the property this section
exists to guarantee.

**Standard spellings of things that work under another name**

```sql
SELECT OVERLAY(x PLACING 'ab' FROM 2 FOR 2) FROM t   -- use SUBSTR(...) || ... || SUBSTR(...)
SELECT * FROM t WHERE x IS TRUE                      -- use x = 1
SELECT * FROM t WHERE x IS NOT FALSE                 -- use x <> 0 OR x IS NULL
SELECT * FROM t WHERE x IS UNKNOWN                   -- use x IS NULL
SELECT GREATEST(x, y), LEAST(x, y) FROM t            -- use CASE WHEN, or createFunction()
SELECT * FROM t WHERE x BETWEEN SYMMETRIC 3 AND 1    -- order the bounds yourself
```

**Not implemented**

```sql
-- Expressions and predicates
SELECT * FROM t WHERE x SIMILAR TO 'a%'
SELECT d + INTERVAL '1' DAY FROM t             -- no INTERVAL type or arithmetic
SELECT ARRAY[1, 2]                             -- no collection types
SELECT CURRENT_USER                            -- no session/user context
SELECT * FROM t ORDER BY x COLLATE NOCASE      -- no COLLATE clause

-- Joins and set operations
SELECT x FROM t UNION CORRESPONDING SELECT x FROM t
SELECT * FROM t CROSS JOIN t s ON t.x = s.x    -- a CROSS JOIN takes no join
SELECT * FROM t CROSS JOIN t s USING (x)       -- specification; write JOIN
SELECT t.x FROM t JOIN t s USING (x)           -- a merged column has no
                                               -- qualified spelling
SELECT x FROM t UNION SELECT x FROM t ORDER BY x NULLS LAST

-- Grouping and aggregate extensions
SELECT x, COUNT(*) FROM t GROUP BY ROLLUP(x)
SELECT x, COUNT(*) FROM t GROUP BY CUBE(x)
SELECT x, COUNT(*) FROM t GROUP BY GROUPING SETS ((x), ())
SELECT COUNT(*) FILTER (WHERE x > 10) FROM t
SELECT GROUP_CONCAT(x) FROM t                  -- also STRING_AGG, LISTAGG
SELECT DISTINCT ON (x) y FROM t

-- Window functions beyond the core four
SELECT SUM(x) OVER (ORDER BY y) FROM t         -- aggregates as window functions
SELECT LAG(x) OVER (ORDER BY y) FROM t         -- also LEAD, NTILE, FIRST_VALUE,
SELECT NTILE(2) OVER (ORDER BY y) FROM t       -- LAST_VALUE, PERCENT_RANK, CUME_DIST
SELECT SUM(x) OVER (ORDER BY y ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) FROM t
SELECT ROW_NUMBER() OVER w FROM t WINDOW w AS (ORDER BY x)   -- named windows

-- Statements and clauses
MERGE INTO t USING t AS s ON t.x = s.x WHEN MATCHED THEN UPDATE SET y = 1
SELECT * FROM t, LATERAL (SELECT 1) l
SELECT * FROM t FOR UPDATE
ALTER TABLE t ADD COLUMN z INT
TRUNCATE TABLE t
INSERT INTO t DEFAULT VALUES
INSERT INTO t (x) VALUES (1) ON CONFLICT (x) DO NOTHING

-- Lexing
SELECT * FROM t WHERE x = "Widget"             -- double quotes are identifiers,
                                               -- not strings; use 'Widget'
```

`ROW_NUMBER`, `RANK` and `DENSE_RANK` with `PARTITION BY` / `ORDER BY` are
supported; window *frames* are not, so those three are the useful set.

## Notes

Keep this file honest. It is cited as the coverage authority by
`src/Database/Virtual/README.md`, and an evaluator who hits an undocumented gap
in the first five minutes will not trust anything else the docs claim. If you
add a feature, move its line from "Not Supported" to "Working" in the same
change — and add a test.

`tests/Database/VirtualDatabase.StatusDocument.php` enforces this
mechanically: it executes every entry in the "Not Supported" blocks and fails
if one of them succeeds, and parses every executable line of "Working" and
fails if one of them stops parsing. A feature landed without a doc move breaks
the build.
