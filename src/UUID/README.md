# UUID - Universally Unique Identifiers

## Philosophy

Mini's UUID implementation provides **cryptographically secure, standards-compliant UUID generation** with sensible defaults and easy customization.

**Key Principles:**
- **Time-ordered by default** - UUID v7 for database-friendly performance
- **Cryptographically secure** - Uses `random_bytes()` for randomness
- **Zero configuration** - Works out of the box with `uuid()`
- **Swappable implementations** - Easy to customize via config
- **Standards-compliant** - Follows RFC 4122 and RFC 9562

## Quick Start

```php
// Generate UUID v7 (default - time-ordered, database-friendly)
$id = uuid();  // "018c8f3a-2b4e-7a1c-9f23-4d5e6f7a8b9c"

// Generate UUID v4 (random, no timestamp)
$token = uuid4();  // "550e8400-e29b-41d4-a716-446655440000"

// Explicitly request v7
$sortableId = uuid7();  // "018c8f3a-2b4e-7a1c-9f23-4d5e6f7a8b9c"

// Use in database
db()->exec("INSERT INTO posts (id, title) VALUES (?, ?)", [uuid(), 'Hello World']);
```

That's it! No configuration needed for most use cases.

## UUID Versions

Mini includes two built-in UUID implementations:

### UUID v7 (Default) - Time-Ordered

**Use when:**
- Inserting into databases (better B-tree performance)
- You need chronological sorting
- Building distributed systems
- Default choice for most applications

**Format:** `018c8f3a-2b4e-7a1c-9f23-4d5e6f7a8b9c`

**Structure:**
- First 48 bits: Unix timestamp (milliseconds)
- Next 74 bits: Cryptographic randomness
- Naturally sortable by creation time
- Valid until year ~10889 AD

```php
$id = uuid();   // Uses UUID v7 by default
$id = uuid7();  // Explicitly request v7
```

### UUID v4 - Random

**Use when:**
- You don't need time ordering
- Maximum unpredictability is required
- Security/privacy concerns (no timestamp leakage)
- Compatibility with systems expecting v4

**Format:** `550e8400-e29b-41d4-a716-446655440000`

**Structure:**
- 122 bits of cryptographic randomness
- No temporal component
- Classic UUID format

```php
$token = uuid4();  // Direct function call
```

## Common Usage Examples

### Basic UUID Generation

```php
// Generate UUIDs
$userId = uuid();
$postId = uuid();
$sessionId = uuid();

// All UUIDs are strings in standard format (36 characters)
// Example: "018c8f3a-2b4e-7a1c-9f23-4d5e6f7a8b9c"
```

### Database Primary Keys

```php
// Create table with UUID primary key
db()->exec("CREATE TABLE posts (
    id CHAR(36) PRIMARY KEY,
    title TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Insert with UUID
db()->exec(
    "INSERT INTO posts (id, title) VALUES (?, ?)",
    [uuid(), 'My First Post']
);

// Query by UUID
$post = db()->queryOne("SELECT * FROM posts WHERE id = ?", [$postId]);
```

### User Registration

```php
// _routes/register.php
session();

$userId = uuid();

db()->exec(
    "INSERT INTO users (id, email, password_hash) VALUES (?, ?, ?)",
    [$userId, $_POST['email'], password_hash($_POST['password'], PASSWORD_DEFAULT)]
);

$_SESSION['user_id'] = $userId;

header('Location: /dashboard');
```

### API Resource Identifiers

```php
// _routes/api/posts.php
header('Content-Type: application/json');

$postId = uuid();

db()->exec(
    "INSERT INTO posts (id, title, content, author_id) VALUES (?, ?, ?, ?)",
    [$postId, $_POST['title'], $_POST['content'], $_SESSION['user_id']]
);

echo json_encode([
    'id' => $postId,
    'url' => "/api/posts/$postId"
]);
```

### Session Tokens

```php
// Generate secure session token
$token = uuid();

db()->exec(
    "INSERT INTO sessions (token, user_id, expires_at) VALUES (?, ?, ?)",
    [$token, $userId, date('Y-m-d H:i:s', time() + 3600)]
);

setcookie('session_token', $token, time() + 3600, '/', '', true, true);
```

### Batch ID Generation

```php
$orders = [];

foreach ($_POST['items'] as $item) {
    $orders[] = [
        'id' => uuid(),
        'product_id' => $item['product_id'],
        'quantity' => $item['quantity']
    ];
}

db()->transaction(function() use ($orders) {
    $stmt = db()->getPdo()->prepare(
        "INSERT INTO orders (id, product_id, quantity) VALUES (?, ?, ?)"
    );

    foreach ($orders as $order) {
        $stmt->execute([$order['id'], $order['product_id'], $order['quantity']]);
    }
});
```

## Advanced Examples

### Time-Range Queries (UUID v7)

UUID v7's time-ordered nature enables efficient time-range queries:

