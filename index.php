<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

set_flash('success', 'Day 2 works!');

$page_title = 'Home';
require_once __DIR__ . '/includes/header.php';
?>

<h1>Welcome</h1>
<p>Database connected and layout working.</p>

<?php require_once __DIR__ . '/includes/footer.php'; ?>