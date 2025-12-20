<?php $this->extend(); // Uses $layout from _viewstart.php ?>
<?php $this->block('title', 'My Page'); ?>
<?php $this->block('content'); ?><p>Hello from child!</p><?php $this->end(); ?>
