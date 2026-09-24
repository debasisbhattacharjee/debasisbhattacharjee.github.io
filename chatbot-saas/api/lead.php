<?php
/**
 * Public endpoint the widget's "Get in touch" form posts to.
 */

require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$widgetKey = trim((string)($input['widget_key'] ?? ''));
$conversationId = (int)($input['conversation_id'] ?? 0);
$name = trim((string)($input['name'] ?? ''));
$email = trim((string)($input['email'] ?? ''));
$phone = trim((string)($input['phone'] ?? ''));
$message = trim((string)($input['message'] ?? ''));

$stmt = db()->prepare('SELECT * FROM bots WHERE widget_key = ?');
$stmt->execute([$widgetKey]);
$bot = $stmt->fetch();
if (!$bot) {
    json_response(['error' => 'Unknown widget key.'], 404);
}

header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Vary: Origin');

if ($name === '' && $email === '' && $phone === '') {
    json_response(['error' => 'Please provide at least a name, email, or phone number.'], 400);
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_response(['error' => 'Please enter a valid email address.'], 400);
}

$convIdOrNull = null;
if ($conversationId > 0) {
    $c = db()->prepare('SELECT id FROM conversations WHERE id = ? AND bot_id = ?');
    $c->execute([$conversationId, $bot['id']]);
    if ($c->fetch()) {
        $convIdOrNull = $conversationId;
    }
}

$stmt = db()->prepare(
    'INSERT INTO leads (bot_id, conversation_id, name, email, phone, message, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)'
);
$stmt->execute([$bot['id'], $convIdOrNull, mb_substr($name, 0, 200), mb_substr($email, 0, 200), mb_substr($phone, 0, 50), mb_substr($message, 0, 2000), now()]);

json_response(['ok' => true]);
