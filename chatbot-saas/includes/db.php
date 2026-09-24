<?php
/**
 * Single shared PDO/SQLite connection.
 */

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $isNew = !file_exists(DB_PATH);

    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');

    if ($isNew) {
        db_migrate($pdo);
    }

    return $pdo;
}

function db_migrate(PDO $pdo): void
{
    $sql = file_get_contents(__DIR__ . '/schema.sql');
    $pdo->exec($sql);
}

/** Generate a URL-safe random token, e.g. for widget keys / license keys. */
function random_token(int $bytes = 16): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}
