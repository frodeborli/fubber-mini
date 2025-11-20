<?php $extend('errors/layout.php'); ?>

<?php $block('title', '400 - Bad Request'); ?>

<?php $block('content'); ?>
<div class="error-code">400</div>
<h1>Bad Request</h1>
<p><?= htmlspecialchars($message ?? 'The request could not be understood by the server.', ENT_QUOTES, 'UTF-8') ?></p>
<?php $end(); ?>
