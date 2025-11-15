# Template - PHP Template Rendering

## Philosophy

Mini's template system is **pure PHP with optional inheritance**:

- **Native PHP syntax** - No special template language to learn
- **Inheritance support** - Multi-level layouts via `$extend()` and `$block()`
- **Path registry** - Automatic template discovery in `_views/` directory
- **Zero configuration** - Works out of the box
- **Flexible** - Use inheritance, or just plain PHP files

## Setup

Templates are stored in `_views/` by default:

```
project/
├── _views/
│   ├── layout.php          # Main layout
│   ├── home.php            # Homepage template
│   ├── settings.php        # Settings page
│   └── partials/
│       └── user-card.php   # Reusable component
```

No configuration needed - Mini automatically finds templates in `_views/`.

## Basic Usage

### Simple Template (No Inheritance)

```php
// _routes/settings.php
echo render('settings.php', ['user' => $currentUser]);
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
<?php $extend('layout.php'); ?>
<?php $block('title', 'Home Page'); ?>
<?php $block('content'); ?>
    <h1>Welcome!</h1>
    <p>This is the home page content.</p>
<?php $end(); ?>
```

**Parent layout:**
```php
// _views/layout.php
<!DOCTYPE html>
<html>
<head>
    <title><?php $show('title', 'My App'); ?></title>
</head>
<body>
    <header>
        <nav>...</nav>
    </header>
    <main>
        <?php $show('content'); ?>
    </main>
    <footer>...</footer>
</body>
</html>
```

**Render:**
```php
// _routes/index.php
echo render('home.php');
```

## Template Helpers

Templates have access to four special helper functions:

### `$extend(string $layout)`
Marks the current template as extending a parent layout:

```php
<?php $extend('layout.php'); ?>
```

### `$block(string $name, ?string $value = null)`
Defines a block (two modes):

**Inline mode:**
```php
<?php $block('title', 'My Page Title'); ?>
```

**Buffered mode:**
```php
<?php $block('content'); ?>
    <p>Multiple lines of content...</p>
    <p>...captured until $end() is called.</p>
<?php $end(); ?>
```

### `$end()`
Ends a buffered block started with `$block()`:

```php
<?php $block('sidebar'); ?>
    <ul>
        <li>Item 1</li>
        <li>Item 2</li>
    </ul>
<?php $end(); ?>
```

### `$show(string $name, string $default = '')`
Outputs a block in parent templates:

```php
<title><?php $show('title', 'Default Title'); ?></title>
<div class="content"><?php $show('content'); ?></div>
```

## Multi-Level Inheritance

You can extend layouts that themselves extend other layouts:

```php
// _views/two-column.php
<?php $extend('layout.php'); ?>
<?php $block('content'); ?>
    <div class="row">
        <div class="col-main"><?php $show('main'); ?></div>
        <div class="col-sidebar"><?php $show('sidebar'); ?></div>
    </div>
<?php $end(); ?>
```

```php
// _views/dashboard.php
<?php $extend('two-column.php'); ?>
<?php $block('title', 'Dashboard'); ?>
<?php $block('main'); ?>
    <h1>Dashboard</h1>
    <p>Main content here</p>
<?php $end(); ?>
<?php $block('sidebar'); ?>
    <ul>
        <li>Sidebar item</li>
    </ul>
<?php $end(); ?>
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
$layout = $user['role'] === 'admin' ? 'admin-layout.php' : 'layout.php';
```

```php
// _views/page.php
<?php $extend($layout); ?>
<?php $block('content'); ?>
    <p>Content here</p>
<?php $end(); ?>
```

### Conditional Blocks

```php
// _views/layout.php
<!DOCTYPE html>
<html>
<head>
    <title><?php $show('title', 'My App'); ?></title>
    <?php if (isset($blocks['meta'])): ?>
        <?php $show('meta'); ?>
    <?php endif; ?>
</head>
<body>
    <?php $show('content'); ?>
</body>
</html>
```

### Block Default Content

```php
// _views/layout.php
<aside class="sidebar">
    <?php $show('sidebar', '<p>Default sidebar content</p>'); ?>
</aside>
```

### Escaping Output

Always escape user-provided data:

```php
<?= htmlspecialchars($user['name']) ?>

<!-- For HTML attributes -->
<input value="<?= htmlspecialchars($user['email'], ENT_QUOTES) ?>">

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

### Custom Renderer

Replace the default renderer:

```php
// _config/mini/Template/RendererInterface.php
return new App\CustomRenderer();
```

## How It Works

1. **Template Discovery**: Uses path registry to locate template files
2. **Rendering**: PHP file is included with extracted variables
3. **Block Capture**: `$block()` captures content via output buffering
4. **Inheritance**: If `$extend()` was called, re-render parent with blocks
5. **Output**: Final rendered content returned as string

The renderer handles multi-level inheritance by recursively rendering parent templates, passing captured blocks upward through the `__blocks` variable.

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

Rendering errors are caught and returned as strings for easier debugging during development.

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
<?php $extend('layout.php'); ?>
<?php $block('title', htmlspecialchars($post['title'])); ?>
<?php $block('content'); ?>
    <article>
        <h1><?= htmlspecialchars($post['title']) ?></h1>
        <time><?= $post['published_at'] ?></time>
        <div class="content">
            <?= $post['body'] ?> <!-- Already sanitized -->
        </div>
    </article>
<?php $end(); ?>
```

### Admin Dashboard

```php
// _views/admin/dashboard.php
<?php $extend('admin/layout.php'); ?>
<?php $block('title', 'Admin Dashboard'); ?>
<?php $block('content'); ?>
    <h1>Dashboard</h1>
    <div class="stats">
        <?= mini\render('admin/partials/stat-card.php', ['label' => 'Users', 'value' => $userCount]) ?>
        <?= mini\render('admin/partials/stat-card.php', ['label' => 'Posts', 'value' => $postCount]) ?>
    </div>
<?php $end(); ?>
```

### JSON API with Template

```php
// _routes/api/user.php
header('Content-Type: application/json');
echo render('api/user.json.php', ['user' => $user]);
```

```php
// _views/api/user.json.php
<?= json_encode([
    'id' => $user['id'],
    'name' => $user['name'],
    'email' => $user['email'],
], JSON_PRETTY_PRINT) ?>
```
