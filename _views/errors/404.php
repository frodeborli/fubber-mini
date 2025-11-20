<?php $extend('errors/layout.php'); ?>

<?php $block('title', '404 - Not Found'); ?>

<?php $block('content'); ?>
<div class="error-code">404</div>
<h1>Not Found</h1>
<p><?= htmlspecialchars($message ?? 'The requested resource could not be found.', ENT_QUOTES, 'UTF-8') ?></p>
<?php $end(); ?>
