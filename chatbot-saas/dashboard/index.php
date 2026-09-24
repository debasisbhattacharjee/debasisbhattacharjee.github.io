<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$user = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && e_param('action') === 'delete_bot') {
    csrf_check();
    $botId = (int)e_param('bot_id');
    $bot = bot_or_404($user, $botId);
    db()->prepare('DELETE FROM bots WHERE id = ?')->execute([$botId]);
    flash_set('success', 'Bot "' . $bot['name'] . '" was deleted.');
    redirect(app_path('/dashboard/index.php'));
}

$stmt = db()->prepare(
    'SELECT b.*,
            (SELECT COUNT(*) FROM chunks c WHERE c.bot_id = b.id) AS chunk_count,
            (SELECT COUNT(*) FROM conversations cv WHERE cv.bot_id = b.id) AS conversation_count,
            (SELECT COUNT(*) FROM leads l WHERE l.bot_id = b.id) AS lead_count
     FROM bots b WHERE b.user_id = ? ORDER BY b.created_at DESC'
);
$stmt->execute([$user['id']]);
$bots = $stmt->fetchAll();

$limits = plan_limits($user['plan']);
$canCreate = can_create_bot($user);

$pageTitle = 'My Bots - ' . APP_NAME;
require __DIR__ . '/../includes/layout_top.php';
?>
<div class="row-between">
  <h1>My Bots</h1>
  <?php if ($canCreate): ?>
    <a class="btn btn-primary" href="<?= h(app_path('/dashboard/bot_edit.php')) ?>">+ New Bot</a>
  <?php else: ?>
    <span class="muted">Bot limit reached for your plan (<?= (int)$limits['max_bots'] ?>). <a href="<?= h(app_path('/dashboard/account.php')) ?>">Upgrade</a></span>
  <?php endif; ?>
</div>

<?php if (empty($bots)): ?>
  <div class="card">
    <p>You haven't created a bot yet. Bots answer questions from content you train
       them on (paste text, crawl a URL, or upload a .txt file) and can be embedded
       on any website with one line of code.</p>
    <a class="btn btn-primary" href="<?= h(app_path('/dashboard/bot_edit.php')) ?>">Create your first bot</a>
  </div>
<?php else: ?>
  <div class="grid">
    <?php foreach ($bots as $bot): ?>
      <div class="card bot-card">
        <div class="row-between">
          <h3><?= h($bot['name']) ?></h3>
          <span class="badge <?= $bot['is_active'] ? 'badge-on' : 'badge-off' ?>"><?= $bot['is_active'] ? 'Active' : 'Paused' ?></span>
        </div>
        <p class="muted"><?= (int)$bot['chunk_count'] ?> trained snippets &middot;
           <?= (int)$bot['conversation_count'] ?> conversations &middot;
           <?= (int)$bot['lead_count'] ?> leads</p>
        <div class="btn-row">
          <a class="btn" href="<?= h(app_path('/dashboard/bot_train.php?bot_id=' . $bot['id'])) ?>">Train</a>
          <a class="btn" href="<?= h(app_path('/dashboard/bot_edit.php?id=' . $bot['id'])) ?>">Settings</a>
          <a class="btn" href="<?= h(app_path('/dashboard/bot_widget.php?bot_id=' . $bot['id'])) ?>">Embed</a>
          <a class="btn" href="<?= h(app_path('/dashboard/conversations.php?bot_id=' . $bot['id'])) ?>">Chats</a>
          <a class="btn" href="<?= h(app_path('/dashboard/leads.php?bot_id=' . $bot['id'])) ?>">Leads</a>
        </div>
        <form method="post" onsubmit="return confirm('Delete this bot and all its data? This cannot be undone.');" class="inline-form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete_bot">
          <input type="hidden" name="bot_id" value="<?= (int)$bot['id'] ?>">
          <button type="submit" class="btn btn-danger btn-sm">Delete</button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
