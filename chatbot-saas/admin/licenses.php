<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/_guard.php';

$successMsg = null;
$errorMsg = null;
$generatedKey = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $plan = e_param('plan');
    $email = strtolower(e_param('email'));
    if (!array_key_exists($plan, PLAN_LIMITS)) {
        $errorMsg = 'Unknown plan.';
    } else {
        $generatedKey = license_create($plan, $email, 'manual', 'manual');
        $successMsg = 'License created. Give this key to the customer: ' . $generatedKey;
    }
}

$stmt = db()->query('SELECT * FROM licenses ORDER BY created_at DESC LIMIT 200');
$licenses = $stmt->fetchAll();

$pageTitle = 'Admin - Licenses';
require __DIR__ . '/../includes/layout_top.php';
?>
<div class="row-between">
  <h1>Licenses</h1>
  <a href="<?= h(app_path('/admin/users.php')) ?>">Users &rarr;</a>
</div>
<?php if ($errorMsg): ?><div class="alert alert-error"><?= h($errorMsg) ?></div><?php endif; ?>
<?php if ($successMsg): ?><div class="alert alert-success"><?= h($successMsg) ?></div><?php endif; ?>

<div class="card narrow">
  <h3>Manually generate a license</h3>
  <p class="muted">Use this if a sale comes in before your WarriorPlus IPN is verified,
     or for support/manual comps.</p>
  <form method="post">
    <?= csrf_field() ?>
    <label>Plan
      <select name="plan">
        <?php foreach (PLAN_LIMITS as $key => $l): if ($key === 'trial') continue; ?>
          <option value="<?= h($key) ?>"><?= h($l['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Buyer email (optional - enables auto-match at registration)<input type="email" name="email"></label>
    <button type="submit" class="btn btn-primary">Generate key</button>
  </form>
</div>

<table class="table">
  <thead><tr><th>Key</th><th>Plan</th><th>Email</th><th>Redeemed by</th><th>Created</th></tr></thead>
  <tbody>
  <?php foreach ($licenses as $l): ?>
    <tr>
      <td><code><?= h($l['license_key']) ?></code></td>
      <td><?= h(plan_limits($l['plan'])['label']) ?></td>
      <td><?= h($l['email']) ?></td>
      <td><?= $l['redeemed_by_user_id'] ? ('user #' . (int)$l['redeemed_by_user_id']) : '<span class="muted">unredeemed</span>' ?></td>
      <td><?= h($l['created_at']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
