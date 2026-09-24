<?php
/**
 * Lets an EXISTING logged-in user redeem a license key to upgrade their
 * plan (e.g. buying an OTO after already using the front-end). If they are
 * not logged in yet, sends them to registration with the key prefilled.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$errorMsg = null;
$successMsg = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $key = e_param('license');

    if (empty($_SESSION['user_id'])) {
        redirect(app_path('/auth/register.php?license=' . urlencode($key)));
    }

    [$ok, $license, $err] = license_lookup_unredeemed($key);
    if (!$ok) {
        $errorMsg = $err;
    } else {
        license_redeem($license['id'], $_SESSION['user_id']);
        $stmt = db()->prepare('UPDATE users SET plan = ? WHERE id = ?');
        $stmt->execute([$license['plan'], $_SESSION['user_id']]);
        $successMsg = 'License activated! Your account is now on the "' . $license['plan'] . '" plan.';
    }
}

$pageTitle = 'Activate license - ' . APP_NAME;
require __DIR__ . '/../includes/layout_top.php';
?>
<div class="card narrow">
  <h1>Activate a license key</h1>
  <p class="muted">Bought an upgrade (OTO) on WarriorPlus? Paste the license key from
     your receipt email to unlock it on this account.</p>
  <?php if ($errorMsg): ?><div class="alert alert-error"><?= h($errorMsg) ?></div><?php endif; ?>
  <?php if ($successMsg): ?><div class="alert alert-success"><?= h($successMsg) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <label>License key<input type="text" name="license" required placeholder="e.g. CBS-XXXX-XXXX-XXXX"></label>
    <button type="submit" class="btn btn-primary">Activate</button>
  </form>
</div>
<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
