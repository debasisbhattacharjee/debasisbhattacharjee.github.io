<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$user = require_login();

$botId = (int)e_param('bot_id');
$bot = bot_or_404($user, $botId);

$stmt = db()->prepare(
    'SELECT cv.*, (SELECT COUNT(*) FROM messages m WHERE m.conversation_id = cv.id) AS message_count
     FROM conversations cv WHERE cv.bot_id = ? ORDER BY cv.last_message_at DESC LIMIT 200'
);
$stmt->execute([$botId]);
$conversations = $stmt->fetchAll();

$pageTitle = 'Conversations - ' . $bot['name'];
require __DIR__ . '/../includes/layout_top.php';
?>
<h1>Conversations - "<?= h($bot['name']) ?>"</h1>
<?php if (empty($conversations)): ?>
  <p class="muted">No conversations yet. Once your widget is embedded and visitors start chatting, they'll show up here.</p>
<?php else: ?>
  <table class="table">
    <thead><tr><th>Started</th><th>Last message</th><th>Messages</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($conversations as $c): ?>
      <tr>
        <td><?= h($c['created_at']) ?></td>
        <td><?= h($c['last_message_at']) ?></td>
        <td><?= (int)$c['message_count'] ?></td>
        <td><a class="btn btn-sm" href="<?= h(app_path('/dashboard/conversation_view.php?id=' . $c['id'] . '&bot_id=' . $botId)) ?>">View</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
