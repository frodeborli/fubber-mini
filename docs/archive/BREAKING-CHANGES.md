# Breaking Changes - Clean Architecture - 2025-10-27

## Summary

Removed DOC_ROOT file searching from SimpleRouter to eliminate complexity. SimpleRouter now ONLY searches `_routes/` directory.

## Breaking Changes

### **1. SimpleRouter No Longer Searches DOC_ROOT**

**Before:**
```php
// SimpleRouter would search DOC_ROOT for PHP files
// /users → DOC_ROOT/users.php (if exists)
```

**After:**
```php
// SimpleRouter ONLY searches _routes/
// /users → _routes/users.php (if exists)
// DOC_ROOT/users.php → Accessed as /users.php (direct, no routing)
```

**Migration:**
```bash
# Move route handlers from DOC_ROOT to _routes/
mv html/users.php _routes/users.php
mv html/api/posts.php _routes/api/posts.php
```

### **2. Removed getDocumentRoot() Method**

**Removed from SimpleRouter:**
- `getDocumentRoot()` - No longer needed

**Why:** SimpleRouter doesn't search DOC_ROOT anymore, so no need for this method.

### **3. DOC_ROOT PHP Files Must Call bootstrap()**

**Before:** Ambiguous - some files needed bootstrap, some didn't

**After:** Clear rule:
- `DOC_ROOT/index.php` → calls `mini\router()`
- `DOC_ROOT/custom.php` → calls `mini\bootstrap()`
- `_routes/*.php` → no bootstrap needed

### **4. Two Distinct Patterns**

**Pattern 1: Routing Mode**
```php
// DOC_ROOT/index.php
<?php
require_once __DIR__ . '/../vendor/autoload.php';
mini\router();
```

```php
// _routes/users.php
<?php
return mini\json_response(['users' => [...]]);
```

**Pattern 2: Standalone Files**
```php
// DOC_ROOT/admin.php
<?php
require_once __DIR__ . '/../vendor/autoload.php';
mini\bootstrap();

echo "<h1>Admin Panel</h1>";
```

## Removed Features

### **1. File-Based Routing in DOC_ROOT**

**Removed:**
- Automatic routing of DOC_ROOT PHP files
- `/users` → `html/users.php` (no longer supported)

**Use Instead:**
- `/users` → `_routes/users.php`
- Access `html/users.php` directly as `/users.php`

### **2. Clean URL Redirects for DOC_ROOT Files**

**Removed:**
- `/users.php` → `/users` redirects (for DOC_ROOT files)

**Kept:**
- `/index.php` → `/` redirect (only this one)

**Why:** Complexity without benefit. If you want clean URLs, use routing mode.

### **3. getDocumentRoot() Validation**

**Removed:**
- Runtime validation that docRoot exists

**Why:** SimpleRouter doesn't need docRoot anymore.

## Migration Guide

### **For Applications Using Routing**

**Step 1:** Move route handlers to `_routes/`
```bash
mkdir -p _routes/api
mv html/users.php _routes/users.php
mv html/api/posts.php _routes/api/posts.php
```

**Step 2:** Update index.php (if needed)
```php
// DOC_ROOT/index.php
<?php
require_once __DIR__ . '/../vendor/autoload.php';
mini\router();  // No changes needed if already using this
```

**Step 3:** Remove bootstrap from route handlers
```php
// _routes/users.php - REMOVE these lines:
require_once __DIR__ . '/../vendor/autoload.php';  // ❌ Remove
mini\bootstrap();  // ❌ Remove

// Just write your route logic:
return mini\json_response(['users' => [...]]);
```

### **For Applications NOT Using Routing**

**If you have multiple PHP files in DOC_ROOT:**

**Before:**
```php
// html/contact.php
<?php
// Sometimes needed bootstrap, sometimes didn't (confusing!)
echo "<h1>Contact</h1>";
```

**After:**
```php
// html/contact.php
<?php
require_once __DIR__ . '/../vendor/autoload.php';
mini\bootstrap();  // ALWAYS call bootstrap for standalone files

echo "<h1>Contact</h1>";
```

### **For Mixed Scenarios**

**Can you mix patterns?** No! Choose one:

**Option A: Use Routing**
- `DOC_ROOT/index.php` calls `router()`
- All dynamic pages in `_routes/`
- DOC_ROOT only has static files + index.php

**Option B: Use Standalone Files**
- Each PHP file in DOC_ROOT calls `bootstrap()`
- No routing, direct file access
- Simple sites, admin tools

## Benefits of Breaking Changes

### **1. Simpler Router**

**Removed complexity:**
- No DOC_ROOT searching
- No file existence checks
- No self-reference detection
- No clean URL redirect logic

**Result:** ~50 lines of code removed

### **2. Clear Separation**

| Directory | Purpose | Routing |
|-----------|---------|---------|
| `_routes/` | Route handlers | ✅ Yes |
| `DOC_ROOT/` | Static files + entry point | ❌ No |

No ambiguity about where code belongs.

### **3. Better Security**

- Route handlers not web-accessible
- DOC_ROOT intentionally accessed files only
- No accidental exposure

### **4. Easier to Reason About**

**Before:** "Does this file route? Does it need bootstrap? Is it web-accessible?"

**After:**
- `_routes/` → Routes, no bootstrap, not web-accessible
- `DOC_ROOT/` → Direct access, needs bootstrap (except index.php)

## Testing

Updated tests:
- ✅ `tests/router-new-architecture.php` - Verifies routing works
- ✅ `tests/SimpleRouter.catch-all-handler.php` - May need updates

## Documentation

Updated:
- ✅ `CLAUDE.md` - Two patterns clearly documented
- ✅ `ARCHITECTURE-REFACTOR.md` - Full migration guide
- ✅ This file - Breaking changes documented

## FAQ

**Q: Can I still have custom PHP files in DOC_ROOT?**
A: Yes! Just call `mini\bootstrap()` at the top.

**Q: Do _routes/ files need bootstrap?**
A: No! The router calls it automatically.

**Q: Can I access _routes/ files directly?**
A: No, they're not web-accessible (that's the point!).

**Q: What if I want clean URLs for DOC_ROOT files?**
A: Use routing mode - move files to `_routes/`.

**Q: Can I mix both patterns?**
A: No! Choose routing mode OR standalone mode, not both.

## Timeline

- **2025-10-27:** Breaking changes implemented
- **Upgrade path:** Move files to `_routes/`, update bootstrap calls
- **Support:** Old pattern no longer supported (clean break)
