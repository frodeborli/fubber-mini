<?php
// This extends layout-with-sidebar.php which extends base.php
$this->extend('layout-with-sidebar.php');

// Set page-specific blocks (inline syntax)
$this->block('title', 'Special Page');
$this->block('lang', 'en');

// Define sidebar content (buffered syntax)
$this->block('sidebar'); ?>
  <ul>
    <li><a href="#section1">Section 1</a></li>
    <li><a href="#section2">Section 2</a></li>
  </ul>
<?php $this->end();

// Define main content (buffered syntax)
$this->block('content'); ?>
  <h1>Welcome to Special Page</h1>
  <p>This page has three levels of inheritance:</p>
  <ol>
    <li>base.php (HTML structure)</li>
    <li>layout-with-sidebar.php (adds sidebar layout)</li>
    <li>special-page.php (adds content)</li>
  </ol>
  <p>User: <?= htmlspecialchars($user) ?></p>
<?php $this->end();

// Override footer (inline syntax)
$this->block('footer', 'Special Page © 2025');
