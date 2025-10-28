<?php
// This layout extends base.php
$extend('base.php');

// Override some base blocks (inline syntax)
$block('body-class', 'with-sidebar');

// Define the layout block with sidebar structure (buffered syntax)
$block('layout'); ?>
  <div class="sidebar">
    <?php $show('sidebar', '<p>Default sidebar</p>'); ?>
  </div>
  <main>
    <?php $show('content'); ?>
  </main>
<?php $end();
