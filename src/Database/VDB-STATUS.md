# VirtualDatabase — Supported SQL

VirtualDatabase runs a **practical subset of SQL with SQLite-compatible
spellings**, not standard-conformant SQL. It is not a SQL:2003 implementation
and does not aim to become one.

Read that as a deliberate scope, not an apology. The engine exists to run
*sensible* SQL over heterogeneous sources from PHP — a CSV file, a JSON
document, a remote API and a database table joined in one query. It is not
built to be pushed to the edge, and it is not a general-purpose RDBMS: for a
workload that needs window frames, `MERGE` and `GROUPING SETS`, use a database
that has them, and register it as a source.

Where a construct exists in both standard and SQLite spelling, **the SQLite
spelling is the supported one** — `SUBSTR(x, y, z)`, not
`SUBSTRING(x FROM y FOR z)`. SQLite is this engine's reference implementation
and the test suite differentially cross-checks against `sqlite3`.

Scalar functions are **pluggable**: the built-ins below are registered through
the same public `createFunction()` API available to you, so an unsupported
function is usually a few lines of PHP away rather than a wall. Aggregates
likewise via `createAggregate()`. See `src/Database/Virtual/README.md`.

The engine also enforces deliberate limits (joined tables per query, recursion
depth, buffered writes) so that a runaway query fails with an actionable error
instead of consuming the process. See `mini\Database\Limits`.

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
SELECT * FROM products WHERE stock IS NULL
SELECT * FROM products WHERE stock IS NOT NULL

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
SELECT * FROM products ORDER BY value              -- aliases
SELECT * FROM products LIMIT 2
SELECT * FROM products LIMIT 2 OFFSET 1
SELECT * FROM products ORDER BY price DESC LIMIT 2

-- JOINs (all types)
SELECT * FROM users u CROSS JOIN products p
SELECT u.name, o.total FROM users u INNER JOIN orders o ON u.id = o.user_id
SELECT u.name, o.total FROM users u LEFT JOIN orders o ON u.id = o.user_id
SELECT u.name, o.total FROM users u RIGHT JOIN orders o ON u.id = o.user_id
SELECT u.name, o.total FROM users u FULL JOIN orders o ON u.id = o.user_id
SELECT u.name, p.name, o.qty FROM users u JOIN orders o ON ... JOIN products p ON ...

-- Subqueries (all forms)
SELECT * FROM users WHERE id IN (SELECT user_id FROM orders)
SELECT * FROM users WHERE id NOT IN (SELECT user_id FROM orders)
SELECT * FROM users WHERE EXISTS (SELECT 1 FROM orders WHERE orders.user_id = users.id)
SELECT * FROM products WHERE NOT EXISTS (SELECT 1 FROM orders WHERE ...)
SELECT * FROM products WHERE price > ALL (SELECT price FROM products WHERE category = 'tools')
SELECT * FROM products WHERE price > ANY (SELECT price FROM products WHERE category = 'tools')

-- Scalar subqueries
SELECT * FROM products WHERE price > (SELECT AVG(price) FROM products)
SELECT (SELECT MAX(price) FROM products) AS max_price
SELECT *, (SELECT COUNT(*) FROM orders WHERE orders.user_id = users.id) FROM users

-- Derived tables
SELECT * FROM (SELECT * FROM products) AS sub
SELECT u.name, o.total FROM users u JOIN (SELECT user_id, SUM(total) AS total FROM orders GROUP BY user_id) o ON u.id = o.user_id

-- Nested subqueries (3+ levels)
SELECT * FROM users WHERE id IN (SELECT user_id FROM orders WHERE id IN (SELECT order_id FROM order_items WHERE product_id = 101))

-- CASE WHEN
SELECT CASE WHEN price > 10 THEN 'expensive' ELSE 'cheap' END FROM products
SELECT CASE role WHEN 'admin' THEN 'Administrator' ELSE 'User' END FROM users
SELECT name FROM products WHERE CASE WHEN price < 10 THEN 'cheap' ELSE 'expensive' END = 'cheap'

