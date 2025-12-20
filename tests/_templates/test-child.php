<?php
// Extend parent layout
$this->extend('layout.php');

// Define title block (using buffered syntax for dynamic content)
$this->block('title'); ?>Welcome, <?= htmlspecialchars($user['name']) ?><?php $this->end();

// Define header block
$this->block('header'); ?>
  Welcome to the site, <?= htmlspecialchars($user['name']) ?>!
<?php $this->end();

// Define content block
$this->block('content'); ?>
  <p>This is the main content area.</p>
  <p>User email: <?= htmlspecialchars($user['email']) ?></p>
<?php $this->end();
