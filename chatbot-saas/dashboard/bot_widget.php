<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$user = require_login();

$botId = (int)e_param('bot_id');
$bot = bot_or_404($user, $botId);

$embedCode = '<script src="' . APP_URL . '/api/widget.php?key=' . h($bot['widget_key']) . '" async></script>';

$pageTitle = 'Embed ' . $bot['name'] . ' - ' . APP_NAME;
require __DIR__ . '/../includes/layout_top.php';
?>
<h1>Embed "<?= h($bot['name']) ?>" on a website</h1>
<div class="card">
  <p>Paste this one line just before the closing <code>&lt;/body&gt;</code> tag of any
     website (WordPress, Shopify, Wix, or plain HTML):</p>
  <textarea readonly rows="2" class="code-box" onclick="this.select()"><?= h($embedCode) ?></textarea>
  <p class="muted small">The chat bubble will appear in the bottom-right corner. It works on
     any domain unless you set "Restrict widget to domain" in this bot's settings.</p>
</div>
<div class="card">
  <h3>Preview</h3>
  <p class="muted">This preview loads the real widget against this install.</p>
  <div id="widget-preview-note" class="muted small">Loading preview...</div>
</div>
<script src="<?= h(APP_URL) ?>/api/widget.php?key=<?= h($bot['widget_key']) ?>" async
        onload="document.getElementById('widget-preview-note').textContent='Widget loaded - look for the chat bubble in the bottom-right corner.'"></script>
<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
