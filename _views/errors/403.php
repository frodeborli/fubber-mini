<?php $this->extend('errors/layout.php'); ?>

<?php $this->block('title', '403 - Forbidden'); ?>

<?php $this->block('content'); ?>
<div class="error-code">403</div>
<h1>Forbidden</h1>
<p><?= htmlspecialchars($message ?? 'You do not have permission to access this resource.', ENT_QUOTES, 'UTF-8') ?></p>
<?php $this->end(); ?>
