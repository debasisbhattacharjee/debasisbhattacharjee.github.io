<?php
/**
 * One-time setup script. Visit this file in your browser once after
 * uploading the app and creating config.php. Creates the SQLite database
 * and schema. Safe to re-run (it won't wipe existing data).
 *
 * DELETE THIS FILE (or password-protect it) once setup is complete on a
 * live/public server.
 */

$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    exit('config.php not found. Copy config.php.example to config.php and fill in your values first.');
}
require_once $configFile;
require_once __DIR__ . '/includes/db.php';

$dataDir = dirname(DB_PATH);
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
}

$isNew = !file_exists(DB_PATH);
$pdo = db();
if (!$isNew) {
    // Already exists - re-run migrations in case schema.sql gained new
    // CREATE TABLE IF NOT EXISTS statements since this was first installed.
    db_migrate($pdo);
}

// Ensure data/.htaccess exists to block direct HTTP access to the db file
// on Apache hosts (nginx: add an equivalent `location ~* \.sqlite$ { deny all; }`).
$htaccess = $dataDir . '/.htaccess';
if (!file_exists($htaccess)) {
    file_put_contents($htaccess, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
}

header('Content-Type: text/plain; charset=utf-8');
echo $isNew ? "Database created successfully at " . DB_PATH . "\n" : "Database already existed - schema re-checked (no data lost).\n";
echo "\nNext steps:\n";
echo "1. Delete or password-protect install.php now that setup is done.\n";
echo "2. Register your first account: " . rtrim(APP_URL, '/') . "/auth/register.php\n";
echo "3. If you'll sell through WarriorPlus, set your IPN URL to: " . rtrim(APP_URL, '/') . "/wplus_ipn.php\n";
echo "   and fill in WPLUS_IPN_SECRET + WPLUS_PRODUCT_PLAN_MAP in config.php.\n";
