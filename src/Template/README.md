# Template - PHP Template Rendering

Pure-PHP templates with .NET-style multi-level inheritance — no template language for a forkable core to maintain, just PHP.

Templates are **compiled** before they run: `TemplateCompiler` translates every output-producing
construct (inline HTML, `<?= ?>`, `echo`, `print`, `printf()`, `vprintf()`) into a call that writes
through `TemplateContext` to a `RenderSink`, instead of relying on PHP's output buffer
(`ob_start()`/`ob_get_clean()`). An output buffer belongs to the whole process, not to one
`render()` call — on a runtime where requests can interleave inside a process (a worker suspended
mid-template on a database call), that mixes one request's HTML into another's buffer. A
`RenderSink` is a plain object local to one render, so this can't happen. See "Compilation" below
for what changes, what's forbidden, and what's still possible but out of scope.

## Philosophy

Mini's template system is **pure PHP with optional inheritance**:

- **Native PHP syntax** - No special template language to learn
- **Inheritance support** - Multi-level layouts via `$this->extend()` and `$this->block()`
- **ViewStart convention** - Automatic `_viewstart.php` inclusion (like ASP.NET Core)
- **Path registry** - Automatic template discovery in `_views/` directory
- **Zero configuration** - Works out of the box with sensible defaults
- **Flexible** - Use inheritance, or just plain PHP files

## Setup

Templates are stored in `_views/` by default:

```
project/
├── _views/
│   ├── _viewstart.php      # Auto-included, sets $layout default
│   ├── _layout.php         # Main layout
│   ├── home.php            # Homepage template
│   ├── settings.php        # Settings page
│   ├── admin/
│   │   ├── _viewstart.php  # Admin-specific setup (optional)
│   │   └── dashboard.php
│   └── partials/
│       └── user-card.php   # Reusable component
```

No configuration needed - Mini automatically finds templates in `_views/`.

## Basic Usage

### Simple Template (No Inheritance)

```php
// _routes/settings.php
return new mini\Http\Message\HtmlResponse(render('settings.php', ['user' => $currentUser]));
```

```php
// _views/settings.php
<h1>Settings for <?= htmlspecialchars($user['name']) ?></h1>
<form>
    <input name="email" value="<?= htmlspecialchars($user['email']) ?>">
    <button>Save</button>
</form>
```

### Template with Layout Inheritance

**Child template:**
```php
// _views/home.php
<?php $this->extend(); ?>  <!-- Uses $layout from _viewstart.php -->
<?php $this->block('title', 'Home Page'); ?>
<?php $this->block('content'); ?>
    <h1>Welcome!</h1>
    <p>This is the home page content.</p>
<?php $this->end(); ?>
```

**Parent layout:**
```php
// _views/_layout.php
<!DOCTYPE html>
<html>
<head>
    <title><?php $this->show('title', 'My App'); ?></title>
</head>
<body>
    <header>
        <nav>...</nav>
    </header>
    <main>
        <?php $this->show('content'); ?>
    </main>
    <footer>...</footer>
</body>
</html>
```

**Render:**
```php
// _routes/index.php
return new mini\Http\Message\HtmlResponse(render('home.php'));
```

## Template Helpers

Templates have access to four methods on `$this`:

### `$this->extend(?string $layout = null)`
Marks the current template as extending a parent layout:

```php
<?php $this->extend(); ?>              // Use default $layout from _viewstart.php
<?php $this->extend('_layout.php'); ?> // Explicit layout
```

### `$this->block(string $name, ?string $value = null)`
Defines a block (two modes):

**Inline mode:**
```php
<?php $this->block('title', 'My Page Title'); ?>
```

**Buffered mode:**
```php
<?php $this->block('content'); ?>
    <p>Multiple lines of content...</p>
    <p>...captured until $this->end() is called.</p>
<?php $this->end(); ?>
```

### `$this->end()`
Ends a buffered block started with `$this->block()`:

```php
<?php $this->block('sidebar'); ?>
    <ul>
        <li>Item 1</li>
        <li>Item 2</li>
    </ul>
<?php $this->end(); ?>
```

### `$this->show(string $name, string $default = '')`
Outputs a block in parent templates:

```php
<title><?php $this->show('title', 'Default Title'); ?></title>
<div class="content"><?php $this->show('content'); ?></div>
```

## ViewStart Files

Mini automatically includes `_viewstart.php` files before rendering templates, similar to ASP.NET Core's `_ViewStart.cshtml`. This is useful for:

