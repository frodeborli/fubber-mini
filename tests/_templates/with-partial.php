<h1>Users List</h1>

<div class="users">
  <?php foreach ($users as $user): ?>
    <?= mini\render('_user-card.php', ['user' => $user]) ?>
  <?php endforeach; ?>
</div>

<p>Total users: <?= count($users) ?></p>
