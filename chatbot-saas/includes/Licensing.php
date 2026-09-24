<?php
/**
 * Plan tiers (front end + OTOs), monthly message-credit metering, and
 * license key generation/redemption. Keeping this in one file makes it
 * easy to retune limits after launch without hunting through the app.
 */

const PLAN_LIMITS = [
    'trial' => [
        'label' => 'Free Trial',
        'max_bots' => 1,
        'monthly_messages' => 50,
        'agency' => false,
        'white_label' => false,
    ],
    'front_end' => [
        'label' => 'Front End',
        'max_bots' => 1,
        'monthly_messages' => 500,
        'agency' => false,
        'white_label' => false,
    ],
    'oto1_unlimited' => [
        'label' => 'OTO1 - Unlimited',
        'max_bots' => 25,
        'monthly_messages' => 5000,
        'agency' => false,
        'white_label' => false,
    ],
    'oto3_agency' => [
        'label' => 'OTO3 - Agency',
        'max_bots' => 100,
        'monthly_messages' => 20000,
        'agency' => true,
        'white_label' => false,
    ],
    'oto4_whitelabel' => [
        'label' => 'OTO4 - White Label',
        'max_bots' => 100,
        'monthly_messages' => 20000,
        'agency' => true,
        'white_label' => true,
    ],
];

function plan_limits(string $plan): array
{
    return PLAN_LIMITS[$plan] ?? PLAN_LIMITS['trial'];
}

/** Reset the monthly counter if we've rolled into a new calendar month. */
function ensure_monthly_reset(array &$user): void
{
    $currentMonth = gmdate('Y-m-01');
    if ($user['credits_reset_at'] !== $currentMonth) {
        $stmt = db()->prepare('UPDATE users SET credits_used_month = 0, credits_reset_at = ? WHERE id = ?');
        $stmt->execute([$currentMonth, $user['id']]);
        $user['credits_used_month'] = 0;
        $user['credits_reset_at'] = $currentMonth;
    }
}

/**
 * Call once per visitor chat message, BEFORE calling Claude.
 * @return array{ok:bool, error:?string}
 */
function check_and_increment_usage(array &$user): array
{
    ensure_monthly_reset($user);

    // Bring-your-own-key customers use their own Anthropic billing, so the
    // vendor's monthly credit pool doesn't apply to them.
    if (trim($user['own_api_key']) !== '') {
        return ['ok' => true, 'error' => null];
    }

    $limits = plan_limits($user['plan']);
    if ((int)$user['credits_used_month'] >= $limits['monthly_messages']) {
        return ['ok' => false, 'error' => 'Monthly message limit reached for this plan (' . $limits['monthly_messages'] . '). Please upgrade or add your own Anthropic API key in Account settings.'];
    }

    $stmt = db()->prepare('UPDATE users SET credits_used_month = credits_used_month + 1 WHERE id = ?');
    $stmt->execute([$user['id']]);
    $user['credits_used_month'] = (int)$user['credits_used_month'] + 1;

    return ['ok' => true, 'error' => null];
}

function can_create_bot(array $user): bool
{
    $limits = plan_limits($user['plan']);
    $stmt = db()->prepare('SELECT COUNT(*) AS n FROM bots WHERE user_id = ?');
    $stmt->execute([$user['id']]);
    return (int)$stmt->fetch()['n'] < $limits['max_bots'];
}

/** The Anthropic API key this user's bots should call the API with. */
function effective_api_key(array $user): string
{
    return trim($user['own_api_key']) !== '' ? trim($user['own_api_key']) : ANTHROPIC_API_KEY;
}

// ---------------------------------------------------------------------
// License keys
// ---------------------------------------------------------------------

function license_generate_key(): string
{
    $groups = [];
    for ($i = 0; $i < 4; $i++) {
        $groups[] = strtoupper(bin2hex(random_bytes(2)));
    }
    return 'CBS-' . implode('-', $groups);
}

/** Create a new, unredeemed license row (called by the IPN handler or admin panel). */
function license_create(string $plan, string $email = '', string $wplusTxn = '', string $wplusProduct = ''): string
{
    $key = license_generate_key();
    $stmt = db()->prepare(
        'INSERT INTO licenses (license_key, plan, email, wplus_txn_id, wplus_product_id, created_at) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$key, $plan, $email, $wplusTxn, $wplusProduct, now()]);
    return $key;
}

/** @return array{0:bool, 1:?array, 2:?string} */
function license_lookup_unredeemed(string $key): array
{
    $key = strtoupper(trim($key));
    $stmt = db()->prepare('SELECT * FROM licenses WHERE license_key = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch();

    if (!$row) {
        return [false, null, 'That license key was not found. Please check it and try again.'];
    }
    if ($row['redeemed_by_user_id']) {
        return [false, null, 'That license key has already been activated.'];
    }
    return [true, $row, null];
}

/**
 * Auto-match: WarriorPlus lets you set a custom Thank You / download URL
 * with a placeholder for the buyer's email (check your WarriorPlus vendor
 * dashboard's product delivery settings for the exact placeholder syntax).
 * Point it at /auth/register.php?email=... and a buyer who registers with
 * that same email automatically picks up the license the IPN created for
 * them - no outbound email required from this app.
 */
function license_find_unredeemed_by_email(string $email): ?array
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return null;
    }
    $stmt = db()->prepare(
        "SELECT * FROM licenses WHERE lower(email) = ? AND redeemed_by_user_id IS NULL ORDER BY created_at DESC LIMIT 1"
    );
    $stmt->execute([$email]);
    return $stmt->fetch() ?: null;
}

function license_redeem(int $licenseId, int $userId): void
{
    $stmt = db()->prepare('UPDATE licenses SET redeemed_by_user_id = ?, redeemed_at = ? WHERE id = ?');
    $stmt->execute([$userId, now(), $licenseId]);
}
