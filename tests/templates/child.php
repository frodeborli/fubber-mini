<?php
// Extend parent layout
$extend('layout.php');

// Define title block
$start('title'); ?>Welcome, <?= htmlspecialchars($user['name']) ?><?php $end();

// Define header block
$start('header'); ?>
  Welcome to the site, <?= htmlspecialchars($user['name']) ?>!
<?php $end();

// Define content block
$start('content'); ?>
  <p>This is the main content area.</p>
  <p>User email: <?= htmlspecialchars($user['email']) ?></p>
<?php $end();
