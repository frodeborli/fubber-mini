<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?php $show('title', 'Untitled'); ?></title>
</head>
<body>
  <header>
    <h1><?php $show('header', 'My Site'); ?></h1>
  </header>

  <main>
    <?php $show('content'); ?>
  </main>

  <footer>
    <?php $show('footer', '© ' . date('Y')); ?>
  </footer>
</body>
</html>
