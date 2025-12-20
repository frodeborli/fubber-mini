<!DOCTYPE html>
<html lang="<?php $this->show('lang', 'en'); ?>">
<head>
  <meta charset="UTF-8">
  <title><?php $this->show('title', 'My Site'); ?></title>
  <?php $this->show('head'); ?>
</head>
<body class="<?php $this->show('body-class', ''); ?>">
  <nav><?php $this->show('nav', '<a href="/">Home</a>'); ?></nav>

  <?php $this->show('layout'); ?>

  <footer><?php $this->show('footer', '© ' . date('Y')); ?></footer>
</body>
</html>
