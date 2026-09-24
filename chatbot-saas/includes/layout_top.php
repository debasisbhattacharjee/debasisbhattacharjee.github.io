<?php
/**
 * Shared HTML header. Include with a $pageTitle variable set.
 * Kept as a plain include (no template engine) - concatenate PHP/HTML freely.
 */
$pageTitle = $pageTitle ?? APP_NAME;
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($pageTitle) ?></title>
<link rel="stylesheet" href="<?= h(app_path('/public/css/app.css')) ?>">
</head>
<body>
<header class="topbar">
  <a class="brand" href="<?= h(app_path('/dashboard/index.php')) ?>"><?= h(APP_NAME) ?></a>
  <?php if (!empty($_SESSION['user_id'])): ?>
    <nav>
      <a href="<?= h(app_path('/dashboard/index.php')) ?>">My Bots</a>
      <a href="<?= h(app_path('/dashboard/account.php')) ?>">Account</a>
      <a href="<?= h(app_path('/auth/logout.php')) ?>">Log out</a>
    </nav>
  <?php endif; ?>
</header>
<main class="wrap">
<?php
$err = flash_get('error');
$ok = flash_get('success');
if ($err): ?><div class="alert alert-error"><?= h($err) ?></div><?php endif;
if ($ok): ?><div class="alert alert-success"><?= h($ok) ?></div><?php endif;
