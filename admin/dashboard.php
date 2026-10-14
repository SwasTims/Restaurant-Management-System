<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_role(['admin']);

$page_title = 'Admin dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<h2 class="page-heading">Admin dashboard</h2>
<p>You are logged in as admin, <strong><?= e($user['name']) ?></strong>.</p>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>