<?php
/**
 * Small shared helpers used throughout the app. Kept dependency-free on
 * purpose (no template engine, no framework) so the whole thing runs on
 * plain PHP with zero Composer install step.
 */

/** Escape for HTML output. Use on every piece of user/visitor supplied data. */
function h(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function json_response($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function flash_set(string $key, string $message): void
{
    $_SESSION['flash'][$key] = $message;
}

function flash_get(string $key): ?string
{
    if (empty($_SESSION['flash'][$key])) {
        return null;
    }
    $msg = $_SESSION['flash'][$key];
    unset($_SESSION['flash'][$key]);
    return $msg;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function csrf_check(): void
{
    $token = $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $token)) {
        http_response_code(400);
        exit('Invalid or expired form submission. Please go back and try again.');
    }
}

/** Require an authenticated customer session, else bounce to login. */
function require_login(): array
{
    if (empty($_SESSION['user_id'])) {
        redirect(app_path('/auth/login.php'));
    }
    $user = db()->prepare('SELECT * FROM users WHERE id = ?');
    $user->execute([$_SESSION['user_id']]);
    $row = $user->fetch();
    if (!$row || $row['status'] !== 'active') {
        session_destroy();
        redirect(app_path('/auth/login.php'));
    }
    return $row;
}

/** Build an absolute app path honoring the folder this app is installed in. */
function app_path(string $path = ''): string
{
    static $base = null;
    if ($base === null) {
        // SCRIPT_NAME e.g. /chatbot-saas/dashboard/index.php -> /chatbot-saas
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $dir = str_replace('\\', '/', dirname($script));
        // Walk up out of known first-level subfolders to the app root.
        foreach (['/dashboard', '/auth', '/api', '/admin'] as $sub) {
            if (str_ends_with($dir, $sub)) {
                $dir = substr($dir, 0, -strlen($sub));
                break;
            }
        }
        $base = rtrim($dir, '/');
    }
    return $base . $path;
}

function now(): string
{
    return gmdate('Y-m-d H:i:s');
}

/** Fetch the bot owned by $user, or die 404. */
function bot_or_404(array $user, int $botId): array
{
    $stmt = db()->prepare('SELECT * FROM bots WHERE id = ? AND user_id = ?');
    $stmt->execute([$botId, $user['id']]);
    $bot = $stmt->fetch();
    if (!$bot) {
        http_response_code(404);
        exit('Bot not found.');
    }
    return $bot;
}

function e_param(string $key, string $default = ''): string
{
    return trim((string)($_POST[$key] ?? $_GET[$key] ?? $default));
}
