<?php
/**
 * Public endpoint the embedded widget calls to send/receive chat messages.
 * No login required - authenticated only by the bot's public widget_key.
 */

require_once __DIR__ . '/../includes/bootstrap.php';

function cors_headers(string $allowedDomain): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
    if ($allowedDomain !== '') {
        $host = parse_url($origin, PHP_URL_HOST) ?: '';
        if (strcasecmp($host, $allowedDomain) !== 0 && !str_ends_with(strtolower($host), '.' . strtolower($allowedDomain))) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'This widget is not authorized for this domain.']);
            exit;
        }
    }
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Vary: Origin');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    // Preflight - we don't know the bot yet, so just allow generically.
    header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$widgetKey = trim((string)($input['widget_key'] ?? ''));
$message = trim((string)($input['message'] ?? ''));
$conversationId = (int)($input['conversation_id'] ?? 0);
$visitorId = trim((string)($input['visitor_id'] ?? ''));
$pageUrl = trim((string)($input['page_url'] ?? ''));

if ($widgetKey === '') {
    json_response(['error' => 'Missing widget_key.'], 400);
}

$stmt = db()->prepare('SELECT * FROM bots WHERE widget_key = ?');
$stmt->execute([$widgetKey]);
$bot = $stmt->fetch();

if (!$bot) {
    json_response(['error' => 'Unknown widget key.'], 404);
}

cors_headers($bot['allowed_domain']);

if (!$bot['is_active']) {
    json_response(['error' => 'This chatbot is currently paused.'], 403);
}

if ($message === '' || mb_strlen($message) > 4000) {
    json_response(['error' => 'Please send a message between 1 and 4000 characters.'], 400);
}

$ownerStmt = db()->prepare('SELECT * FROM users WHERE id = ?');
$ownerStmt->execute([$bot['user_id']]);
$owner = $ownerStmt->fetch();
if (!$owner || $owner['status'] !== 'active') {
    json_response(['error' => 'This chatbot is temporarily unavailable.'], 503);
}

$usage = check_and_increment_usage($owner);
if (!$usage['ok']) {
    json_response(['error' => $usage['error']], 429);
}

// --- Get or create the conversation ------------------------------------
$conversation = null;
if ($conversationId > 0) {
    $cstmt = db()->prepare('SELECT * FROM conversations WHERE id = ? AND bot_id = ?');
    $cstmt->execute([$conversationId, $bot['id']]);
    $conversation = $cstmt->fetch();
}
if (!$conversation) {
    $meta = json_encode(['page_url' => $pageUrl, 'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''], JSON_UNESCAPED_SLASHES);
    $ins = db()->prepare('INSERT INTO conversations (bot_id, visitor_id, visitor_meta, created_at, last_message_at) VALUES (?, ?, ?, ?, ?)');
    $ins->execute([$bot['id'], $visitorId, $meta, now(), now()]);
    $conversation = ['id' => (int)db()->lastInsertId()];
}
$conversationId = (int)$conversation['id'];

// Store the visitor's message.
db()->prepare('INSERT INTO messages (conversation_id, role, content, created_at) VALUES (?, ?, ?, ?)')
    ->execute([$conversationId, 'user', $message, now()]);

// --- Build context from trained content --------------------------------
$contextChunks = search_chunks((int)$bot['id'], $message, 5);
$hasContent = bot_has_trained_content((int)$bot['id']);

$systemPrompt = trim($bot['persona']) . "\n\n";
if (!empty($contextChunks)) {
    $systemPrompt .= "Use the following information about the business to answer the visitor's question. " .
        "Only use what's below plus normal conversational courtesy - do not invent facts about the business:\n\n---\n" .
        implode("\n---\n", $contextChunks) . "\n---\n";
} elseif (!$hasContent) {
    $systemPrompt .= "No business information has been trained into this bot yet. Politely say you don't have " .
        "specific information yet and offer to take the visitor's contact details so a human can follow up.\n";
} else {
    $systemPrompt .= "No relevant trained information was found for this specific question. Say you're not sure " .
        "and offer to collect the visitor's name/email so the business can follow up personally. Do not guess.\n";
}
if ($bot['lead_capture']) {
    $systemPrompt .= "\nIf the visitor seems interested but you can't fully answer, or they ask to be contacted, " .
        "ask for their name and email (and phone if relevant) so the business can reach them. Keep replies short " .
        "and conversational (2-4 sentences), suitable for a website chat widget.";
}

// --- Recent history for context (last 8 messages including this one) --
$histStmt = db()->prepare('SELECT role, content FROM messages WHERE conversation_id = ? ORDER BY id DESC LIMIT 8');
$histStmt->execute([$conversationId]);
$history = array_reverse($histStmt->fetchAll());
$claudeMessages = array_map(fn($m) => ['role' => $m['role'] === 'assistant' ? 'assistant' : 'user', 'content' => $m['content']], $history);

$model = trim($bot['model']) !== '' ? trim($bot['model']) : DEFAULT_MODEL;
$apiKey = effective_api_key($owner);
$client = new AnthropicClient($apiKey);
$result = $client->chat($claudeMessages, $systemPrompt, $model, 700);

if (!$result['ok']) {
    $reply = "Sorry, I'm having trouble responding right now. Please try again in a moment.";
    error_log('[chatbot-saas] Anthropic error for bot ' . $bot['id'] . ': ' . $result['error']);
} else {
    $reply = $result['text'];
}

db()->prepare('INSERT INTO messages (conversation_id, role, content, created_at) VALUES (?, ?, ?, ?)')
    ->execute([$conversationId, 'assistant', $reply, now()]);
db()->prepare('UPDATE conversations SET last_message_at = ? WHERE id = ?')->execute([now(), $conversationId]);

json_response([
    'conversation_id' => $conversationId,
    'reply' => $reply,
]);
