<?php
// Extend parent layout
$extend('layout.php');

// Define title block (using buffered syntax for dynamic content)
$block('title'); ?>Welcome, <?= htmlspecialchars($user['name']) ?><?php $end();

// Define header block
$block('header'); ?>
  Welcome to the site, <?= htmlspecialchars($user['name']) ?>!
<?php $end();

// Define content block
$block('content'); ?>
  <p>This is the main content area.</p>
  <p>User email: <?= htmlspecialchars($user['email']) ?></p>
<?php $end();
