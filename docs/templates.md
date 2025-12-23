# Template Writing Guide

This guide covers practical template patterns in Mini, from basic usage to real-world scenarios with multi-level layouts, reusable parts, and data handling.

## Quick Start

Templates are PHP files in `_views/`. Render them from controllers:

```php
$html = render('profile.php', ['user' => $user]);
```

```php
// _views/profile.php
<h1><?= htmlspecialchars($user->name) ?></h1>
<p><?= htmlspecialchars($user->bio) ?></p>
```

Variables passed to `render()` are extracted into the template scope.

## Template Resolution

When you call `render('profile.php')`, Mini searches for the template in this order:

1. **Application's `_views/`** - Your project's templates (checked first)
2. **Composer packages' `_views/`** - Any package that registers with `Mini::$mini->paths->views`
3. **Mini framework's `views/`** - Built-in fallback templates

The first match wins. This means you can override any template by placing a file with the same path in your application's `_views/` folder.

**Reusable template packages:** Composer packages can provide base layouts or reusable parts:

```php
// In a composer package's bootstrap file
Mini::$mini->paths->views->add(__DIR__ . '/_views');
```

For example, a `acme/bootstrap-layout` package could provide `_layout.php` and common parts. Your application can use them directly or override specific templates as needed.

## Template Inheritance

Templates can extend layouts using `$this->extend()`, define content with `$this->block()`, and layouts output that content with `$this->show()`.

### Child Template

```php
// _views/about.php
<?php $this->extend(); ?>

<?php $this->block('title', 'About Us'); ?>

<?php $this->block('content'); ?>
    <h1>About Us</h1>
    <p>We build software.</p>
<?php $this->end(); ?>
```

### Parent Layout

```php
// _views/_layout.php
<!DOCTYPE html>
<html>
<head>
    <title><?php $this->show('title', 'My Site'); ?></title>
    <?php $this->show('head'); ?>
</head>
<body>
    <nav>
        <a href="/">Home</a>
        <a href="/about">About</a>
    </nav>

    <main>
        <?php $this->show('content'); ?>
    </main>

    <footer>
        <?php $this->show('footer', '&copy; ' . date('Y')); ?>
    </footer>
</body>
</html>
```

### The Four Helpers

| Method | Purpose |
|--------|---------|
| `$this->extend($layout)` | Extend a parent layout. No argument uses the default from `_viewstart.php` |
| `$this->block($name, $value)` | Define a block. Inline if value given, or start buffering |
| `$this->end()` | End a buffered block |
| `$this->show($name, $default)` | Output a block in parent templates. Default is only evaluated if block is missing |

**Call order:** `extend()` must be called once, before defining any blocks.

**Inline vs Buffered blocks:**

```php
// Inline - for simple values
<?php $this->block('title', 'Contact Us'); ?>

// Buffered - for HTML content
<?php $this->block('content'); ?>
    <h1>Contact</h1>
    <form>...</form>
<?php $this->end(); ?>
```

**Block override behavior:** If you define the same block twice, the last definition wins. Inline blocks overwrite buffered ones and vice versa.

## ViewStart: Setting Up Templates

`_viewstart.php` files run before templates render. Use them to set defaults and bring in common variables.

### Root ViewStart

```php
// _views/_viewstart.php
<?php
// Default layout for all templates
$layout = '_layout.php';

// Common helpers available in all templates
$h = fn($s) => htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');  // HTML text
$a = fn($s) => htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');  // HTML attributes (same, but semantic)
$fmt = mini\fmt();
$t = fn($text, $vars = []) => mini\t($text, $vars);

// Current user (if authenticated)
$currentUser = mini\auth()->user();
```

Now every template has access to `$h()`, `$a()`, `$fmt`, `$t()`, and `$currentUser` without passing them explicitly.

### Section-Specific ViewStart

Create `_viewstart.php` in subdirectories to customize sections:

