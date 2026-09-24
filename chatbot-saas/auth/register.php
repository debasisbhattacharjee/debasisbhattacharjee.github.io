<?php
require_once __DIR__ . '/../includes/bootstrap.php';

if (!empty($_SESSION['user_id'])) {
    redirect(app_path('/dashboard/index.php'));
}

$prefillLicense = e_param('license');
$errorMsg = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = e_param('email');
    $password = (string)($_POST['password'] ?? '');
    $name = e_param('name');
    $license = e_param('license');

    [$ok, $err] = auth_register($email, $password, $name, $license);
    if ($ok) {
        redirect(app_path('/dashboard/index.php'));
    }
    $errorMsg = $err;
    $prefillLicense = $license;
}

$pageTitle = 'Create your account - ' . APP_NAME;
require __DIR__ . '/../includes/layout_top.php';
?>
<div class="card narrow">
  <h1>Create your account</h1>
  <p class="muted">Have a license key from your WarriorPlus receipt? Enter it below to
     unlock your plan automatically. Otherwise you'll start on the free trial
     (1 bot, 50 messages).</p>
  <?php if ($errorMsg): ?><div class="alert alert-error"><?= h($errorMsg) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <label>Name<input type="text" name="name" required></label>
    <label>Email<input type="email" name="email" required></label>
    <label>Password (min 8 characters)<input type="password" name="password" required minlength="8"></label>
    <label>License key (optional)<input type="text" name="license" value="<?= h($prefillLicense) ?>" placeholder="e.g. CBS-XXXX-XXXX-XXXX"></label>
    <button type="submit" class="btn btn-primary">Create account</button>
  </form>
  <p class="muted">Already have an account? <a href="<?= h(app_path('/auth/login.php')) ?>">Log in</a></p>
</div>
<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
