<?php
/**
 * Registration / login for customer accounts (people who bought the tool
 * on WarriorPlus and run their own bots). Separate from the /admin panel.
 */

function auth_register(string $email, string $password, string $name, string $licenseKey): array
{
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return [false, 'Please enter a valid email address.'];
    }
    if (strlen($password) < 8) {
        return [false, 'Password must be at least 8 characters.'];
    }

    $pdo = db();

    $existing = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $existing->execute([$email]);
    if ($existing->fetch()) {
        return [false, 'An account with that email already exists. Please log in instead.'];
    }

    $plan = 'trial';
    $licenseRow = null;
    $licenseKey = trim($licenseKey);
    if ($licenseKey !== '') {
        [$ok, $licenseRow, $err] = license_lookup_unredeemed($licenseKey);
        if (!$ok) {
            return [false, $err];
        }
        $plan = $licenseRow['plan'];
    } else {
        // No key typed in - see if WarriorPlus's IPN already created an
        // unredeemed license for this exact buyer email (see Licensing.php).
        $licenseRow = license_find_unredeemed_by_email($email);
        if ($licenseRow) {
            $plan = $licenseRow['plan'];
        }
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO users (email, password_hash, name, plan, created_at) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$email, password_hash($password, PASSWORD_DEFAULT), $name, $plan, now()]);
        $userId = (int)$pdo->lastInsertId();

        if ($licenseRow) {
            license_redeem($licenseRow['id'], $userId);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return [false, 'Could not create account: ' . $e->getMessage()];
    }

    $_SESSION['user_id'] = $userId;
    return [true, null];
}

function auth_login(string $email, string $password): array
{
    $email = strtolower(trim($email));
    $stmt = db()->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return [false, 'Incorrect email or password.'];
    }
    if ($user['status'] !== 'active') {
        return [false, 'This account has been suspended.'];
    }

    $_SESSION['user_id'] = (int)$user['id'];
    return [true, null];
}

function auth_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}