```php
// _views/admin/_viewstart.php
<?php
$layout = 'admin/_layout.php';  // Admin section uses different layout
$adminSection = true;

// Require authentication for all admin templates
if (!$currentUser || !$currentUser->isAdmin()) {
    mini\redirect('/login');
}
```

**Execution order** for `_views/admin/users/list.php`:
1. `_views/_viewstart.php` (sets global defaults)
2. `_views/admin/_viewstart.php` (sets admin defaults, overrides `$layout`)
3. `_views/admin/users/list.php` (your template)

## Real-World Layout Structure

A typical site has a base layout, section layouts, and page templates.

### Directory Structure

```
_views/
├── _viewstart.php          # Global: $layout, $h, $fmt, $currentUser
├── _layout.php             # Base HTML structure
├── home.php                # Homepage
├── about.php               # Static page
├── admin/
│   ├── _viewstart.php      # Admin: different layout, auth check
│   ├── _layout.php         # Admin layout with sidebar
│   ├── dashboard.php
│   └── users/
│       ├── list.php
│       └── edit.php
├── blog/
│   ├── _viewstart.php      # Blog: adds $categories
│   ├── _layout.php         # Blog layout with sidebar
│   ├── index.php
│   └── post.php
└── parts/
    ├── user-badge.php      # Reusable components
    ├── pagination.php
    └── flash-messages.php
```

### Base Layout

```php
// _views/_layout.php
<!DOCTYPE html>
<html lang="<?php $this->show('lang', 'en'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php $this->show('title', 'My Site'); ?></title>
    <link rel="stylesheet" href="/css/app.css">
    <?php $this->show('head'); ?>
</head>
<body class="<?php $this->show('body-class'); ?>">
    <header>
        <nav class="main-nav">
            <a href="/" class="logo">MySite</a>
            <ul>
                <li><a href="/">Home</a></li>
                <li><a href="/blog">Blog</a></li>
                <li><a href="/about">About</a></li>
            </ul>
            <?php if ($currentUser): ?>
                <span>Welcome, <?= $h($currentUser->name) ?></span>
            <?php else: ?>
                <a href="/login">Login</a>
            <?php endif; ?>
        </nav>
    </header>

    <?= render('parts/flash-messages.php') ?>

    <main>
        <?php $this->show('content'); ?>
    </main>

    <footer>
        <?php $this->show('footer', '<p>&copy; ' . date('Y') . ' My Site</p>'); ?>
    </footer>

    <script src="/js/app.js"></script>
    <?php $this->show('scripts'); ?>
</body>
</html>
```

### Section Layout (Blog)

The blog layout extends the base layout and adds a sidebar:

```php
// _views/blog/_layout.php
<?php $this->extend('_layout.php'); ?>

<?php $this->block('body-class', 'blog-section'); ?>

<?php $this->block('content'); ?>
<div class="blog-container">
    <div class="blog-content">
        <?php $this->show('blog-content'); ?>
    </div>
    <aside class="blog-sidebar">
        <h3>Categories</h3>
        <ul>
            <?php foreach ($categories as $cat): ?>
                <li><a href="/blog?category=<?= $cat->id ?>"><?= $h($cat->name) ?></a></li>
            <?php endforeach; ?>
        </ul>
        <?php $this->show('sidebar'); ?>
    </aside>
</div>
<?php $this->end(); ?>
```

```php
// _views/blog/_viewstart.php
<?php
$layout = 'blog/_layout.php';

// Load categories for sidebar (available in all blog templates)
$categories = db()->query('SELECT * FROM categories ORDER BY name')->fetchAll();
```

### Page Template (Blog Post)

