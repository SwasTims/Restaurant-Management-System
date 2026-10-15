<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_role(['admin']);

$page_title = 'Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<p>Welcome back, <strong><?= e($user['name']) ?></strong>.</p>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>