<!DOCTYPE html>
<html lang="<?php $show('lang', 'en'); ?>">
<head>
  <meta charset="UTF-8">
  <title><?php $show('title', 'My Site'); ?></title>
  <?php $show('head'); ?>
</head>
<body class="<?php $show('body-class', ''); ?>">
  <nav><?php $show('nav', '<a href="/">Home</a>'); ?></nav>

  <?php $show('layout'); ?>

  <footer><?php $show('footer', '© ' . date('Y')); ?></footer>
</body>
</html>
