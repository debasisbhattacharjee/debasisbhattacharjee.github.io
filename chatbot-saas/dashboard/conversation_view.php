<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$user = require_login();

$botId = (int)e_param('bot_id');
$bot = bot_or_404($user, $botId);
$convId = (int)e_param('id');

$stmt = db()->prepare('SELECT * FROM conversations WHERE id = ? AND bot_id = ?');
$stmt->execute([$convId, $botId]);
$conv = $stmt->fetch();
if (!$conv) {
    http_response_code(404);
    exit('Conversation not found.');
}

$stmt = db()->prepare('SELECT * FROM messages WHERE conversation_id = ? ORDER BY id ASC');
$stmt->execute([$convId]);
$messages = $stmt->fetchAll();

$pageTitle = 'Conversation - ' . $bot['name'];
require __DIR__ . '/../includes/layout_top.php';
?>
<p><a href="<?= h(app_path('/dashboard/conversations.php?bot_id=' . $botId)) ?>">&larr; Back to conversations</a></p>
<h1>Conversation</h1>
<div class="chat-log">
  <?php foreach ($messages as $m): ?>
    <div class="chat-msg chat-<?= h($m['role']) ?>">
      <strong><?= $m['role'] === 'user' ? 'Visitor' : h($bot['name']) ?>:</strong>
      <span><?= nl2br(h($m['content'])) ?></span>
    </div>
  <?php endforeach; ?>
</div>
<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
