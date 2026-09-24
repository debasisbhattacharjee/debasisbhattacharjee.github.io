<?php
/**
 * Entry point required by every PHP page in this app.
 * Loads config, starts the session, wires up autoload-free includes.
 */

declare(strict_types=1);

$configFile = __DIR__ . '/../config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    exit('Setup required: copy config.php.example to config.php, fill in your ' .
         'values, then visit install.php once.');
}
require_once $configFile;

if (APP_DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
}

date_default_timezone_set('UTC');

session_name('cbs_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/Anthropic.php';
require_once __DIR__ . '/Retrieval.php';
require_once __DIR__ . '/Crawler.php';
require_once __DIR__ . '/Licensing.php';
require_once __DIR__ . '/auth.php';

if (!file_exists(DB_PATH)) {
    http_response_code(500);
    exit('Database not found. Please run install.php once to set it up.');
}