```php
// _views/blog/post.php
<?php $this->extend(); ?>

<?php $this->block('title', $h($post->title) . ' - Blog'); ?>

<?php $this->block('head'); ?>
<meta property="og:title" content="<?= $h($post->title) ?>">
<meta property="og:description" content="<?= $h($post->excerpt) ?>">
<?php $this->end(); ?>

<?php $this->block('blog-content'); ?>
<article class="post">
    <header>
        <h1><?= $h($post->title) ?></h1>
        <p class="meta">
            By <?= render('parts/user-badge.php', ['user' => $post->author]) ?>
            on <?= $fmt->date($post->published_at) ?>
        </p>
    </header>

    <div class="content">
        <?= $post->content ?>
    </div>

    <footer>
        <p>Tags:
            <?php foreach ($post->tags as $tag): ?>
                <a href="/blog?tag=<?= $tag->id ?>"><?= $h($tag->name) ?></a>
            <?php endforeach; ?>
        </p>
    </footer>
</article>
<?php $this->end(); ?>

<?php $this->block('sidebar'); ?>
<h3>Related Posts</h3>
<ul>
    <?php foreach ($relatedPosts as $related): ?>
        <li><a href="/blog/<?= $related->slug ?>"><?= $h($related->title) ?></a></li>
    <?php endforeach; ?>
</ul>
<?php $this->end(); ?>
```

### Admin Layout

```php
// _views/admin/_layout.php
<?php $this->extend('_layout.php'); ?>

<?php $this->block('body-class', 'admin-section'); ?>

<?php $this->block('content'); ?>
<div class="admin-container">
    <nav class="admin-sidebar">
        <ul>
            <li><a href="/admin">Dashboard</a></li>
            <li><a href="/admin/users">Users</a></li>
            <li><a href="/admin/posts">Posts</a></li>
            <li><a href="/admin/settings">Settings</a></li>
        </ul>
    </nav>
    <div class="admin-content">
        <h1><?php $this->show('page-title', 'Admin'); ?></h1>
        <?php $this->show('admin-content'); ?>
    </div>
</div>
<?php $this->end(); ?>
```

## Reusable Template Parts

For repeated UI components, create small templates in `parts/` and include them with `render()`.

### User Badge

```php
// _views/parts/user-badge.php
<span class="user-badge">
    <img src="<?= $h($user->avatar_url ?? '/img/default-avatar.png') ?>"
         alt="<?= $h($user->name) ?>"
         class="avatar">
    <a href="/users/<?= $user->id ?>"><?= $h($user->name) ?></a>
</span>
```

Usage:
```php
<?= render('parts/user-badge.php', ['user' => $post->author]) ?>
```

### Flash Messages

```php
// _views/parts/flash-messages.php
<?php $messages = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); ?>
<?php if ($messages): ?>
<div class="flash-messages">
    <?php foreach ($messages as $type => $text): ?>
        <div class="flash flash-<?= $type ?>"><?= $h($text) ?></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
```

### Pagination

```php
// _views/parts/pagination.php
<?php
// Expects: $query (PartialQuery), $baseUrl (string)
$page = (int)($_GET['page'] ?? 1);
$perPage = 20;
$total = $query->count();
$totalPages = (int)ceil($total / $perPage);

if ($totalPages <= 1) return;
?>
<nav class="pagination">
    <?php if ($page > 1): ?>
        <a href="<?= $baseUrl ?>?page=<?= $page - 1 ?>">&laquo; Prev</a>
    <?php endif; ?>

    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
        <?php if ($i === $page): ?>
            <span class="current"><?= $i ?></span>
        <?php else: ?>
            <a href="<?= $baseUrl ?>?page=<?= $i ?>"><?= $i ?></a>
        <?php endif; ?>
    <?php endfor; ?>

    <?php if ($page < $totalPages): ?>
        <a href="<?= $baseUrl ?>?page=<?= $page + 1 ?>">Next &raquo;</a>
    <?php endif; ?>
</nav>
```

## Passing Queries to Templates

Keep controllers simple by passing query objects rather than paginated results. Templates handle pagination, sorting, and display logic.

