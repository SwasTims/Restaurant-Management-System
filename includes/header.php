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

<div class="app-shell">

    <?php require __DIR__ . '/sidebar.php'; ?>

    <div class="app-main">

        <header class="topbar">
            <div class="topbar-left">
                <button type="button" class="menu-toggle" id="menuToggle" aria-label="Toggle menu">
                    <span></span>
                    <span></span>
                    <span></span>
                </button>
                <div class="topbar-title"><?= e($page_title) ?></div>
            </div>
            <div class="topbar-user">
                <span><?= e($user['name']) ?></span>
                <span class="role-badge"><?= e($user['role']) ?></span>
            </div>
        </header>

        <main class="app-content">
            <?php if ($flash): ?>
                <div class="alert alert-<?= e($flash['type']) ?>">
                    <?= e($flash['message']) ?>
                </div>
            <?php endif; ?>