<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?php $this->show('title', 'Untitled'); ?></title>
</head>
<body>
  <header>
    <h1><?php $this->show('header', 'My Site'); ?></h1>
  </header>

  <main>
    <?php $this->show('content'); ?>
  </main>

  <footer>
    <?php $this->show('footer', '© ' . date('Y')); ?>
  </footer>
</body>
</html>