```php
// Find posts created in the last hour
// UUID v7 encodes timestamp, so we can use UUID comparison!

$oneHourAgo = uuid7AtTime(time() - 3600);

$recentPosts = db()->query(
    "SELECT * FROM posts WHERE id >= ? ORDER BY id DESC",
    [$oneHourAgo]
);

// Helper function to generate UUID v7 at specific time
function uuid7AtTime(int $unixTime): string {
    $timestamp = $unixTime * 1000;

    $uuid = dechex(
        0xF0000000000000 |
        (($timestamp << 8) & 0x0FFFFFFF000000) |
        (($timestamp << 4) & 0x000000000FFFF0)
    ) . str_repeat('0', 22);

    $uuid[0] = '0';
    $uuid[8] = '-';
    $uuid[13] = '-';
    $uuid[14] = '7';
    $uuid[18] = '-';
    $uuid[19] = '8';
    $uuid[23] = '-';

    return $uuid;
}
```

### Extracting Timestamp from UUID v7

```php
// Extract creation timestamp from UUID v7
function uuid7Timestamp(string $uuid): int {
    // Remove hyphens and extract first 12 hex chars (48 bits)
    $hex = str_replace('-', '', $uuid);
    $timestampHex = substr($hex, 0, 12);

    // Convert to milliseconds
    $milliseconds = hexdec($timestampHex);

    // Convert to Unix timestamp
    return (int)($milliseconds / 1000);
}

$uuid = uuid();
$createdAt = uuid7Timestamp($uuid);

echo "UUID created at: " . date('Y-m-d H:i:s', $createdAt);
```

### URL-Safe UUIDs

```php
// Convert UUID to URL-safe base64 (22 characters instead of 36)
function uuidToBase64(string $uuid): string {
    $binary = hex2bin(str_replace('-', '', $uuid));
    return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
}

function base64ToUuid(string $base64): string {
    $binary = base64_decode(strtr($base64, '-_', '+/'));
    $hex = bin2hex($binary);

    return sprintf(
        '%s-%s-%s-%s-%s',
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        substr($hex, 12, 4),
        substr($hex, 16, 4),
        substr($hex, 20)
    );
}

$uuid = uuid();
$short = uuidToBase64($uuid);  // "AYyPOitOehyfI01ub3qLnA"

// Use in URL
echo "https://example.com/posts/$short";

// Convert back
$original = base64ToUuid($short);
```

## Custom UUID Implementations

### Switching to UUID v4

```php
// _config/mini/UUID/FactoryInterface.php
return new \mini\UUID\UUID4Factory();
```

### Custom UUID Generator

```php
// _config/mini/UUID/FactoryInterface.php

return new class implements \mini\UUID\FactoryInterface {
    public function make(): string {
        // Example: ULID-style (base32, time-ordered)
        return strtolower(
            sprintf(
                '%010s%016s',
                base_convert((string)(int)(microtime(true) * 1000), 10, 32),
                base_convert(bin2hex(random_bytes(10)), 16, 32)
            )
        );
    }
};
```

### Snowflake-Style IDs

```php
// _config/mini/UUID/FactoryInterface.php

return new class implements \mini\UUID\FactoryInterface {
    private int $sequence = 0;
    private int $lastTimestamp = 0;
    private int $machineId;

    public function __construct() {
        // Machine ID from environment or generated
        $this->machineId = (int)($_ENV['MACHINE_ID'] ?? crc32(gethostname())) & 0x3FF;
    }

    public function make(): string {
        $timestamp = (int)(microtime(true) * 1000);

        if ($timestamp === $this->lastTimestamp) {
            $this->sequence = ($this->sequence + 1) & 0xFFF;
        } else {
            $this->sequence = 0;
        }

        $this->lastTimestamp = $timestamp;

        // 64-bit Snowflake ID
        $id = ($timestamp << 22) | ($this->machineId << 12) | $this->sequence;

        return (string)$id;
    }
};
```

### Composite UUID (Database + Random)

```php
// _config/mini/UUID/FactoryInterface.php

return new class implements \mini\UUID\FactoryInterface {
    public function make(): string {
        // Get next database sequence value
        $sequence = db()->queryField("SELECT nextval('uuid_sequence')");

        // Combine with timestamp and random data
        $timestamp = (int)(microtime(true) * 1000);
        $random = bin2hex(random_bytes(6));

        return sprintf(
            '%08x-%04x-7%03x-%04x-%012s',
            ($timestamp >> 16) & 0xFFFFFFFF,
            $timestamp & 0xFFFF,
            $sequence & 0xFFF,
            mt_rand(0, 0x3FFF) | 0x8000,
            $random
        );
    }
};
```

## Database Considerations

### UUID Storage Formats

**String (CHAR/VARCHAR):**
```sql
-- Most compatible, readable
CREATE TABLE posts (
    id CHAR(36) PRIMARY KEY,  -- Fixed length
    title TEXT
);
```

**Binary (BINARY/VARBINARY):**
```sql
-- More efficient storage (16 bytes vs 36 bytes)
-- MySQL/MariaDB
CREATE TABLE posts (
    id BINARY(16) PRIMARY KEY,
    title TEXT
);
```

