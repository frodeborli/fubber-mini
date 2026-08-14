<?php
/**
 * mini\Table\KVTable - a key/value table over values that live elsewhere
 *
 * Settings, feature flags, profile fields, statistics: values belonging to a
 * system rather than to a row of storage. A subclass supplies keys(), read()
 * and optionally write(); this class turns that into a two-column SQL table.
 *
 * The property that makes it safe to expose to an untrusted query author (an
 * LLM agent, say) is that read() is the ONLY source of values. There is no
 * storage engine behind the table to answer a predicate differently from what
 * the rows show, so a masked value cannot be probed - which is exactly what
 * fails on a storage-backed table.
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Test;
use mini\Database\VirtualDatabase;
use mini\Table\KVTable;

final class TestSettings extends KVTable
{
    public array $store = ['theme' => 'dark', 'items_per_page' => 25];
    public string $password = 's3cret!';
    public int $loginCount = 42;

    protected function keys(): array
    {
        return ['theme', 'items_per_page', 'password', 'login_count'];
    }

    protected function read(string $key): mixed
    {
        return match ($key) {
            'theme', 'items_per_page' => $this->store[$key],
            'password' => '***',
            'login_count' => $this->loginCount,
        };
    }

    protected function write(string $key, mixed $value): void
    {
        match ($key) {
            'theme' => in_array($value, ['light', 'dark'], true)
                ? $this->store['theme'] = $value
                : throw new \InvalidArgumentException(
                    "Setting 'theme' must be one of: light, dark; got '$value'."
                ),
            'password' => strlen((string) $value) >= 8
                ? $this->password = (string) $value
                : throw new \InvalidArgumentException('Password must be at least 8 characters.'),
            default => parent::write($key, $value),
        };
    }
}

$test = new class extends Test {

    private function vdb(TestSettings $s): VirtualDatabase
    {
        $vdb = new VirtualDatabase();
        $vdb->registerTable('settings', $s);
        return $vdb;
    }

    private function col(VirtualDatabase $vdb, string $sql): array
    {
        $out = [];
        foreach ($vdb->query($sql) as $row) {
            $out[] = array_values((array) $row)[0];
        }
        return $out;
    }

    public function testEveryKeyIsARow(): void
    {
        $rows = iterator_to_array($this->vdb(new TestSettings())->query(
            'SELECT key, value FROM settings ORDER BY key'
        ));
        $this->assertCount(4, $rows);
        $this->assertSame('items_per_page', $rows[0]->key);
    }

    public function testReadOneKey(): void
    {
        $this->assertSame(['dark'], $this->col($this->vdb(new TestSettings()),
            "SELECT value FROM settings WHERE key = 'theme'"));
    }

    public function testValuesComeFromReadNotStorage(): void
    {
        // A key whose read() masks the value shows the mask, not the secret
        $this->assertSame(['***'], $this->col($this->vdb(new TestSettings()),
            "SELECT value FROM settings WHERE key = 'password'"));
    }

    public function testAMaskedValueCannotBeProbed(): void
    {
        // The property this class exists for: predicates filter the rows the
        // table yields, so they compare against the mask - there is no
        // storage behind them to consult. On a storage-backed table these
        // probes would recover the secret one character at a time.
        $vdb = $this->vdb(new TestSettings());
        $this->assertSame([], $this->col($vdb, "SELECT key FROM settings WHERE value LIKE 's%'"));
        $this->assertSame([], $this->col($vdb, "SELECT key FROM settings WHERE value = 's3cret!'"));
        $this->assertSame(['password'], $this->col($vdb, "SELECT key FROM settings WHERE value = '***'"));
    }

    public function testUpdateAppliesThroughWrite(): void
    {
        $s = new TestSettings();
        $affected = $this->vdb($s)->exec("UPDATE settings SET value = 'light' WHERE key = 'theme'");
        $this->assertSame(1, $affected);
        $this->assertSame('light', $s->store['theme']);
    }

    public function testAValueCanBeWrittenWithoutBeingReadable(): void
    {
        // "the agent may change the password but not read it"
        $s = new TestSettings();
        $this->vdb($s)->exec("UPDATE settings SET value = 'correct-horse' WHERE key = 'password'");
        $this->assertSame('correct-horse', $s->password);
        $this->assertSame(['***'], $this->col($this->vdb($s), "SELECT value FROM settings WHERE key = 'password'"));
    }

    public function testValidationErrorReachesTheCallerVerbatim(): void
    {
        $s = new TestSettings();
        try {
            $this->vdb($s)->exec("UPDATE settings SET value = 'neon' WHERE key = 'theme'");
            $this->fail('expected the write to be rejected');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('must be one of: light, dark', $e->getMessage());
        }
        $this->assertSame('dark', $s->store['theme'], 'a rejected write must not change anything');
    }

    public function testKeysAreReadOnlyUnlessWriteAllowsThem(): void
    {
        $this->assertThrows(
            fn() => $this->vdb(new TestSettings())->exec(
                "UPDATE settings SET value = '0' WHERE key = 'login_count'"
            ),
            \RuntimeException::class
        );
    }

    public function testRowsCannotBeDeleted(): void
    {
        $this->assertThrows(
            fn() => $this->vdb(new TestSettings())->exec("DELETE FROM settings WHERE key = 'theme'"),
            \RuntimeException::class
        );
    }

    public function testTheKeyColumnCannotBeChanged(): void
    {
        $this->assertThrows(
            fn() => $this->vdb(new TestSettings())->exec("UPDATE settings SET key = 'other'"),
            \InvalidArgumentException::class
        );
    }

    public function testAggregatesAndOrderingWork(): void
    {
        $vdb = $this->vdb(new TestSettings());
        $this->assertSame([4], array_map('intval', $this->col($vdb, 'SELECT COUNT(*) FROM settings')));
        $this->assertSame(
            ['items_per_page', 'login_count'],
            $this->col($vdb, 'SELECT key FROM settings ORDER BY key LIMIT 2')
        );
    }
};

exit($test->run());
