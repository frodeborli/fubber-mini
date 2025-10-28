# No Backwards Compatibility Refactor - 2025-10-27

## Summary

With no existing users, we removed all backwards compatibility complexity and deprecated patterns for a clean, simple architecture.

## Breaking Changes Made

### **1. Removed `$GLOBALS['app']` Completely**

**What it was:**
- Global state container for request-scoped data
- Used for caching: PSR-7 requests, database, cache, codecs, repositories

**Replaced with:**
- Static variables in functions (simpler, cleaner)
- No global state pollution

**Changed functions:**
```php
// Before
function request() {
    if (!isset($GLOBALS['app']['psr']['request'])) {
        $GLOBALS['app']['psr']['request'] = ...;
    }
    return $GLOBALS['app']['psr']['request'];
}

// After
function request() {
    static $request = null;
    if ($request === null) {
        $request = ...;
    }
    return $request;
}
```

**Functions updated:**
- `request()` - PSR-7 request caching
- `db()` - Database singleton
- `cache()` - Cache singleton
- `repositories()` - Repository registry (in Tables feature)

**Note:** `codecs()` was later removed in favor of `CodecRegistry` static class (proper OOP)

### **2. SimpleRouter No Longer Searches DOC_ROOT**

**Removed:**
- File-based routing in DOC_ROOT
- `getDocumentRoot()` method
- Self-reference detection
- Clean URL redirects for DOC_ROOT files
- Bootstrap check in `executeRequestHandler()`

**Result:**
- ~100 lines of complexity removed
- SimpleRouter ONLY searches `_routes/`
- Clear separation: routes in `_routes/`, static files in DOC_ROOT

### **3. Simplified Error Messages**

**Updated:**
```php
// Before
'No PDO configuration found. Please create config/pdo.php or configure config[\'pdo_factory\']'

// After
'No PDO configuration found. Please create _config/pdo.php'
```

### **4. Two Clear Patterns Only**

**Pattern 1: Routing Mode**
```php
// DOC_ROOT/index.php
<?php
require_once __DIR__ . '/../vendor/autoload.php';
mini\router();
```

**Pattern 2: Standalone Files**
```php
// DOC_ROOT/admin.php
<?php
require_once __DIR__ . '/../vendor/autoload.php';
mini\bootstrap();
echo "<h1>Admin</h1>";
```

**No mixing allowed:** Choose one pattern per project.

## Code Removed

### **From functions.php:**
- All `$GLOBALS['app']` initialization and checks
- Config loading from old `config.php` pattern
- Complex routing detection logic
- Clean URL redirect complexity

### **From SimpleRouter:**
- `getDocumentRoot()` method
- DOC_ROOT file searching
- Self-reference detection
- Bootstrap check in executeRequestHandler
- Clean URL redirect static method

### **From Mini class:**
- Config path changed from `config/` to `_config/`
- Routes path added: `_routes/`

## Benefits

### **1. Simpler Code**

**Lines removed:** ~150 lines total
- functions.php: ~50 lines
- SimpleRouter: ~100 lines

**Complexity removed:**
- No global state management
- No DOC_ROOT searching
- No bootstrap detection
- No self-reference logic

### **2. Clearer Architecture**

| Directory | Purpose | Web Access | Bootstrap |
|-----------|---------|------------|-----------|
| `_routes/` | Route handlers | ❌ No | ❌ No (router does it) |
| `_config/` | Configuration | ❌ No | N/A |
| `_errors/` | Error pages | ❌ No | N/A |
| `DOC_ROOT/` | Entry + static | ✅ Yes | ✅ Yes (except index.php) |

### **3. Better Performance**

**Static variables vs $GLOBALS:**
- Faster access (no array lookup)
- Less memory overhead
- Simpler garbage collection

**No DOC_ROOT searching:**
- Fewer file_exists() calls
- Faster routing
- Less I/O

### **4. Easier to Understand**

**Before:** "Where does this variable come from? What initializes it? When is it available?"

**After:** "Static variable in function. Available when function is called."

## Migration (If There Were Users)

### **1. Move Route Handlers**
```bash
mv html/users.php _routes/users.php
mv html/api/posts.php _routes/api/posts.php
```

### **2. Update Configuration Path**
```bash
mv config _config
```

### **3. Remove Bootstrap from Routes**
```php
// _routes/users.php - REMOVE these:
require_once __DIR__ . '/../vendor/autoload.php';  // ❌
mini\bootstrap();  // ❌

// Just your logic:
return mini\json_response(['users' => [...]]);
```

### **4. Add Bootstrap to Standalone Files**
```php
// DOC_ROOT/admin.php
<?php
require_once __DIR__ . '/../vendor/autoload.php';
mini\bootstrap();  // ADD THIS

echo "<h1>Admin</h1>";
```

## Testing

All tests pass after changes:
- ✅ `tests/container.php` - PSR-11 container
- ✅ `tests/router-new-architecture.php` - Routing
- ✅ `tests/translator-locale.php` - I18n
- ✅ `tests/bootstrap.php` - Bootstrap

## Documentation

Updated:
- ✅ `CLAUDE.md` - Two patterns clearly documented
- ✅ `BREAKING-CHANGES.md` - SimpleRouter changes
- ✅ `ARCHITECTURE-REFACTOR.md` - Directory structure
- ✅ This file - No-BC refactor summary

## Comparison

### **Before (With BC Concerns)**

**functions.php:**
- 697 lines
- Complex bootstrap with router detection
- `$GLOBALS['app']` everywhere
- Config loading from multiple locations

**SimpleRouter:**
- 640 lines
- DOC_ROOT searching
- Self-reference detection
- Document root validation
- Clean URL redirects

### **After (No BC)**

**functions.php:**
- 645 lines (-52 lines, -7.5%)
- Simple bootstrap focused on error handling
- Static variables only
- Single config location (`_config/`)

**SimpleRouter:**
- 540 lines (-100 lines, -15.6%)
- Only searches `_routes/`
- No DOC_ROOT logic
- No bootstrap detection
- Single /index.php redirect only

**Total:** ~150 lines removed, ~13% reduction

## Philosophy

**With BC:** "How do we support the old way while adding the new way?"
- Result: Complexity, confusion, dual code paths

**Without BC:** "What's the simplest, cleanest architecture?"
- Result: One clear way, easy to understand, maintainable

## Future

With no BC concerns, we can:
1. **Make aggressive optimizations** - No worrying about old code
2. **Simplify further** - Remove any remaining complexity
3. **Change patterns freely** - Improve as we learn
4. **Keep it clean** - No technical debt accumulation

## Key Insight

> **"Backwards compatibility is a tax on every future change."**
>
> Without it, we can:
> - Make the framework simpler
> - Make it faster
> - Make it more maintainable
> - Make it easier to understand
>
> The cost? We'd have to update existing projects. But with zero users, this cost is zero.

## Result

A **clean, simple, fast, maintainable** framework with:
- ✅ Clear patterns (routing vs standalone)
- ✅ No global state
- ✅ Minimal complexity
- ✅ Easy to understand
- ✅ Easy to extend
- ✅ Fast execution

**Perfect foundation for future development.**
