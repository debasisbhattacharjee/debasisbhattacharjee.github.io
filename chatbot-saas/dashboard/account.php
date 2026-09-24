<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$user = require_login();
ensure_monthly_reset($user);

$errorMsg = null;
$successMsg = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = e_param('action');

    if ($action === 'save_api_key') {
        $key = trim((string)($_POST['own_api_key'] ?? ''));
        $stmt = db()->prepare('UPDATE users SET own_api_key = ? WHERE id = ?');
        $stmt->execute([$key, $user['id']]);
        $user['own_api_key'] = $key;
        $successMsg = $key === '' ? 'Removed your custom API key - bots will use the shared plan credits again.' : 'Your own Anthropic API key is now saved and will be used for all your bots (unlimited messages, billed to your own Anthropic account).';
    } elseif ($action === 'create_client' && plan_limits($user['plan'])['agency']) {
        $email = strtolower(trim(e_param('client_email')));
        $password = (string)($_POST['client_password'] ?? '');
        $name = e_param('client_name');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
            $errorMsg = 'Please provide a valid email and an 8+ character password for the client account.';
        } else {
            $exists = db()->prepare('SELECT id FROM users WHERE email = ?');
            $exists->execute([$email]);
            if ($exists->fetch()) {
                $errorMsg = 'A user with that email already exists.';
            } else {
                $stmt = db()->prepare(
                    'INSERT INTO users (email, password_hash, name, plan, parent_user_id, created_at) VALUES (?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([$email, password_hash($password, PASSWORD_DEFAULT), $name, 'front_end', $user['id'], now()]);
                $successMsg = 'Client account created for ' . h($email) . '. Share their login email/password with them, or log in as them to build their bot yourself.';
            }
        }
    }
}

$limits = plan_limits($user['plan']);

$clients = [];
if ($limits['agency']) {
    $stmt = db()->prepare('SELECT id, email, name, plan, status, created_at FROM users WHERE parent_user_id = ? ORDER BY created_at DESC');
    $stmt->execute([$user['id']]);
    $clients = $stmt->fetchAll();
}

$pageTitle = 'Account - ' . APP_NAME;
require __DIR__ . '/../includes/layout_top.php';
?>
<h1>Account</h1>
<?php if ($errorMsg): ?><div class="alert alert-error"><?= h($errorMsg) ?></div><?php endif; ?>
<?php if ($successMsg): ?><div class="alert alert-success"><?= $successMsg ?></div><?php endif; ?>

<div class="card">
  <h3>Plan &amp; usage</h3>
  <p><strong><?= h($limits['label']) ?></strong> plan &middot; up to <?= (int)$limits['max_bots'] ?> bots</p>
  <p>Messages used this month: <strong><?= (int)$user['credits_used_month'] ?></strong> / <?= (int)$limits['monthly_messages'] ?>
     <?php if (trim($user['own_api_key']) !== ''): ?><span class="muted">(not enforced - you're using your own API key)</span><?php endif; ?></p>
  <p class="muted">Have an upgrade license key? <a href="<?= h(app_path('/auth/activate.php')) ?>">Activate it here</a>.</p>
</div>

<div class="card">
  <h3>Bring your own Anthropic API key (optional)</h3>
  <p class="muted">Add your own key from <a href="https://console.anthropic.com/settings/keys" target="_blank" rel="noopener">console.anthropic.com</a>
     to bypass this plan's monthly message limit entirely. Claude usage is then billed directly to your Anthropic account instead of ours.</p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_api_key">
    <label>Your Anthropic API key<input type="text" name="own_api_key" value="<?= h($user['own_api_key']) ?>" placeholder="sk-ant-..."></label>
    <button type="submit" class="btn btn-primary">Save</button>
  </form>
</div>

<?php if ($limits['agency']): ?>
<div class="card">
  <h3>Agency: client accounts</h3>
  <p class="muted">Create a separate login for each client so they can log in and see only their own bots.</p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create_client">
    <label>Client name<input type="text" name="client_name" required></label>
    <label>Client email<input type="email" name="client_email" required></label>
    <label>Temporary password<input type="text" name="client_password" required minlength="8"></label>
    <button type="submit" class="btn btn-primary">Create client account</button>
  </form>

  <?php if ($clients): ?>
    <table class="table">
      <thead><tr><th>Name</th><th>Email</th><th>Plan</th><th>Status</th><th>Created</th></tr></thead>
      <tbody>
      <?php foreach ($clients as $c): ?>
        <tr><td><?= h($c['name']) ?></td><td><?= h($c['email']) ?></td><td><?= h($c['plan']) ?></td><td><?= h($c['status']) ?></td><td><?= h($c['created_at']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
