<?php
// Every role's menu in one place: [label, path]
$menus = [
    'admin' => [
        ['Dashboard',      '/admin/dashboard'],
        ['Staff accounts', '/admin/accounts'],
        ['Orders',         '/admin/orders'],
        ['Menu items',     '/admin/menu'],
        ['Tables',         '/admin/tables'],
        ['Inventory',      '/admin/inventory'],
        ['Reports',        '/admin/reports'],
    ],
    'waiter' => [
        ['Dashboard', '/staff/dashboard'],
        ['Tables',    '/staff/tables'],
        ['Orders',    '/staff/orders'],
    ],
    'kitchen' => [
        ['Dashboard', '/staff/dashboard'],
        ['Kitchen',   '/staff/kitchen'],
    ],
    'cashier' => [
        ['Dashboard', '/staff/dashboard'],
        ['Billing',   '/staff/billing'],
    ],
];

$links = $menus[current_user()['role']] ?? [];
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