```php
// Convert UUID to binary for storage
function uuidToBinary(string $uuid): string {
    return hex2bin(str_replace('-', '', $uuid));
}

function binaryToUuid(string $binary): string {
    $hex = bin2hex($binary);
    return sprintf(
        '%s-%s-%s-%s-%s',
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        substr($hex, 12, 4),
        substr($hex, 16, 4),
        substr($hex, 20)
    );
}

// Insert
db()->exec(
    "INSERT INTO posts (id, title) VALUES (?, ?)",
    [uuidToBinary(uuid()), 'Hello']
);

// Query
$row = db()->queryOne("SELECT id, title FROM posts WHERE id = ?", [uuidToBinary($id)]);
$uuid = binaryToUuid($row['id']);
```

**Native UUID Type (PostgreSQL):**
```sql
CREATE TABLE posts (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    title TEXT
);
```

```php
// PostgreSQL accepts string UUIDs directly
db()->exec(
    "INSERT INTO posts (id, title) VALUES (?::uuid, ?)",
    [uuid(), 'Hello']
);
```

### Index Performance

UUID v7 performs better than UUID v4 for B-tree indexes:

```sql
-- UUID v7: Sequential inserts (good locality)
-- Pages: [018c8f00...] [018c8f10...] [018c8f20...]

-- UUID v4: Random inserts (poor locality)
-- Pages: [f47ac10b...] [a3c8e4d2...] [7c9e6b1f...]
```

**Benchmark (1M inserts, MySQL):**
- UUID v7: ~12 seconds, minimal page splits
- UUID v4: ~18 seconds, frequent page splits

## Performance Characteristics

### UUID Generation Speed

```php
// Benchmark
$start = microtime(true);
for ($i = 0; $i < 100000; $i++) {
    uuid();
}
$elapsed = microtime(true) - $start;

// ~0.5 seconds for 100k UUIDs
// ~200,000 UUIDs/second on modern hardware
```

### Memory Usage

Each UUID:
- **String form:** 36 bytes (plus PHP overhead ~80 bytes)
- **Binary form:** 16 bytes (plus PHP overhead ~72 bytes)

### Collision Probability

**UUID v7:**
- Same millisecond: 74 random bits
- Collision probability: 2^-74 ≈ 1 in 18 quintillion
- Even at 1 billion/second, collision is virtually impossible

**UUID v4:**
- 122 random bits
- Collision probability: 2^-122 ≈ 1 in 5.3 undecillion
- Need ~2.7 quintillion UUIDs for 50% collision probability

## Configuration

**Config File:** `_config/mini/UUID/FactoryInterface.php` (optional, defaults to UUID7Factory)

**No environment variables** - UUIDs require no external configuration.

## Overriding the Service

### Change Default Factory

```php
// _config/mini/UUID/FactoryInterface.php

// Use UUID v4 instead of v7
return new \mini\UUID\UUID4Factory();

// Or custom implementation
return new App\UUID\CustomFactory();
```

### Using Both Versions

Mini provides dedicated functions for each UUID version:

```php
// UUID v7 (time-ordered, default)
$userId = uuid();    // Uses configured factory (v7 by default)
$postId = uuid7();   // Explicitly v7 (ignores factory config)

// UUID v4 (random)
$token = uuid4();    // Always v4 (ignores factory config)

// Mix and match as needed
db()->exec("INSERT INTO users (id, api_token) VALUES (?, ?)",
    [uuid7(), uuid4()]);
```

**Note:** `uuid4()` and `uuid7()` always use their respective implementations,
even if you've customized the default factory via `_config/mini/UUID/FactoryInterface.php`.

## UUID Scope

UUID generation is **stateless and thread-safe**:
- No shared state between calls
- Safe for concurrent requests
- No connection to external services
- Pure function based on time and randomness

Works in any environment:
- Traditional PHP (apache2, php-fpm)
- Long-running apps (Swoole, RoadRunner)
- CLI scripts
- Unit tests

## Best Practices

1. **Use UUID v7 by default** - Better database performance
2. **Store as CHAR(36)** for simplicity - Binary storage only if space-critical
3. **Don't parse UUID structure** in application logic - Treat as opaque identifiers
4. **Use UUIDs for public IDs** - Safe to expose in URLs and APIs
5. **Don't use UUIDs for everything** - Auto-increment integers fine for internal relations

## Troubleshooting

### "Call to undefined function uuid()"

The `uuid()` function is defined in `src/UUID/functions.php`. Ensure:
- File is autoloaded via Composer
- Check `composer.json` has `"files": ["src/UUID/functions.php"]`

### UUIDs Not Time-Ordered

If using UUID v4, UUIDs are random by design. Switch to UUID v7:

```php
// _config/mini/UUID/FactoryInterface.php
return new \mini\UUID\UUID7Factory();
```

### Database Errors with UUID

Ensure column is wide enough:
- Use `CHAR(36)` not `VARCHAR(32)`
- PostgreSQL: Use `UUID` type
- Binary storage: Use `BINARY(16)` exactly

### Performance Issues

For very high throughput, consider:
- Binary storage format (16 bytes vs 36)
- Batch inserts with transactions
- Database-specific optimizations (e.g., PostgreSQL's `gen_random_uuid()`)
