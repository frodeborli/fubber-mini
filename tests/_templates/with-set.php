<?php
// Extend parent layout
$this->extend('layout.php');

// Use $this->block() inline for simple string values
$this->block('title', 'Page with dual-use $block()');
$this->block('header', 'Using Dual-Use $block() Helper');

// Use $this->block()/$this->end() for complex content
$this->block('content'); ?>
  <p>The $block() function works two ways: inline or buffered.</p>
  <p>User: <?= htmlspecialchars($user['name']) ?></p>
<?php $this->end();

// Inline for simple values
$this->block('footer', '© 2025 Example Corp');