### Controller

```php
// _routes/admin/users.php
$users = db()->from('users')
    ->where('deleted_at IS NULL')
    ->orderBy('created_at DESC');

// Optionally apply filters from request
if ($search = $_GET['search'] ?? null) {
    $users = $users->where('name LIKE ? OR email LIKE ?', ["%$search%", "%$search%"]);
}

if ($role = $_GET['role'] ?? null) {
    $users = $users->where('role = ?', [$role]);
}

echo render('admin/users/list.php', [
    'users' => $users,  // Pass the query, not results
    'search' => $search,
    'role' => $role,
]);
```

### Template with Pagination

```php
// _views/admin/users/list.php
<?php $this->extend(); ?>

<?php $this->block('title', 'Manage Users'); ?>
<?php $this->block('page-title', 'Users'); ?>

<?php $this->block('admin-content'); ?>

<?php
// Template handles pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$total = $users->count();
$totalPages = (int)ceil($total / $perPage);

$pagedUsers = $users
    ->limit($perPage)
    ->offset(($page - 1) * $perPage)
    ->fetchAll();
?>

<div class="toolbar">
    <form method="get" class="search-form">
        <input type="text" name="search" value="<?= $h($search ?? '') ?>" placeholder="Search...">
        <select name="role">
            <option value="">All Roles</option>
            <option value="admin" <?= ($role ?? '') === 'admin' ? 'selected' : '' ?>>Admin</option>
            <option value="user" <?= ($role ?? '') === 'user' ? 'selected' : '' ?>>User</option>
        </select>
        <button type="submit">Filter</button>
    </form>
    <a href="/admin/users/new" class="btn btn-primary">Add User</a>
</div>

<p>Showing <?= count($pagedUsers) ?> of <?= $total ?> users</p>

<table class="data-table">
    <thead>
        <tr>
            <th>Name</th>
            <th>Email</th>
            <th>Role</th>
            <th>Created</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($pagedUsers as $user): ?>
        <tr>
            <td><?= render('parts/user-badge.php', ['user' => $user]) ?></td>
            <td><?= $h($user->email) ?></td>
            <td><span class="badge badge-<?= $user->role ?>"><?= $user->role ?></span></td>
            <td><?= $fmt->dateShort($user->created_at) ?></td>
            <td>
                <a href="/admin/users/<?= $user->id ?>/edit">Edit</a>
                <a href="/admin/users/<?= $user->id ?>/delete"
                   onclick="return confirm('Delete this user?')">Delete</a>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?= render('parts/pagination.php', ['query' => $users, 'baseUrl' => '/admin/users']) ?>

<?php $this->end(); ?>
```

### Why Pass Queries?

1. **Separation of concerns** - Controllers filter and authorize; templates present
2. **Flexibility** - Template can paginate, sort, or display differently without controller changes
3. **Lazy evaluation** - Count and fetch happen only when needed
4. **Testability** - Mock the query object for template testing

**Guidelines:** Templates may perform read-only query operations (pagination, counting, fetching). Avoid joins, conditional mutations, or business logic branching in templates—keep that in controllers.

## Escaping Output

Always escape user-provided data. Use the `$h()` and `$a()` helpers set up in `_viewstart.php`:

```php
// Text content - use $h()
<h1><?= $h($user->name) ?></h1>

// Attribute values - use $a() (semantically clearer, same function)
<input type="text" value="<?= $a($user->name) ?>" placeholder="<?= $a($placeholder) ?>">

// UNSAFE - never do this with user data
<h1><?= $user->name ?></h1>
```

**Escaping contexts:** `$h()` and `$a()` escape for HTML text and attribute contexts only. For other contexts:

```php
// URL query parameters - use urlencode()
<a href="/search?q=<?= urlencode($query) ?>">Search</a>

// JavaScript - use json_encode()
<script>const user = <?= json_encode($user->name) ?>;</script>
```

