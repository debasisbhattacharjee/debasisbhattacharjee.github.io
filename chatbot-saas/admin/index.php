<?php
require_once __DIR__ . '/../includes/bootstrap.php';

if (!empty($_SESSION['is_admin'])) {
    redirect(app_path('/admin/licenses.php'));
}

$errorMsg = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $u = e_param('username');
    $p = (string)($_POST['password'] ?? '');
    if (ADMIN_PASS_HASH === '' ) {
        $errorMsg = 'Admin panel is not configured yet - set ADMIN_USER and ADMIN_PASS_HASH in config.php.';
    } elseif (hash_equals(ADMIN_USER, $u) && password_verify($p, ADMIN_PASS_HASH)) {
        $_SESSION['is_admin'] = true;
        redirect(app_path('/admin/licenses.php'));
    } else {
        $errorMsg = 'Incorrect username or password.';
    }
}

$pageTitle = 'Admin Login';
require __DIR__ . '/../includes/layout_top.php';
?>
<div class="card narrow">
  <h1>Admin Login</h1>
  <?php if ($errorMsg): ?><div class="alert alert-error"><?= h($errorMsg) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <label>Username<input type="text" name="username" required autofocus></label>
    <label>Password<input type="password" name="password" required></label>
    <button type="submit" class="btn btn-primary">Log in</button>
  </form>
</div>
<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
