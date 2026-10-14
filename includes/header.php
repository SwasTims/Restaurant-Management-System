<?php
require_once __DIR__ . '/functions.php';
$flash = get_flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($page_title ?? 'Restaurant Management System') ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>
    <nav class="navbar">
        <a href="<?= BASE_URL ?>/" class="brand">Restaurant Management System</a>
        <div class="nav-links">
            <a href="<?= BASE_URL ?>/dashboard.php">Dashboard</a>
            <a href="<?= BASE_URL ?>/menu/">Menu</a>
            <a href="<?= BASE_URL ?>/orders/">Orders</a>
        </div>
    </nav>

    <main class="container">
        <?php if ($flash): ?>
            <div class="alert alert-<?= e($flash['type']) ?>">
                <?= e($flash['message']) ?>
            </div>
        <?php endif; ?>