- Setting a default `$layout` for templates
- Defining variables available to all templates in a directory tree
- Running setup code before templates render

### How It Works

When rendering `admin/users/list.php`, Mini includes `_viewstart.php` files in order:

1. `_views/_viewstart.php` (root - runs first)
2. `_views/admin/_viewstart.php` (if exists)
3. `_views/admin/users/_viewstart.php` (if exists - runs last)

Later files can override variables set by earlier files.

### Default Layout

The root `_viewstart.php` typically sets the default layout:

```php
// _views/_viewstart.php
<?php
$layout = '_layout.php';
```

Templates can then use `$this->extend()` without arguments:

```php
// _views/page.php
<?php $this->extend(); ?>  // Uses '_layout.php' from _viewstart.php
<?php $this->block('content'); ?>
    <p>Page content</p>
<?php $this->end(); ?>
```

### Section-Specific Setup

Create `_viewstart.php` in subdirectories for section-specific configuration:

```php
// _views/admin/_viewstart.php
<?php
$layout = '_admin-layout.php';  // Override default layout for admin
$adminSection = true;           // Variable available to all admin templates
```

```php
// _views/admin/dashboard.php
<?php $this->extend(); ?>  // Uses '_admin-layout.php'
<?php $this->block('content'); ?>
    <h1>Admin Dashboard</h1>
    <?php if ($adminSection): ?>
        <p>You are in the admin section.</p>
    <?php endif; ?>
<?php $this->end(); ?>
```

## Multi-Level Inheritance

You can extend layouts that themselves extend other layouts:

```php
// _views/two-column.php
<?php $this->extend('_layout.php'); ?>
<?php $this->block('content'); ?>
    <div class="row">
        <div class="col-main"><?php $this->show('main'); ?></div>
        <div class="col-sidebar"><?php $this->show('sidebar'); ?></div>
    </div>
<?php $this->end(); ?>
```

```php
// _views/dashboard.php
<?php $this->extend('two-column.php'); ?>
<?php $this->block('title', 'Dashboard'); ?>
<?php $this->block('main'); ?>
    <h1>Dashboard</h1>
    <p>Main content here</p>
<?php $this->end(); ?>
<?php $this->block('sidebar'); ?>
    <ul>
        <li>Sidebar item</li>
    </ul>
<?php $this->end(); ?>
```

## Including Partials

Use `render()` to include reusable components:

```php
// _views/users.php
<div class="users">
    <?php foreach ($users as $user): ?>
        <?= mini\render('partials/user-card.php', ['user' => $user]) ?>
    <?php endforeach; ?>
</div>
```

```php
// _views/partials/user-card.php
<div class="user-card">
    <img src="<?= htmlspecialchars($user['avatar']) ?>">
    <h3><?= htmlspecialchars($user['name']) ?></h3>
    <p><?= htmlspecialchars($user['email']) ?></p>
</div>
```

## Advanced Examples

### Dynamic Layouts

```php
// Choose layout based on user role
$layout = $user['role'] === 'admin' ? '_admin-layout.php' : '_layout.php';
```

```php
// _views/page.php
<?php $this->extend($layout); ?>
<?php $this->block('content'); ?>
    <p>Content here</p>
<?php $this->end(); ?>
```

### Conditional Blocks

```php
// _views/_layout.php
<!DOCTYPE html>
<html>
<head>
    <title><?php $this->show('title', 'My App'); ?></title>
    <?php if (isset($this->allBlocks()['meta'])): ?>
        <?php $this->show('meta'); ?>
    <?php endif; ?>
</head>
<body>
    <?php $this->show('content'); ?>
</body>
</html>
```

### Block Default Content

```php
// _views/_layout.php
<aside class="sidebar">
    <?php $this->show('sidebar', '<p>Default sidebar content</p>'); ?>
</aside>
```

### Escaping Output

Always escape user-provided data. Use the `mini\h()` helper for concise escaping:

```php
<?= mini\h($user['name']) ?>

<!-- Equivalent to: -->
<?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?>

<!-- For HTML attributes -->
<input value="<?= mini\h($user['email']) ?>">

<!-- For JSON in inline scripts -->
<script>
    const user = <?= json_encode($user, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
```

## Configuration

### Custom Views Directory

Set via environment variable:

```bash
MINI_VIEWS_ROOT=/path/to/custom/views
```

Or configure programmatically:

