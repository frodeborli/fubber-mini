<?php
/**
 * Regression tests: src/Database/VDB-STATUS.md must describe the engine
 *
 * VDB-STATUS.md is cited as the SQL coverage authority for VirtualDatabase.
 * A coverage document rots in one direction only - features land, nobody
 * moves the line, and the document quietly starts under-claiming; or a
 * construct regresses and it starts over-claiming. Either way the first
 * evaluator to hit the discrepancy stops trusting the docs.
 *
 * So the document is executable. This test reads it and holds it to what it
 * says:
 *
 *   - Every entry under "Not Supported" must actually fail. If someone
 *     implements one without moving its line, this test fails and tells them
 *     to move it.
 *   - Every executable line under "Working" must still parse. If a grammar
 *     change breaks one, this test fails before a user finds it.
 *
 * Lines beginning with `--` are prose and skipped. Lines containing `...` are
 * illustrative shapes, not statements, and are skipped for the parse check
 * (they are not permitted in the "Not Supported" blocks, which must be
 * runnable).
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Test;
use mini\Database\VirtualDatabase;
use mini\Parsing\SQL\SqlParser;
use mini\Table\InMemoryTable;
use mini\Table\ColumnDef;
use mini\Table\Types\ColumnType;
use mini\Table\Types\IndexType;

$test = new class extends Test {

    private const DOC = __DIR__ . '/../../src/Database/VDB-STATUS.md';

    /**
     * Extract the fenced ```sql blocks of VDB-STATUS.md, keyed by the `##`
     * heading they appear under.
     *
     * @return array<string, list<string>> heading => statement lines
     */
    private function sections(): array
    {
        $contents = file_get_contents(self::DOC);
        $this->assertNotEmpty($contents, 'VDB-STATUS.md must exist and be readable');

        $sections = [];
        $heading = '';
        $inFence = false;

        foreach (explode("\n", $contents) as $line) {
            if (str_starts_with($line, '## ')) {
                $heading = trim(substr($line, 3));
                continue;
            }
            if (str_starts_with($line, '```')) {
                $inFence = !$inFence;
                continue;
            }
            if (!$inFence) {
                continue;
            }

            $line = trim($line);
            if ($line === '' || str_starts_with($line, '--')) {
                continue;
            }
            // Drop a trailing end-of-line annotation. The document separates
            // one from the statement by at least two spaces, and no statement
            // in it carries `--` inside a string literal.
            $line = trim(preg_replace('/\s{2,}--.*$/', '', $line));
            $sections[$heading][] = $line;
        }

        return $sections;
    }

    private function createVdb(): VirtualDatabase
    {
        $t = new InMemoryTable(
            new ColumnDef('x', ColumnType::Int, IndexType::Primary),
            new ColumnDef('y', ColumnType::Int),
            new ColumnDef('d', ColumnType::Text),
        );
        $t->insert(['x' => 1, 'y' => 2, 'd' => '2020-01-01']);
        $t->insert(['x' => 2, 'y' => 3, 'd' => '2020-01-02']);

        $vdb = new VirtualDatabase();
        $vdb->registerTable('t', $t);
        return $vdb;
    }

    /**
     * The tables the "Working" examples are written against
     *
     * Every table and column named anywhere in that section exists here, with
     * at least two rows and at least one NULL, so a statement that runs
     * actually touches data rather than short-circuiting on an empty table.
     */
    private function createDocumentedVdb(): VirtualDatabase
    {
        $users = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('name', ColumnType::Text),
            new ColumnDef('email', ColumnType::Text),
            new ColumnDef('role', ColumnType::Text),
            new ColumnDef('active', ColumnType::Int),
        );
        $users->insert(['id' => 1, 'name' => 'Alice', 'email' => 'a@old.com', 'role' => 'admin', 'active' => 1]);
        $users->insert(['id' => 2, 'name' => 'Bob', 'email' => 'b@old.com', 'role' => 'user', 'active' => 0]);
        $users->insert(['id' => 3, 'name' => 'Cara', 'email' => null, 'role' => 'user', 'active' => 1]);

        $products = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('name', ColumnType::Text),
            new ColumnDef('category', ColumnType::Text),
            new ColumnDef('price', ColumnType::Float),
            new ColumnDef('stock', ColumnType::Int),
        );
        $products->insert(['id' => 101, 'name' => 'Widget', 'category' => 'gadgets', 'price' => 9.99, 'stock' => 50]);
        $products->insert(['id' => 102, 'name' => 'Sprocket', 'category' => 'tools', 'price' => 19.5, 'stock' => null]);
        $products->insert(['id' => 103, 'name' => 'Cog', 'category' => 'tools', 'price' => 4.25, 'stock' => 7]);

        $orders = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('user_id', ColumnType::Int),
            new ColumnDef('product_id', ColumnType::Int),
            new ColumnDef('total', ColumnType::Float),
            new ColumnDef('qty', ColumnType::Int),
            new ColumnDef('status', ColumnType::Text),
        );
        $orders->insert(['id' => 1, 'user_id' => 1, 'product_id' => 101, 'total' => 9.99, 'qty' => 1, 'status' => 'paid']);
        $orders->insert(['id' => 2, 'user_id' => 2, 'product_id' => 102, 'total' => 39.0, 'qty' => 2, 'status' => 'open']);

        $orderItems = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('order_id', ColumnType::Int),
            new ColumnDef('product_id', ColumnType::Int),
        );
        $orderItems->insert(['id' => 1, 'order_id' => 1, 'product_id' => 101]);
        $orderItems->insert(['id' => 2, 'order_id' => 2, 'product_id' => 102]);

        $contacts = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('email', ColumnType::Text),
            new ColumnDef('notes', ColumnType::Text),
        );
        $contacts->insert(['id' => 1, 'email' => 'a@old.com', 'notes' => 'a@old.com']);
        $contacts->insert(['id' => 2, 'email' => 'b@old.com', 'notes' => null]);
        $contacts->insert(['id' => 3, 'email' => null, 'notes' => null]);

        $events = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('created_at', ColumnType::Text),
        );
        $events->insert(['id' => 1, 'created_at' => '2020-01-01 10:00:00']);
        $events->insert(['id' => 2, 'created_at' => '2020-02-09 11:30:00']);

        $emp = new InMemoryTable(
            new ColumnDef('emp_id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('dept_id', ColumnType::Int),
            new ColumnDef('name', ColumnType::Text),
        );
        $emp->insert(['emp_id' => 1, 'dept_id' => 1, 'name' => 'Alice']);
        $emp->insert(['emp_id' => 2, 'dept_id' => 2, 'name' => 'Bob']);

        $dept = new InMemoryTable(
            new ColumnDef('dept_id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('dept', ColumnType::Text),
        );
        $dept->insert(['dept_id' => 1, 'dept' => 'Eng']);

        $vdb = new VirtualDatabase();
        $vdb->registerTable('users', $users);
        $vdb->registerTable('products', $products);
        $vdb->registerTable('orders', $orders);
        $vdb->registerTable('order_items', $orderItems);
        $vdb->registerTable('contacts', $contacts);
        $vdb->registerTable('events', $events);
        $vdb->registerTable('emp', $emp);
        $vdb->registerTable('dept', $dept);
        return $vdb;
    }

    /** Route a statement to the API that accepts its kind. */
    private function execute(VirtualDatabase $vdb, string $sql): void
    {
        $head = strtoupper(strtok(ltrim($sql), " \t("));

        if ($head === 'SELECT' || $head === 'VALUES' || $head === 'WITH') {
            iterator_to_array($vdb->query($sql));
            return;
        }
        $vdb->exec($sql);
    }

    public function testNotSupportedEntriesAllFail(): void
    {
        $statements = $this->sections()['Not Supported'] ?? [];
        $this->assertGreaterThan(20, count($statements), 'the Not Supported blocks must have been found');

        $unexpectedlyWorking = [];

        foreach ($statements as $sql) {
            $this->assertStringNotContainsString(
                '...',
                $sql,
                "Not Supported entries must be runnable statements, not sketches: {$sql}"
            );

            // A fresh database per statement: entries that are DML must not be
            // able to empty the table and let a later SELECT pass vacuously.
            $vdb = $this->createVdb();

            try {
                $this->execute($vdb, $sql);
                $unexpectedlyWorking[] = $sql;
            } catch (\Throwable) {
                // Expected: the document says this does not work.
            }
        }

        $this->assertSame(
            [],
            $unexpectedlyWorking,
            "These succeed but VDB-STATUS.md lists them as unsupported - move them to \"Working\":\n  "
                . implode("\n  ", $unexpectedlyWorking)
        );
    }

    public function testWorkingEntriesAllParse(): void
    {
        $statements = $this->sections()['Working'] ?? [];
        $this->assertGreaterThan(80, count($statements), 'the Working block must have been found');

        $broken = [];
        $checked = 0;

        foreach ($statements as $sql) {
            if (str_contains($sql, '...')) {
                continue; // illustrative shape, not a statement
            }
            $checked++;
            try {
                (new SqlParser())->parse($sql);
            } catch (\Throwable $e) {
                $broken[] = $sql . '  --  ' . $e->getMessage();
            }
        }

        $this->assertGreaterThan(80, $checked, 'most Working entries must be real statements');
        $this->assertSame(
            [],
            $broken,
            "VDB-STATUS.md claims these work, but they no longer parse:\n  " . implode("\n  ", $broken)
        );
    }

    /**
     * Parsing is not working
     *
     * "It parses" was the bar this test used to set, and it is far too low:
     * `SELECT * FROM users JOIN orders USING (user_id)` sat in "Working" and
     * parsed cheerfully, even though `users` has no `user_id` and the
     * statement had never once been run. Every entry is executed here, against
     * a fixture that has every table and column the document names.
     *
     * What this still does not check is the *answers*. Doing that generically
     * would mean a second engine to compare against, and roughly a third of
     * the entries are constructs `sqlite3` does not accept in the first place
     * (EXTRACT's standard spelling, FULL JOIN, POSITION, REPEAT/REVERSE/LPAD,
     * IS DISTINCT FROM, FETCH FIRST) - so the differential would be against a
     * hand-written expected result per line, which belongs in the feature's
     * own test file, not here. Correctness of a given construct is pinned by
     * those files; what this test pins is that the *document* is not describing
     * an engine that does not exist.
     */
    public function testWorkingEntriesAllExecute(): void
    {
        $statements = $this->sections()['Working'] ?? [];

        $broken = [];
        $executed = 0;

        foreach ($statements as $sql) {
            if (str_contains($sql, '...')) {
                continue; // illustrative shape, not a statement
            }
            // A fresh database per statement, for the same reason the
            // "Not Supported" pass uses one.
            $vdb = $this->createDocumentedVdb();
            try {
                $this->execute($vdb, $sql);
                $executed++;
            } catch (\Throwable $e) {
                $broken[] = $sql . '  --  ' . get_class($e) . ': ' . $e->getMessage();
            }
        }

        $this->assertGreaterThan(80, $executed + count($broken), 'most Working entries must be real statements');
        $this->assertSame(
            [],
            $broken,
            "VDB-STATUS.md claims these work, but they fail when run:\n  " . implode("\n  ", $broken)
        );
    }
};

exit($test->run());
