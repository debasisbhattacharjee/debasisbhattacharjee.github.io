<?php
require_once __DIR__ . '/includes/bootstrap.php';

if (!empty($_SESSION['user_id'])) {
    redirect(app_path('/dashboard/index.php'));
}
redirect(app_path('/auth/login.php'));
