<?php
/** Include after bootstrap.php on every admin page except index.php (login) and logout.php. */
if (empty($_SESSION['is_admin'])) {
    redirect(app_path('/admin/index.php'));
}