**When NOT to escape:**
- Content already sanitized (e.g., HTML from a trusted WYSIWYG editor stored safely)
- URLs you construct yourself (but escape query parameters)

```php
// Pre-sanitized content (trust the storage, not the display)
<div class="content"><?= $post->html_content ?></div>
```

## Template Organization Tips

### Naming Conventions

- Layouts: `_layout.php` (underscore prefix)
- ViewStart: `_viewstart.php`
- Parts: `parts/component-name.php` (kebab-case)
- Pages: `page-name.php` or `section/page-name.php`

### Keep Templates Focused

Templates should primarily contain HTML with minimal logic. Move complex logic to:
- Controllers (data fetching, authorization)
- ViewStart files (common setup)
- Helper functions (formatting, calculations)

**Too much logic:**
```php
<?php
// Bad - this belongs in a controller or service
$users = db()->query('SELECT * FROM users WHERE ...');
$stats = array_reduce($users, function($acc, $user) { ... });
?>
```

**Just right:**
```php
<?php
// Good - template receives prepared data
foreach ($users as $user):
?>
    <li><?= $h($user->name) ?></li>
<?php endforeach; ?>
```

### Conditional Blocks

Check if optional blocks were defined:

```php
// _views/_layout.php
<?php if (isset($this->blocks['sidebar'])): ?>
<aside class="sidebar">
    <?php $this->show('sidebar'); ?>
</aside>
<?php endif; ?>
```

## Complete Example: Blog Application

### Directory Structure

```
_views/
├── _viewstart.php
├── _layout.php
├── home.php
├── blog/
│   ├── _viewstart.php
│   ├── _layout.php
│   ├── index.php
│   └── post.php
└── parts/
    ├── post-card.php
    ├── pagination.php
    └── user-badge.php
```

### Root ViewStart

```php
// _views/_viewstart.php
<?php
$layout = '_layout.php';
$h = fn($s) => htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
$a = fn($s) => htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
$fmt = mini\fmt();
$currentUser = mini\auth()->user();
```

### Blog ViewStart

```php
// _views/blog/_viewstart.php
<?php
$layout = 'blog/_layout.php';
$categories = db()->query('SELECT * FROM categories ORDER BY name')->fetchAll();
$popularTags = db()->query('SELECT * FROM tags ORDER BY post_count DESC LIMIT 10')->fetchAll();
```

### Blog Index (with query passed from controller)

```php
// _views/blog/index.php
<?php $this->extend(); ?>

<?php $this->block('title', 'Blog'); ?>

<?php $this->block('blog-content'); ?>
<?php
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$pagedPosts = $posts->limit($perPage)->offset(($page - 1) * $perPage)->fetchAll();
?>

<h1>Latest Posts</h1>

<div class="post-grid">
    <?php foreach ($pagedPosts as $post): ?>
        <?= render('parts/post-card.php', ['post' => $post]) ?>
    <?php endforeach; ?>
</div>

<?= render('parts/pagination.php', ['query' => $posts, 'baseUrl' => '/blog']) ?>

<?php $this->end(); ?>
```

### Post Card Part

```php
// _views/parts/post-card.php
<article class="post-card">
    <?php if ($post->featured_image): ?>
        <img src="<?= $h($post->featured_image) ?>" alt="">
    <?php endif; ?>
    <div class="post-card-body">
        <h2><a href="/blog/<?= $h($post->slug) ?>"><?= $h($post->title) ?></a></h2>
        <p class="meta">
            <?= render('parts/user-badge.php', ['user' => $post->author]) ?>
            &middot; <?= $fmt->dateShort($post->published_at) ?>
        </p>
        <p><?= $h($post->excerpt) ?></p>
    </div>
</article>
```

This structure scales well: add new sections by creating a subdirectory with its own `_viewstart.php` and `_layout.php`, inheriting from the base while customizing as needed.
