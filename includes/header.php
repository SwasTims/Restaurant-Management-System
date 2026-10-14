<?php
require_once __DIR__ . '/auth.php';
require_login();

$user = current_user();
$page_title = $page_title ?? 'Dashboard';
$flash = get_flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($page_title) ?> | Restaurant Management System</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body class="app">

<header class="app-topbar">
    <a class="app-brand" href="<?= BASE_URL . home_for_role($user['role']) ?>">
        <span class="brand-mark">R</span>
        <span>Restaurant Management</span>
    </a>
    <div class="app-topbar-right">
        <span><?= e($user['name']) ?></span>
        <span class="role-badge"><?= e($user['role']) ?></span>
        <a class="topbar-logout" href="<?= BASE_URL ?>/logout">Logout</a>
    </div>
</header>

<main class="app-content">
    <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?>">
            <?= e($flash['message']) ?>
        </div>
    <?php endif; ?>