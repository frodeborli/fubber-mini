<?php
// Extend parent layout
$extend('layout.php');

// Use $set() for simple string values
$set('title', 'Page with $set()');
$set('header', 'Using $set() Helper');

// Can still use $start()/$end() for complex content
$start('content'); ?>
  <p>The $set() helper is a shorthand for simple values.</p>
  <p>User: <?= htmlspecialchars($user['name']) ?></p>
<?php $end();

// Mix and match as needed
$set('footer', '© 2025 Example Corp');
