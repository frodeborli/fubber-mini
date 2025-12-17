<?php
namespace mini\Table;

use SQLite3;
use Traversable;

/**
 * In-memory range index using SQLite3
 *
 * Provides O(log n) range queries via SQLite's B-tree index.
 * Supports compound keys via arrays (serialized to sortable format).
 *
 * ```php
 * $index = new InMemoryRangeIndex();
 *
 * // Single column index
 * $index->insert(25, 'row1');
 * $index->insert(30, 'row2');
 * $index->insert(20, 'row3');
 *
 * // Range query: all rows with key >= 22
 * foreach ($index->range(22, null) as $key => $rowId) { ... }
 *
 * // Ordered iteration (ASC)
 * foreach ($index->range() as $key => $rowId) { ... }  // 20, 25, 30
 *
 * // Reverse iteration (DESC)
 * foreach ($index->range(reverse: true) as $key => $rowId) { ... }  // 30, 25, 20
 * ```
 */
class InMemoryRangeIndex implements IndexInterface
{
    private SQLite3 $db;
    private \SQLite3Stmt $insertStmt;
    private \SQLite3Stmt $deleteStmt;
    private \SQLite3Stmt $eqStmt;

    public function __construct()
    {
        $this->db = new SQLite3(':memory:');
        $this->db->enableExceptions(true);

        // Create index table: key (sortable) + rowid (the external row reference)
        // Note: 'rowid' here is our external row ID, not SQLite's internal rowid
        $this->db->exec('CREATE TABLE idx (key, row_id TEXT)');
        $this->db->exec('CREATE INDEX ix ON idx(key)');

        // Prepare common statements
        $this->insertStmt = $this->db->prepare('INSERT INTO idx (key, row_id) VALUES (:key, :row_id)');
        $this->deleteStmt = $this->db->prepare('DELETE FROM idx WHERE key = :key AND row_id = :row_id');
        $this->eqStmt = $this->db->prepare('SELECT row_id FROM idx WHERE key = :key');
    }

    public function insert(mixed $key, int|string $rowId): void
    {
        $this->insertStmt->bindValue(':key', $this->encodeKey($key));
        $this->insertStmt->bindValue(':row_id', (string)$rowId);
        $this->insertStmt->execute();
        $this->insertStmt->reset();
    }

    public function delete(mixed $key, int|string $rowId): void
    {
        $this->deleteStmt->bindValue(':key', $this->encodeKey($key));
        $this->deleteStmt->bindValue(':row_id', (string)$rowId);
        $this->deleteStmt->execute();
        $this->deleteStmt->reset();
    }

    public function eq(mixed $key): Traversable
    {
        $this->eqStmt->bindValue(':key', $this->encodeKey($key));
        $result = $this->eqStmt->execute();

        while ($row = $result->fetchArray(SQLITE3_NUM)) {
            yield $this->decodeRowId($row[0]);
        }

        $this->eqStmt->reset();
    }