-- Functions
SELECT UPPER(name), LOWER(name), LENGTH(name) FROM users
SELECT CONCAT(name, ' - ', email) FROM users
SELECT SUBSTR(name, 1, 3), TRIM('  hello  ') FROM products
SELECT ABS(-5), ROUND(price, 0) FROM products
SELECT COALESCE(NULL, name), IFNULL(NULL, 'default'), NULLIF(1, 1) FROM users
SELECT REPLACE(email, '@old.com', '@new.com') FROM users
SELECT INSTR(email, '@') FROM users

-- String concatenation and modulo
SELECT name || ' - ' || email FROM users
SELECT id % 2 AS is_odd FROM users

-- UNION / INTERSECT / EXCEPT
SELECT name FROM users UNION SELECT name FROM products
SELECT 1 AS n UNION ALL SELECT 2 AS n
SELECT id FROM users INTERSECT SELECT user_id FROM orders
SELECT id FROM users EXCEPT SELECT user_id FROM orders

-- Window functions (SQL:2003)
SELECT name, ROW_NUMBER() OVER (ORDER BY id) AS rn FROM users
SELECT name, RANK() OVER (PARTITION BY category ORDER BY price DESC) AS rank FROM products
SELECT name, DENSE_RANK() OVER (ORDER BY role) AS dr FROM users

-- CTEs (Common Table Expressions) - SQL:2003
WITH active_users AS (SELECT * FROM users WHERE active = 1) SELECT * FROM active_users
WITH cte1 AS (...), cte2 AS (SELECT * FROM cte1 WHERE ...) SELECT * FROM cte2  -- chained CTEs
WITH RECURSIVE nums AS (SELECT 1 AS n UNION ALL SELECT n + 1 FROM nums WHERE n < 10) SELECT * FROM nums
```

## Not Supported

Verified by probing the engine (2026-08-06). Each entry fails with a parse or
"unknown function" error — none of them silently returns a wrong answer.

**Standard spellings of things that work under another name**

```sql
CHAR_LENGTH(x)                      -- use LENGTH(x)
SUBSTRING(x FROM y FOR z)           -- use SUBSTR(x, y, z)
TRIM(BOTH ' ' FROM x)               -- use TRIM(x), LTRIM(x), RTRIM(x)
POSITION(x IN y)                    -- use INSTR(y, x)
MOD(a, b)                           -- use a % b
```

**Not implemented**

```sql
-- Expressions and predicates
SELECT * FROM t WHERE (a, b) = (1, 2)          -- row value constructors
SELECT * FROM t WHERE a IS DISTINCT FROM b
SELECT * FROM t WHERE x SIMILAR TO 'a%'
SELECT * FROM (VALUES (1), (2)) AS v(x)        -- VALUES as a table
SELECT EXTRACT(YEAR FROM d) FROM t
SELECT POWER(2, 3), SQRT(9)                    -- register via createFunction()

-- Joins and set operations
SELECT * FROM a NATURAL JOIN b
SELECT * FROM a JOIN b USING (id)              -- use ON a.id = b.id
SELECT x FROM a UNION CORRESPONDING SELECT x FROM b

-- Grouping and aggregate extensions
GROUP BY ROLLUP(x) / CUBE(x) / GROUPING SETS ((x), ())
COUNT(*) FILTER (WHERE cond)

-- Window functions beyond the core four
SUM(x) OVER (ORDER BY id ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW)
SELECT ROW_NUMBER() OVER w FROM t WINDOW w AS (ORDER BY id)   -- named windows
LAG, LEAD, NTILE, FIRST_VALUE, LAST_VALUE, PERCENT_RANK, CUME_DIST

-- Statements and clauses
MERGE INTO ...                                 -- no MERGE statement
SELECT * FROM t, LATERAL (SELECT ...) l
SELECT * FROM t ORDER BY x NULLS LAST

-- Lexing
SELECT * FROM products WHERE name = "Widget"   -- double quotes are identifiers,
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
