<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$user = require_login();

$botId = (int)e_param('bot_id');
$bot = bot_or_404($user, $botId);

$errorMsg = null;
$successMsg = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = e_param('action');

    if ($action === 'add_text') {
        $title = e_param('title') ?: 'Pasted text';
        $content = trim((string)($_POST['content'] ?? ''));
        if (strlen($content) < 20) {
            $errorMsg = 'Please paste at least a few sentences of content.';
        } else {
            $stmt = db()->prepare('INSERT INTO sources (bot_id, type, title, origin, created_at) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$botId, 'text', $title, '(pasted text)', now()]);
            $sourceId = (int)db()->lastInsertId();
            index_source($botId, $sourceId, $content);
            $successMsg = 'Text added and indexed.';
        }
    } elseif ($action === 'add_url') {
        $url = e_param('url');
        $result = crawl_url($url);
        if (!$result['ok']) {
            $errorMsg = $result['error'];
        } else {
            $stmt = db()->prepare('INSERT INTO sources (bot_id, type, title, origin, created_at) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$botId, 'url', $result['title'] ?: $url, $url, now()]);
            $sourceId = (int)db()->lastInsertId();
            index_source($botId, $sourceId, $result['text']);
            $successMsg = 'Page crawled and indexed: ' . h($result['title'] ?: $url);
        }
    } elseif ($action === 'add_file') {
        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $errorMsg = 'Please choose a .txt file to upload.';
        } else {
            $file = $_FILES['file'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if ($ext !== 'txt' || $file['size'] > 2 * 1024 * 1024) {
                $errorMsg = 'Only plain .txt files up to 2MB are supported in this version. (PDF/DOCX support is a good OTO/upgrade feature to add later.)';
            } else {
                $content = file_get_contents($file['tmp_name']);
                $stmt = db()->prepare('INSERT INTO sources (bot_id, type, title, origin, created_at) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([$botId, 'file', $file['name'], $file['name'], now()]);
                $sourceId = (int)db()->lastInsertId();
                index_source($botId, $sourceId, $content);
                $successMsg = 'File uploaded and indexed: ' . h($file['name']);
            }
        }
    } elseif ($action === 'delete_source') {
        $sourceId = (int)e_param('source_id');
        $stmt = db()->prepare('DELETE FROM sources WHERE id = ? AND bot_id = ?');
        $stmt->execute([$sourceId, $botId]);
        $successMsg = 'Source removed.';
    }
}

$stmt = db()->prepare('SELECT * FROM sources WHERE bot_id = ? ORDER BY created_at DESC');
$stmt->execute([$botId]);
$sources = $stmt->fetchAll();

$pageTitle = 'Train ' . $bot['name'] . ' - ' . APP_NAME;
require __DIR__ . '/../includes/layout_top.php';
?>
<h1>Train "<?= h($bot['name']) ?>"</h1>
<p class="muted">Add content below. The bot will answer visitor questions using only
   what you've trained it on here, and offer to capture a lead when it doesn't know
   the answer.</p>
<?php if ($errorMsg): ?><div class="alert alert-error"><?= h($errorMsg) ?></div><?php endif; ?>
<?php if ($successMsg): ?><div class="alert alert-success"><?= $successMsg ?></div><?php endif; ?>

<div class="grid">
  <div class="card">
    <h3>Paste text</h3>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_text">
      <label>Title<input type="text" name="title" placeholder="e.g. Pricing FAQ"></label>
      <label>Content<textarea name="content" rows="8" placeholder="Paste FAQs, policies, product info..." required></textarea></label>
      <button type="submit" class="btn btn-primary">Add &amp; Index</button>
    </form>
  </div>

  <div class="card">
    <h3>Crawl a web page</h3>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_url">
      <label>Page URL<input type="url" name="url" placeholder="https://example.com/faq" required></label>
      <button type="submit" class="btn btn-primary">Fetch &amp; Index</button>
      <p class="muted small">One page per fetch. Run this once per page you want trained (e.g. your FAQ page, About page, pricing page).</p>
    </form>
  </div>

  <div class="card">
    <h3>Upload a .txt file</h3>
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_file">
      <label>File (.txt, max 2MB)<input type="file" name="file" accept=".txt" required></label>
      <button type="submit" class="btn btn-primary">Upload &amp; Index</button>
    </form>
  </div>
</div>

<h2>Trained sources</h2>
<?php if (empty($sources)): ?>
  <p class="muted">No sources yet - add one above.</p>
<?php else: ?>
  <table class="table">
    <thead><tr><th>Title</th><th>Type</th><th>Status</th><th>Chunks</th><th>Added</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($sources as $s): ?>
      <tr>
        <td><?= h($s['title']) ?><br><span class="muted small"><?= h($s['origin']) ?></span></td>
        <td><?= h($s['type']) ?></td>
        <td>
          <?php if ($s['status'] === 'indexed'): ?><span class="badge badge-on">Indexed</span>
          <?php elseif ($s['status'] === 'error'): ?><span class="badge badge-off" title="<?= h($s['error_message']) ?>">Error</span>
          <?php else: ?><span class="badge">Pending</span><?php endif; ?>
        </td>
        <td><?= (int)$s['chunk_count'] ?></td>
        <td><?= h($s['created_at']) ?></td>
        <td>
          <form method="post" onsubmit="return confirm('Remove this source?');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_source">
            <input type="hidden" name="source_id" value="<?= (int)$s['id'] ?>">
            <button type="submit" class="btn btn-sm btn-danger">Remove</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<p><a href="<?= h(app_path('/dashboard/bot_widget.php?bot_id=' . $botId)) ?>">Next: get your embed code &rarr;</a></p>
<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