```php
// Before render() is first called
Mini::$mini->paths->views = new PathsRegistry('/custom/views');
```

### Multiple View Paths

Add additional search paths:

```php
// Search in theme directory, then fallback to default views
Mini::$mini->paths->views->addPath('/path/to/theme/views');
```

Templates are found using first-match from the path registry.

### Resolution Order

Templates are resolved using PathRegistry:

1. **Application**: `_views/` (or `MINI_VIEWS_ROOT` env var) - always first (primary path)
2. **Third-party packages**: Their `_views/` directories (most recently added first)
3. **Framework**: `vendor/fubber/mini/_views/` - always last

### Composer Package Integration

Third-party Composer packages can provide templates by calling `addPath()` during bootstrap. Since Composer loads `autoload.files` in dependency graph order (framework → packages → application), and `addPath()` inserts new paths before earlier fallbacks, the resolution order naturally allows packages to override framework templates.

**Package composer.json:**

```json
{
    "name": "vendor/my-package",
    "autoload": {
        "files": ["src/bootstrap.php"]
    }
}
```

**Package bootstrap.php:**

```php
use mini\Mini;

Mini::$mini->paths->views->addPath(__DIR__ . '/../_views');
```

**Package structure:**

```
my-package/
├── _views/
│   └── my-package/
│       ├── widget.php
│       └── partials/
└── src/
    └── bootstrap.php
```

Templates become available as `my-package/widget.php` etc.

To override a package's template, place your version at the same relative path in your application's `_views/` directory.

### Custom Renderer

Replace the default renderer:

```php
// _config/mini/Template/RendererInterface.php
return new App\CustomRenderer();
```

## How It Works

1. **Template Discovery**: Uses path registry to locate template files
2. **Compilation**: `TemplateCompiler` translates the file into plain PHP (cached - see below)
3. **ViewStart**: Includes `_viewstart.php` files from root to template directory (stacked)
4. **Rendering**: Compiled PHP file is included with extracted variables
5. **Block Capture**: `$this->block()` captures content through the render sink
6. **Inheritance**: If `$this->extend()` was called, re-render parent with blocks
7. **Output**: Final rendered content returned as string

The renderer handles multi-level inheritance by recursively rendering parent templates, passing captured blocks upward through the `__blocks` variable. ViewStart files only run for the initial template, not for parent layouts.

## Compilation

A template file is never included as-is. `TemplateCompiler::compile()` tokenizes it with
`PhpToken::tokenize()` (no regexes) and translates every construct that would otherwise write
directly:

| Source | Compiles to |
|---|---|
| inline HTML (text outside `<?php ... ?>`) | `$this->echo('...')` |
| `<?= expr ?>` | `$this->echo(expr)` |
| `echo a, b;` | `$this->echo(a, b);` |
| `print expr` | `$this->print(expr)` - still an expression, still evaluates to `int(1)` |
| `printf(...)` / `vprintf(...)` | `$this->printf(...)` / `$this->vprintf(...)` - arguments untouched |

Everything else — control structures, alternative syntax (`if: ... endif;`), heredoc/nowdoc,
assignments, your own function and method calls — passes through completely unchanged. A call
written as `->printf(`, `::printf(`, `?->printf(`, or a function/method declaration named
`printf`/`vprintf`, is left alone too; only a plain, unqualified call to the global function is
rewritten.

`$this->echo()`, `$this->print()`, `$this->printf()` and `$this->vprintf()` are `TemplateContext`
methods that write to the active `RenderSink` instead of producing direct output. They're what
your template's `echo`/`print`/`printf`/`vprintf` turn into — you never call them yourself.

**Line numbers are exact.** No newline is ever added or removed by compilation — inline HTML's
own newlines stay inside its string literal — so a thrown exception's line, and every line in a
backtrace through a compiled template, is the same line number the source template has.

### Forbidden constructs

Compiling a template fails, with an error naming the file and line, for:

- **`var_dump()` and `debug_zval_dump()`** - always, since they only ever write directly.
- **`var_export()` and `print_r()` called without their second (return) argument** - with it,
  they return a string instead of writing, which is fine: `var_export($value, true)`.
- **`include`/`include_once`/`require`/`require_once`** - raw inclusion bypasses compilation
  entirely (the included file would still try to produce direct output). Use `mini\render()` for
  partials and `$this->extend()`/`$this->block()` for layout inheritance instead.

### What's not detected (a documented limitation)

