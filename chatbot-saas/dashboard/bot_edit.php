<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$user = require_login();

$botId = (int)e_param('id');
$bot = $botId ? bot_or_404($user, $botId) : null;

if (!$bot && !can_create_bot($user)) {
    flash_set('error', 'You have reached the bot limit for your plan. Please upgrade to create more bots.');
    redirect(app_path('/dashboard/index.php'));
}

$errorMsg = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $name = e_param('name');
    $welcome = e_param('welcome_message');
    $persona = e_param('persona');
    $model = e_param('model');
    $color = e_param('color', '#4f46e5');
    $leadCapture = e_param('lead_capture') === '1' ? 1 : 0;
    $allowedDomain = e_param('allowed_domain');
    $isActive = e_param('is_active') === '1' ? 1 : 0;

    if ($name === '') {
        $errorMsg = 'Please give your bot a name.';
    } else {
        if ($bot) {
            $stmt = db()->prepare(
                'UPDATE bots SET name=?, welcome_message=?, persona=?, model=?, color=?, lead_capture=?, allowed_domain=?, is_active=? WHERE id=?'
            );
            $stmt->execute([$name, $welcome ?: 'Hi! How can I help you today?', $persona, $model, $color, $leadCapture, $allowedDomain, $isActive, $bot['id']]);
            flash_set('success', 'Bot settings saved.');
            redirect(app_path('/dashboard/bot_edit.php?id=' . $bot['id']));
        } else {
            $widgetKey = random_token(12);
            $stmt = db()->prepare(
                'INSERT INTO bots (user_id, name, widget_key, welcome_message, persona, model, color, lead_capture, allowed_domain, is_active, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $user['id'], $name, $widgetKey,
                $welcome ?: 'Hi! How can I help you today?',
                $persona ?: 'You are a friendly, concise assistant for this business. Answer only from the provided context. If you do not know, offer to collect the visitor\'s contact details.',
                $model, $color, $leadCapture, $allowedDomain, $isActive, now(),
            ]);
            $newId = (int)db()->lastInsertId();
            flash_set('success', 'Bot created! Now train it with your business content.');
            redirect(app_path('/dashboard/bot_train.php?bot_id=' . $newId));
        }
    }
}

$pageTitle = ($bot ? 'Edit ' . $bot['name'] : 'New Bot') . ' - ' . APP_NAME;
require __DIR__ . '/../includes/layout_top.php';
?>
<div class="card narrow">
  <h1><?= $bot ? 'Bot Settings' : 'Create a New Bot' ?></h1>
  <?php if ($errorMsg): ?><div class="alert alert-error"><?= h($errorMsg) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <label>Bot name<input type="text" name="name" required value="<?= h($bot['name'] ?? '') ?>" placeholder="e.g. Acme Support Bot"></label>
    <label>Welcome message<input type="text" name="welcome_message" value="<?= h($bot['welcome_message'] ?? 'Hi! How can I help you today?') ?>"></label>
    <label>Persona / instructions
      <textarea name="persona" rows="4"><?= h($bot['persona'] ?? "You are a friendly, concise assistant for this business. Answer only from the provided context. If you do not know, offer to collect the visitor's contact details.") ?></textarea>
    </label>
    <label>Model
      <select name="model">
        <?php $curModel = $bot['model'] ?? ''; ?>
        <option value="" <?= $curModel === '' ? 'selected' : '' ?>>Default (<?= h(DEFAULT_MODEL) ?> - cheapest, best for FAQs)</option>
        <option value="claude-haiku-4-5" <?= $curModel === 'claude-haiku-4-5' ? 'selected' : '' ?>>claude-haiku-4-5 (fast & cheap)</option>
        <option value="claude-sonnet-5" <?= $curModel === 'claude-sonnet-5' ? 'selected' : '' ?>>claude-sonnet-5 (balanced)</option>
        <option value="claude-opus-5" <?= $curModel === 'claude-opus-5' ? 'selected' : '' ?>>claude-opus-5 (highest quality, most expensive)</option>
      </select>
    </label>
    <label>Widget accent color<input type="color" name="color" value="<?= h($bot['color'] ?? '#4f46e5') ?>"></label>
    <label>Restrict widget to domain (optional, e.g. example.com)
      <input type="text" name="allowed_domain" value="<?= h($bot['allowed_domain'] ?? '') ?>" placeholder="leave blank to allow any site (recommended while testing)">
    </label>
    <label class="checkbox"><input type="checkbox" name="lead_capture" value="1" <?= ($bot['lead_capture'] ?? 1) ? 'checked' : '' ?>> Show a "Get in touch" lead capture form in the widget</label>
    <label class="checkbox"><input type="checkbox" name="is_active" value="1" <?= ($bot === null || $bot['is_active']) ? 'checked' : '' ?>> Bot is active (uncheck to pause it without deleting)</label>
    <button type="submit" class="btn btn-primary"><?= $bot ? 'Save Settings' : 'Create Bot' ?></button>
  </form>
</div>
<?php require __DIR__ . '/../includes/layout_bottom.php'; ?>
