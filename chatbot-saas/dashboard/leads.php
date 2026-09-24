<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$user = require_login();

$botId = (int)e_param('bot_id');
$bot = bot_or_404($user, $botId);

if (e_param('export') === 'csv') {
    $stmt = db()->prepare('SELECT name, email, phone, message, created_at FROM leads WHERE bot_id = ? ORDER BY created_at DESC');
    $stmt->execute([$botId]);
    $rows = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="leads-' . preg_replace('/[^a-z0-9]+/i', '-', $bot['name']) . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Name', 'Email', 'Phone', 'Message', 'Date'], ',', '"', '\\');
    foreach ($rows as $r) {
        fputcsv($out, [$r['name'], $r['email'], $r['phone'], $r['message'], $r['created_at']], ',', '"', '\\');
    }
    fclose($out);
    exit;
}

$stmt = db()->prepare('SELECT * FROM leads WHERE bot_id = ? ORDER BY created_at DESC');
$stmt->execute([$botId]);
$leads = $stmt->fetchAll();

$pageTitle = 'Leads - ' . $bot['name'];
require __DIR__ . '/../includes/layout_top.php';
?>
<div class="row-between">
  <h1>Leads - "<?= h($bot['name']) ?>"</h1>
  <a class="btn" href="<?= h(app_path('/dashboard/leads.php?bot_id=' . $botId . '&export=csv')) ?>">Export CSV</a>
</div>
<?php if (empty($leads)): ?>
  <p class="muted">No leads captured yet.</p>
<?php else: ?>
  <table class="table">
    <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Message</th><th>Date</th></tr></thead>
    <tbody>
    <?php foreach ($leads as $l): ?>
      <tr>
        <td><?= h($l['name']) ?></td>
        <td><?= h($l['email']) ?></td>
        <td><?= h($l['phone']) ?></td>
        <td><?= h($l['message']) ?></td>
        <td><?= h($l['created_at']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
