<?php
// This extends layout-with-sidebar.php which extends base.php
$extend('layout-with-sidebar.php');

// Set page-specific blocks (inline syntax)
$block('title', 'Special Page');
$block('lang', 'en');

// Define sidebar content (buffered syntax)
$block('sidebar'); ?>
  <ul>
    <li><a href="#section1">Section 1</a></li>
    <li><a href="#section2">Section 2</a></li>
  </ul>
<?php $end();

// Define main content (buffered syntax)
$block('content'); ?>
  <h1>Welcome to Special Page</h1>
  <p>This page has three levels of inheritance:</p>
  <ol>
    <li>base.php (HTML structure)</li>
    <li>layout-with-sidebar.php (adds sidebar layout)</li>
    <li>special-page.php (adds content)</li>
  </ol>
  <p>User: <?= htmlspecialchars($user) ?></p>
<?php $end();

// Override footer (inline syntax)
$block('footer', 'Special Page © 2025');