This is a short, deliberate list — not an exhaustive scan for every way PHP can write output. A
function or method called from a template that outputs directly (an `echo` inside a plain helper
class, `ob_*`, `flush()`, `readfile()`, `fpassthru()`, ...) is **not compiled and not detected**:
its output bypasses the sink and is lost (or, worse, lands in whatever output buffer happens to be
active). Such code should return a string (or `Stringable`) instead of printing it.

One subtler case of the same limitation: `$this->echo()`/`$this->printf()`/`$this->vprintf()` are
plain variadic methods, so all of their arguments are evaluated *before* any of them is written -
unlike the real `echo`/`printf()`, which write as they evaluate each argument. A construct that
writes as a side effect while sitting inside another one's argument list (`print`/`printf()` used
inside the same `echo`/`<?= ?>`) therefore writes out of the order a hand-written `echo` would
produce. This essentially never comes up in normal templates (`echo print $x;` is not something
anyone writes on purpose) - it only matters if you nest these constructs in one statement.

## Compiled file cache

Compiled files are ordinary PHP files, written under Mini's cache directory
(`<project root>/_cache` by default, override with the `MINI_CACHE_ROOT` environment variable),
mirroring the template's path relative to the project root under a `templates/` subdirectory - for
example `_views/blog/post.php` compiles to `<cache>/templates/_views/blog/post.php`. The path is
never a hash: a backtrace through the compiled file makes the source template obvious at a glance.

A template is recompiled when its source file is newer than the compiled file (or the compiled
file doesn't exist yet). The new file is written atomically - compiled to a temporary file in the
same directory, then `rename()`d into place - so many php-fpm workers racing to compile the same
template at once is harmless: compilation is a pure function of the source, every racing writer
produces byte-identical output, and `rename()` never leaves a reader looking at a half-written
file. `opcache_invalidate()` is called on every recompile, so this also works correctly under
`opcache.validate_timestamps=0`.

## Template Variables

All variables passed to `render()` are available directly:

```php
echo render('user.php', ['name' => 'John', 'email' => 'john@example.com']);
```

```php
// _views/user.php - $name and $email are available
<h1><?= htmlspecialchars($name) ?></h1>
<p><?= htmlspecialchars($email) ?></p>
```

## Error Handling

If a template is not found, an exception is thrown:

```php
try {
    echo render('missing.php');
} catch (\Exception $e) {
    // Template not found: missing.php (searched in: /path/to/_views)
}
```

Exceptions thrown while rendering are re-thrown after discarding whatever the render sink had
captured so far — partial output is never emitted. A template using a forbidden construct (see
"Compilation" above) throws `TemplateCompileException` the first time it's rendered, naming the
template file and line.

## Best Practices

1. **Always escape output** - Use `htmlspecialchars()` for user data
2. **Keep logic minimal** - Templates should be mostly HTML
3. **Use partials for reuse** - Don't repeat card/list markup
4. **Consistent naming** - Use kebab-case for template filenames
5. **Organize by feature** - Group related templates in subdirectories

## Examples Repository

### Blog Post Layout

```php
// _views/blog/post.php
<?php $this->extend(); ?>
<?php $this->block('title', htmlspecialchars($post['title'])); ?>
<?php $this->block('content'); ?>
    <article>
        <h1><?= htmlspecialchars($post['title']) ?></h1>
        <time><?= $post['published_at'] ?></time>
        <div class="content">
            <?= $post['body'] ?> <!-- Already sanitized -->
        </div>
    </article>
<?php $this->end(); ?>
```

### Admin Dashboard

```php
// _views/admin/dashboard.php
<?php $this->extend('admin/_layout.php'); ?>
<?php $this->block('title', 'Admin Dashboard'); ?>
<?php $this->block('content'); ?>
    <h1>Dashboard</h1>
    <div class="stats">
        <?= mini\render('admin/partials/stat-card.php', ['label' => 'Users', 'value' => $userCount]) ?>
        <?= mini\render('admin/partials/stat-card.php', ['label' => 'Posts', 'value' => $postCount]) ?>
    </div>
<?php $this->end(); ?>
```

### JSON API with Template

```php
// _routes/api/user.php
return new mini\Http\Message\Response(
    render('api/user.json.php', ['user' => $user]),
    ['Content-Type' => 'application/json']
);
```

```php
// _views/api/user.json.php
<?= json_encode([
    'id' => $user['id'],
    'name' => $user['name'],
    'email' => $user['email'],
], JSON_PRETTY_PRINT) ?>
```
