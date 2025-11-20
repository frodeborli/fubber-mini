<?php $extend('errors/layout.php'); ?>

<?php $block('title', '500 - Internal Server Error'); ?>

<?php $block('content'); ?>
<div class="error-code">500</div>
<h1>Internal Server Error</h1>
<p><?= htmlspecialchars($message ?? 'An unexpected error occurred while processing your request.', ENT_QUOTES, 'UTF-8') ?></p>
<?php $end(); ?>
