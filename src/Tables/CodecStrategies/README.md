# Tables\CodecStrategies - Database-Specific Encoding

This namespace contains database-specific codec strategies for the Tables feature.

## Purpose

Codec strategies handle the differences in how various databases represent data types. They translate between PHP values and database-specific representations:

- **MySQL** - `MySQLCodecStrategy`
- **PostgreSQL** - `PostgreSQLCodecStrategy`
- **SQLite** - `SQLiteCodecStrategy`
- **Generic** - `ScalarCodecStrategy` (fallback)

## How It Works

When you use the Tables feature, the appropriate codec strategy is automatically selected based on your PDO driver:

```php
// Automatically uses correct strategy based on database
$codec = new Codec($entity);
$encoded = $codec->encode($user);  // Handles booleans, dates, JSON, etc.
$decoded = $codec->decode($row);   // Converts back to PHP objects
```

## Database Differences Handled

Different databases represent the same concepts differently:

- **Booleans**: MySQL uses `TINYINT(1)`, PostgreSQL has native `BOOLEAN`, SQLite uses integers
- **Dates**: Format and timezone handling varies
- **JSON**: Native JSON type in some databases, text in others
- **Decimals**: Precision and scale representation

The codec strategies abstract these differences so your entity classes work consistently across databases.

## Extending

You can create custom codec strategies for other databases or specialized encoding needs by implementing the codec strategy pattern. See the existing strategies for examples.
