<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?php $block('title', 'Untitled'); ?></title>
</head>
<body>
  <header>
    <h1><?php $block('header', 'My Site'); ?></h1>
  </header>

  <main>
    <?php $block('content'); ?>
  </main>

  <footer>
    <?php $block('footer', '© ' . date('Y')); ?>
  </footer>
</body>
</html>
