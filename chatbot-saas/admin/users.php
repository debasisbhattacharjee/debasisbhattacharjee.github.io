<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/_guard.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $userId = (int)e_param('user_id');
    $action = e_param('action');
    if ($action === 'suspend') {
        db()->prepare("UPDATE users SET status = 'suspended' WHERE id = ?")->execute([$userId]);
    } elseif ($action === 'reactivate') {
        db()->prepare("UPDATE users SET status = 'active' WHERE id = ?")->execute([$userId]);
    } elseif ($action === 'set_plan') {
        $plan = e_param('plan');
        if (array_key_exists($plan, PLAN_LIMITS)) {
            db()->prepare('UPDATE users SET plan = ? WHERE id = ?')->execute([$plan, $userId]);
        }
    }
    redirect(app_path('/admin/users.php'));
}

$users = db()->query('SELECT * FROM users ORDER BY created_at DESC LIMIT 300')->fetchAll();

$pageTitle = 'Admin - Users';
require __DIR__ . '/../includes/layout_top.php';
?>
<div class="row-between">
  <h1>Users</h1>
  <a href="<?= h(app_path('/admin/licenses.php')) ?>">&larr; Licenses</a>
</div>
<table class="table">
  <thead><tr><th>Email</th><th>Plan</th><th>Status</th><th>Msgs/mo</th><th>Joined</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($users as $u): ?>
    <tr>
      <td><?= h($u['email']) ?><br><span class="muted small"><?= h($u['name']) ?></span></td>
      <td>
        <form method="post" class="inline-form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="set_plan">
          <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
          <select name="plan" onchange="this.form.submit()">
            <?php foreach (PLAN_LIMITS as $key => $l): ?>
              <option value="<?= h($key) ?>" <?= $u['plan'] === $key ? 'selected' : '' ?>><?= h($l['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
      </td>
      <td><span class="badge <?= $u['status'] === 'active' ? 'badge-on' : 'badge-off' ?>"><?= h($u['status']) ?></span></td>
      <td><?= (int)$u['credits_used_month'] ?></td>
      <td><?= h($u['created_at']) ?></td>
      <td>
        <form method="post" class="inline-form">
          <?= csrf_field() ?>
          <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
          <?php if ($u['status'] === 'active'): ?>
            <input type="hidden" name="action" value="suspend">
            <button type="submit" class="btn btn-sm btn-danger">Suspend</button>
          <?php else: ?>
            <input type="hidden" name="action" value="reactivate">
            <button type="submit" class="btn btn-sm">Reactivate</button>
          <?php endif; ?>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