    public function range(mixed $start = null, mixed $end = null, bool $reverse = false): Traversable
    {
        $conditions = [];
        $params = [];

        if ($start !== null) {
            $conditions[] = 'key >= :start';
            $params[':start'] = $this->encodeKey($start);
        }

        if ($end !== null) {
            $conditions[] = 'key <= :end';
            $params[':end'] = $this->encodeKey($end);
        }

        $where = empty($conditions) ? '' : 'WHERE ' . implode(' AND ', $conditions);
        $order = $reverse ? 'DESC' : 'ASC';

        $sql = "SELECT key, row_id FROM idx $where ORDER BY key $order";
        $stmt = $this->db->prepare($sql);

        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value);
        }

        $result = $stmt->execute();

        while ($row = $result->fetchArray(SQLITE3_NUM)) {
            $key = $this->decodeKey($row[0]);
            $rowId = $this->decodeRowId($row[1]);
            yield $key => $rowId;
        }
    }

    /**
     * Check if any rows exist for a key
     */
    public function has(mixed $key): bool
    {
        foreach ($this->eq($key) as $_) {
            return true;
        }
        return false;
    }

    /**
     * Count rows for a key
     */
    public function count(mixed $key): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM idx WHERE key = :key');
        $stmt->bindValue(':key', $this->encodeKey($key));
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_NUM);
        return (int)$row[0];
    }

    /**
     * Count rows in a range
     */
    public function countRange(mixed $start = null, mixed $end = null): int
    {
        $conditions = [];
        $params = [];

        if ($start !== null) {
            $conditions[] = 'key >= :start';
            $params[':start'] = $this->encodeKey($start);
        }

        if ($end !== null) {
            $conditions[] = 'key <= :end';
            $params[':end'] = $this->encodeKey($end);
        }

        $where = empty($conditions) ? '' : 'WHERE ' . implode(' AND ', $conditions);
        $sql = "SELECT COUNT(*) FROM idx $where";

        $stmt = $this->db->prepare($sql);
        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value);
        }

        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_NUM);
        return (int)$row[0];
    }

    /**
     * Clear all entries from the index
     */
    public function clear(): void
    {
        $this->db->exec('DELETE FROM idx');
    }

    /**
     * Get total number of row references in the index
     */
    public function rowCount(): int
    {
        $result = $this->db->query('SELECT COUNT(*) FROM idx');
        $row = $result->fetchArray(SQLITE3_NUM);
        return (int)$row[0];
    }

    /**
     * Encode key for SQLite storage
     *
     * For sortable compound keys, we use a type-prefixed encoding
     * with hex-encoded binary data to avoid SQLite text/blob issues:
     * - Null: "0"
     * - Int/Float: "1" + hex-encoded sortable bytes
     * - String: "2" + string (as-is, strings sort lexicographically)
     * - Array: "3" + hex-encoded length-prefixed elements
     */
    private function encodeKey(mixed $key): string
    {
        if ($key === null) {
            return "0";
        }

        if (is_int($key) || is_float($key)) {
            // Encode numbers to sort correctly
            return "1" . bin2hex($this->encodeNumber($key));
        }

        if (is_string($key)) {
            return "2" . $key;
        }

        if (is_bool($key)) {
            return "1" . bin2hex($this->encodeNumber($key ? 1 : 0));
        }

        if (is_array($key)) {
            $parts = [];
            foreach ($key as $part) {
                $encoded = $this->encodeKey($part);
                // Length-prefix each part for unambiguous parsing
                $parts[] = sprintf('%08x', strlen($encoded)) . $encoded;
            }
            return "3" . implode('', $parts);
        }

        throw new \InvalidArgumentException('Unsupported key type: ' . gettype($key));
    }

    /**
     * Encode a number to a sortable byte string
     *
     * Uses a format where lexicographic order matches numeric order.
     */
    private function encodeNumber(int|float $n): string
    {
        // Pack as 64-bit float, then flip bits for sortability
        // IEEE 754 doubles: negative numbers need all bits flipped,
        // positive numbers need only sign bit flipped
        $packed = pack('E', $n);  // Big-endian double
        $bytes = unpack('C8', $packed);

        if ($n >= 0) {
            // Flip sign bit (make positive > negative)
            $bytes[1] ^= 0x80;
        } else {
            // Flip all bits (make more negative < less negative)
            for ($i = 1; $i <= 8; $i++) {
                $bytes[$i] ^= 0xFF;
            }
        }

        return pack('C8', ...$bytes);
    }

    /**
     * Decode key from SQLite storage
     */
    private function decodeKey(string $encoded): mixed
    {
        if ($encoded === '' || $encoded === '0') {
            return null;
        }

        $type = $encoded[0];
        $data = substr($encoded, 1);

        return match ($type) {
            '0' => null,
            '1' => $this->decodeNumber(hex2bin($data)),
            '2' => $data,
            '3' => $this->decodeArray($data),
            default => throw new \RuntimeException('Unknown key type: ' . $type),
        };
    }

    /**
     * Decode a sortable number string back to number
     */
    private function decodeNumber(string $data): int|float
    {
        $bytes = unpack('C8', $data);

        // Check if originally negative (after our flip, sign bit would be 0)
        $wasNegative = ($bytes[1] & 0x80) === 0;

        if ($wasNegative) {
            // Flip all bits back
            for ($i = 1; $i <= 8; $i++) {
                $bytes[$i] ^= 0xFF;
            }
        } else {
            // Flip sign bit back
            $bytes[1] ^= 0x80;
        }

        $packed = pack('C8', ...$bytes);
        $unpacked = unpack('E', $packed);  // Big-endian double

        $value = $unpacked[1];

        // Return int if it's a whole number
        if ($value == (int)$value && $value >= PHP_INT_MIN && $value <= PHP_INT_MAX) {
            return (int)$value;
        }

        return $value;
    }

    /**
     * Decode array key from encoded format
     */
    private function decodeArray(string $data): array
    {
        $result = [];
        $offset = 0;

        while ($offset < strlen($data)) {
            // Read length prefix (8 hex chars = 4 bytes)
            $lengthHex = substr($data, $offset, 8);
            $length = hexdec($lengthHex);
            $offset += 8;

            // Read encoded element
            $encoded = substr($data, $offset, $length);
            $offset += $length;

            $result[] = $this->decodeKey($encoded);
        }

        return $result;
    }

    /**
     * Decode row ID (stored as string, may be int)
     */
    private function decodeRowId(string $rowId): int|string
    {
        if (ctype_digit($rowId) || ($rowId[0] === '-' && ctype_digit(substr($rowId, 1)))) {
            return (int)$rowId;
        }
        return $rowId;
    }
}
