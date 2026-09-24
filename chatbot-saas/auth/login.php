<?php
require_once __DIR__ . '/../includes/bootstrap.php';

if (!empty($_SESSION['user_id'])) {
    redirect(app_path('/dashboard/index.php'));
}

$errorMsg = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    [$ok, $err] = auth_login(e_param('email'), (string)($_POST['password'] ?? ''));
    if ($ok) {
        redirect(app_path('/dashboard/index.php'));
    }
    $errorMsg = $err;
}

$pageTitle = 'Log in - ' . APP_NAME;
require __DIR__ . '/../includes/layout_top.php';
?>
<div class="card narrow">
  <h1>Log in</h1>
  <?php if ($errorMsg): ?><div class="alert alert-error"><?= h($errorMsg) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <label>Email<input type="email" name="email" required autofocus></label>
    <label>Password<input type="password" name="password" required></label>
    <button type="submit" class="btn btn-primary">Log in</button>
  </form>
  <p class="muted">No account yet? <a href="<?= h(app_path('/auth/register.php')) ?>">Register</a>
     &middot; Have a license key to redeem? <a href="<?= h(app_path('/auth/activate.php')) ?>">Activate it</a></p>
</div>
<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
