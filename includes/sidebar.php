<?php
$links = [
    ['Dashboard',      '/admin/dashboard'],
    ['Staff accounts', '/admin/accounts'],
    ['Orders',         '/admin/orders'],
    ['Menu items',     '/admin/menu'],
    ['Inventory',      '/admin/inventory'],
    ['Reports',        '/admin/reports'],
];

$current = $_SERVER['REQUEST_URI'];
?>
<aside class="sidebar">
    <div class="sidebar-brand">Restaurant Management</div>

    <nav class="sidebar-nav">
        <?php foreach ($links as $link): ?>
            <?php $is_active = strpos($current, BASE_URL . $link[1]) === 0; ?>
            <a href="<?= BASE_URL . $link[1] ?>" class="<?= $is_active ? 'active' : '' ?>">
                <?= e($link[0]) ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <a class="sidebar-logout" href="<?= BASE_URL ?>/logout">Logout</a>
</aside>