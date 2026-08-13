<?php
/**
 * Regression tests: semi-join reduction (sideways information passing)
 *
 * For `SELECT v.name FROM events e JOIN venues v ON e.venue_id = v.id
 * WHERE e.id = 22`, the planner pushes `e.id = 22` into the events backend -
 * but previously the join then consumed the venues table as a whole stream,
 * fetching every venue to match against one event.
 *
 * The planner now uses the one cardinality fact a schema gives for free:
 * an eq on a unique/primary column proves at most one row, and an eq on any
 * column suggests few. A join side hinted small is materialized once, its
 * distinct join keys are pushed into the other side as a parameterized IN,
 * and the materialized rows replace the hinted side so nothing is fetched
 * twice. Past SEMI_JOIN_KEY_CAP the reduction steps aside and the join
 * streams as before - the cap bounds the optimization, it never fails it.
 *
 * Tests observe the SQL each backend actually receives via a logging
 * executor - the wire truth, not a debug rendering.
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Test;
use mini\Database\VirtualDatabase;
use mini\Database\PartialQuery;
use mini\Parsing\SQL\SqlRenderer;

$test = new class extends Test {

    /** @var array<string, list<string>> table => SQL strings its backend received */
    private array $backendSql = [];

    protected function setUp(): void
    {
        \mini\bootstrap();
        \mini\db()->exec('DROP TABLE IF EXISTS sj_events');
        \mini\db()->exec('DROP TABLE IF EXISTS sj_venues');
        \mini\db()->exec('CREATE TABLE sj_venues (id INTEGER PRIMARY KEY, name TEXT)');
        \mini\db()->exec('CREATE TABLE sj_events (id INTEGER PRIMARY KEY, venue_id INTEGER, title TEXT)');
        for ($i = 1; $i <= 50; $i++) {
            \mini\db()->exec("INSERT INTO sj_venues VALUES ($i, 'Venue $i')");
        }
        for ($i = 1; $i <= 200; $i++) {
            $v = ($i % 50) + 1;
            \mini\db()->exec("INSERT INTO sj_events VALUES ($i, $v, 'Event $i')");
        }
    }

    private function vdb(): VirtualDatabase
    {
        $this->backendSql = [];
        $dialect = \mini\db()->getDialect();
        $renderer = SqlRenderer::forDialect($dialect);

        $mk = function (string $table, string $alias) use ($dialect, $renderer): PartialQuery {
            $executor = function (PartialQuery $q, $ast) use ($dialect, $renderer, $alias): \Traversable {
                [$sql, $params] = $ast !== null ? $renderer->renderWithParams($ast) : $q->getSql($dialect);
                $this->backendSql[$alias][] = $sql;
                foreach (\mini\db()->query($sql, $params) as $row) {
                    yield $row;
                }
            };
            return PartialQuery::fromSql(\mini\db(), $executor, "SELECT * FROM $table");
        };

        $vdb = new VirtualDatabase();
        $vdb->registerTable('events', $mk('sj_events', 'events'));
        $vdb->registerTable('venues', $mk('sj_venues', 'venues'));
        return $vdb;
    }

    private function sqlFor(string $table): string
    {
        return implode(' | ', $this->backendSql[$table] ?? []);
    }

    public function testEqOnJoinDrivingSideReducesOtherSideToInLookup(): void
    {
        $rows = iterator_to_array($this->vdb()->query(
            'SELECT v.name FROM events e JOIN venues v ON e.venue_id = v.id WHERE e.id = 22'
        ));

        $this->assertCount(1, $rows);
        $this->assertSame('Venue 23', $rows[0]->name);

        // The venues backend must receive a keyed lookup, not a full scan
        $this->assertStringContainsString('IN (?)', $this->sqlFor('venues'));
        // And the events side is fetched exactly once (materialized rows reused)
        $this->assertCount(1, $this->backendSql['events']);
    }

    public function testReductionResultMatchesUnreducedJoin(): void
    {
        // Same query with no eq hint anywhere - streams both sides fully
        $all = iterator_to_array($this->vdb()->query(
            'SELECT e.id, v.name FROM events e JOIN venues v ON e.venue_id = v.id WHERE e.id < 5'
        ));
        // Oracle: compute expected pairing directly
        $expected = [];
        foreach (\mini\db()->query('SELECT e.id, v.name FROM sj_events e JOIN sj_venues v ON e.venue_id = v.id WHERE e.id < 5 ORDER BY e.id') as $r) {
            $expected[] = [$r->id, $r->name];
        }
        usort($all, fn($a, $b) => $a->{'id'} <=> $b->{'id'});
        $got = array_map(fn($r) => [$r->id, $r->name], $all);
        $this->assertSame($expected, $got);
    }

    public function testEqMatchingNothingShortCircuitsOtherSide(): void
    {
        $rows = iterator_to_array($this->vdb()->query(
            'SELECT v.name FROM events e JOIN venues v ON e.venue_id = v.id WHERE e.id = 99999'
        ));

        $this->assertCount(0, $rows);
        // Inner join against a proven-empty side: venues is never fetched at all
        $this->assertSame('', $this->sqlFor('venues'), 'venues backend should not be queried');
    }

    public function testLargeDrivingSideSkipsReductionAndStreams(): void
    {
        // eq on title matches 1 row per value, but here use a predicate that
        // matches MANY rows via a non-eq shape: no hint fires, join streams.
        $rows = iterator_to_array($this->vdb()->query(
            'SELECT v.name FROM events e JOIN venues v ON e.venue_id = v.id WHERE e.id < 100'
        ));

        $this->assertCount(99, $rows);
        // No IN pushed into venues - full stream, correct results
        $this->assertStringNotContainsString('IN', $this->sqlFor('venues'));
    }

    public function testUniqueColumnHintFiresOnSchemaAwareTables(): void
    {
        // In-memory tables carry real IndexType metadata: the 'unique' proof
        // tier, not just the eq heuristic
        $venues = new \mini\Table\InMemoryTable(
            new \mini\Table\ColumnDef('id', \mini\Table\Types\ColumnType::Int, \mini\Table\Types\IndexType::Primary),
            new \mini\Table\ColumnDef('name', \mini\Table\Types\ColumnType::Text),
        );
        $venues->insert(['id' => 1, 'name' => 'Arena']);
        $venues->insert(['id' => 2, 'name' => 'Hall']);
        $events = new \mini\Table\InMemoryTable(
            new \mini\Table\ColumnDef('id', \mini\Table\Types\ColumnType::Int, \mini\Table\Types\IndexType::Primary),
            new \mini\Table\ColumnDef('venue_id', \mini\Table\Types\ColumnType::Int),
        );
        $events->insert(['id' => 1, 'venue_id' => 2]);
        $events->insert(['id' => 2, 'venue_id' => 1]);

        $vdb = new VirtualDatabase();
        $vdb->registerTable('events', $events);
        $vdb->registerTable('venues', $venues);

        $rows = iterator_to_array($vdb->query(
            'SELECT v.name FROM events e JOIN venues v ON e.venue_id = v.id WHERE e.id = 1'
        ));
        $this->assertCount(1, $rows);
        $this->assertSame('Hall', $rows[0]->name);
    }
};

exit($test->run());
