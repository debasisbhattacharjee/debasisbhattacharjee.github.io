<?php
/**
 * WarriorPlus Instant Payment Notification (IPN) receiver.
 *
 * Configure this exact URL (https://yourdomain.com/wplus_ipn.php) as the
 * IPN Post URL in your WarriorPlus vendor dashboard for each product
 * (front end + every OTO), and set WPLUS_IPN_SECRET in config.php to match
 * the "Secret Key" you set there.
 *
 * IMPORTANT: WarriorPlus's exact POST field names can change between
 * products/account types, and we don't have live access to fetch their
 * current IPN spec at build time. Every request this endpoint receives is
 * appended to data/ipn_log.txt (field names + values) BEFORE any
 * processing, specifically so you can:
 *   1. Send WarriorPlus's IPN test/verification POST
 *   2. Open data/ipn_log.txt and see the exact field names they used
 *   3. Adjust the field lookups below (search for "ADJUST") if needed
 *
 * Until this is verified against a real WarriorPlus test IPN, treat license
 * auto-provisioning as unverified and check data/ipn_log.txt after every
 * sale. The manual fallback (admin/licenses.php lets you hand-generate a
 * license key and email it yourself) always works regardless.
 */

require_once __DIR__ . '/includes/bootstrap.php';

function ipn_log(string $line): void
{
    $path = __DIR__ . '/data/ipn_log.txt';
    $entry = '[' . gmdate('Y-m-d H:i:s') . " UTC]\n" . $line . "\n\n";
    // Keep the log from growing forever on a busy launch day.
    if (file_exists($path) && filesize($path) > 5 * 1024 * 1024) {
        file_put_contents($path, '');
    }
    file_put_contents($path, $entry, FILE_APPEND | LOCK_EX);
}

$raw = file_get_contents('php://input');
ipn_log("POST fields:\n" . print_r($_POST, true) . "\nRaw body:\n" . $raw);

// --- 1. Verify the shared secret --------------------------------------
// ADJUST: WarriorPlus is documented to post a field carrying the secret
// key you configured; the exact name has varied across integration guides.
// We check several common candidates so this keeps working either way.
$postedSecret = $_POST['secret'] ?? $_POST['secret_key'] ?? $_POST['ipn_secret'] ?? $_POST['wp_secret'] ?? '';

if (!WPLUS_IPN_SECRET || WPLUS_IPN_SECRET === 'set-me' || !hash_equals(WPLUS_IPN_SECRET, (string)$postedSecret)) {
    ipn_log('REJECTED: secret mismatch or not configured.');
    http_response_code(403);
    exit('Invalid secret.');
}

// --- 2. Read the sale details ------------------------------------------
// ADJUST these field names if data/ipn_log.txt shows different keys.
$action = strtolower((string)($_POST['wp_action'] ?? $_POST['action'] ?? $_POST['ipn_type'] ?? 'sale'));
$productId = (string)($_POST['product_id'] ?? $_POST['pr_id'] ?? '');
$email = strtolower(trim((string)($_POST['buyer_email'] ?? $_POST['email'] ?? '')));
$txnId = (string)($_POST['transaction_id'] ?? $_POST['trans_id'] ?? $_POST['tx_id'] ?? '');

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    ipn_log('REJECTED: no valid buyer email in payload.');
    http_response_code(200); // acknowledge so WarriorPlus doesn't retry forever
    exit('No buyer email.');
}

$plan = WPLUS_PRODUCT_PLAN_MAP[$productId] ?? null;

// --- 3. Sales / rebills: provision a license -----------------------------
if (in_array($action, ['sale', 'rebill', 'test', ''], true)) {
    if ($plan === null) {
        ipn_log("Product id '$productId' has no plan mapping in WPLUS_PRODUCT_PLAN_MAP - no license created.");
        http_response_code(200);
        exit('OK (unmapped product, logged only).');
    }

    $key = license_create($plan, $email, $txnId, $productId);
    ipn_log("License created: $key (plan=$plan, email=$email, product=$productId, txn=$txnId)");
    http_response_code(200);
    exit('OK');
}

// --- 4. Refunds / chargebacks / cancellations: suspend the account -------
if (in_array($action, ['refund', 'chargeback', 'rebill_cancel', 'cancel'], true)) {
    $stmt = db()->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if ($user) {
        $upd = db()->prepare("UPDATE users SET status = 'suspended' WHERE id = ?");
        $upd->execute([$user['id']]);
        ipn_log("Suspended user #{$user['id']} ($email) due to action='$action'.");
    } else {
        ipn_log("No matching user for refund/cancel action, email=$email.");
    }
    http_response_code(200);
    exit('OK');
}

ipn_log("Unrecognized action '$action' - no changes made.");
http_response_code(200);
exit('OK (unrecognized action, logged only).');
