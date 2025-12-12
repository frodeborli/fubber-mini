<?php $extend(); // Uses $layout from _viewstart.php ?>
<?php $block('title', 'My Page'); ?>
<?php $block('content'); ?><p>Hello from child!</p><?php $end(); ?>
