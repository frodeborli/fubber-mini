<?php
// This layout extends base.php
$this->extend('base.php');

// Override some base blocks (inline syntax)
$this->block('body-class', 'with-sidebar');

// Define the layout block with sidebar structure (buffered syntax)
$this->block('layout'); ?>
  <div class="sidebar">
    <?php $this->show('sidebar', '<p>Default sidebar</p>'); ?>
  </div>
  <main>
    <?php $this->show('content'); ?>
  </main>
<?php $this->end();
