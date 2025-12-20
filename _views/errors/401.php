<?php $this->extend('errors/layout.php'); ?>

<?php $this->block('title', '401 - Unauthorized'); ?>

<?php $this->block('content'); ?>
<div class="error-code">401</div>
<h1>Unauthorized</h1>
<p><?= htmlspecialchars($message ?? 'You must be authenticated to access this resource.', ENT_QUOTES, 'UTF-8') ?></p>
<?php $this->end(); ?>
