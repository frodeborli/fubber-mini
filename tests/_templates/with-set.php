<?php
// Extend parent layout
$extend('layout.php');

// Use $block() inline for simple string values
$block('title', 'Page with dual-use $block()');
$block('header', 'Using Dual-Use $block() Helper');

// Use $block()/$end() for complex content
$block('content'); ?>
  <p>The $block() function works two ways: inline or buffered.</p>
  <p>User: <?= htmlspecialchars($user['name']) ?></p>
<?php $end();

// Inline for simple values
$block('footer', '© 2025 Example Corp');
