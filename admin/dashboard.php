<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_role(['admin']);

// Which badge style each order status uses (same as the Orders page)
$badge_classes = [
    'pending'   => 'badge-low',
    'preparing' => 'badge-info',
    'ready'     => 'badge-active',
    'served'    => 'badge-muted',
    'paid'      => 'badge-paid',
    'cancelled' => 'badge-inactive',
];

// Today: from midnight up to (not including) midnight tomorrow
$today = new DateTime('today');
$start = $today->format('Y-m-d') . ' 00:00:00';
$end = (clone $today)->modify('+1 day')->format('Y-m-d') . ' 00:00:00';

// ---- Today's sales (paid orders only) ----
$stmt = $pdo->prepare(
    "SELECT COUNT(DISTINCT o.id) AS orders,
            COALESCE(SUM(oi.unit_price * oi.quantity), 0) AS revenue
     FROM orders o
     LEFT JOIN order_items oi ON oi.order_id = o.id
     WHERE o.status = 'paid' AND o.created_at >= ? AND o.created_at < ?"
);
$stmt->execute([$start, $end]);
$sales = $stmt->fetch();

// ---- Open orders: not paid and not cancelled ----
$open_orders = (int) $pdo->query(
    "SELECT COUNT(*) FROM orders WHERE status NOT IN ('paid', 'cancelled')"
)->fetchColumn();

// ---- Tables in use ----
$tables = $pdo->query(
    "SELECT COUNT(*) AS total, COALESCE(SUM(status = 'occupied'), 0) AS occupied
     FROM restaurant_tables"
)->fetch();

// ---- Stock and menu ----
$low_stock = (int) $pdo->query(
    'SELECT COUNT(*) FROM ingredients WHERE quantity <= low_stock_level'
)->fetchColumn();

$out_dishes = (int) $pdo->query(
    'SELECT COUNT(*) FROM menu_items WHERE is_available = 0'
)->fetchColumn();

// ---- Staff ----
$active_staff = (int) $pdo->query(
    "SELECT COUNT(*) FROM users WHERE role != 'admin' AND status = 'active'"
)->fetchColumn();

// ---- Latest open orders ----
$latest_open = $pdo->query(
    "SELECT o.id, o.status, o.created_at,
            t.name AS table_name,
            COALESCE(SUM(oi.unit_price * oi.quantity), 0) AS total
     FROM orders o
     JOIN restaurant_tables t ON t.id = o.table_id
     LEFT JOIN order_items oi ON oi.order_id = o.id
     WHERE o.status NOT IN ('paid', 'cancelled')
     GROUP BY o.id, o.status, o.created_at, t.name
     ORDER BY o.id DESC
     LIMIT 8"
)->fetchAll();

// ---- Ingredients that need restocking (emptiest first) ----
$to_restock = $pdo->query(
    'SELECT name, unit, quantity, low_stock_level
     FROM ingredients
     WHERE quantity <= low_stock_level
     ORDER BY quantity ASC, name
     LIMIT 8'
)->fetchAll();

// 12.50 -> "12.5", 12.00 -> "12"
function format_qty($value)
{
    return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
}

$todays_report = BASE_URL . '/admin/reports?' . http_build_query([
    'from' => $today->format('Y-m-d'),
    'to'   => $today->format('Y-m-d'),
]);

$page_title = 'Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<p class="dash-welcome">Welcome back, <strong><?= e($user['name']) ?></strong>. Here is where things stand right now.</p>

<div class="cards">
    <a class="card card-link" href="<?= e($todays_report) ?>">
        <div class="card-label">Today's revenue</div>
        <div class="card-value">Rs. <?= e(number_format((float) $sales['revenue'], 2)) ?></div>
        <div class="card-sub"><?= (int) $sales['orders'] ?> paid orders</div>
    </a>

    <a class="card card-link" href="<?= BASE_URL ?>/admin/orders">
        <div class="card-label">Open orders</div>
        <div class="card-value"><?= $open_orders ?></div>
        <div class="card-sub">not yet paid</div>
    </a>

    <a class="card card-link" href="<?= BASE_URL ?>/admin/tables">
        <div class="card-label">Tables in use</div>
        <div class="card-value"><?= (int) $tables['occupied'] ?> / <?= (int) $tables['total'] ?></div>
        <div class="card-sub">occupied right now</div>
    </a>

    <a class="card card-link <?= $low_stock > 0 ? 'card-warn' : '' ?>" href="<?= BASE_URL ?>/admin/inventory">
        <div class="card-label">Need restocking</div>
        <div class="card-value"><?= $low_stock ?></div>
        <div class="card-sub">ingredients at or below their alert level</div>
    </a>

    <a class="card card-link <?= $out_dishes > 0 ? 'card-warn' : '' ?>" href="<?= BASE_URL ?>/admin/menu?availability=unavailable">
        <div class="card-label">Dishes out of stock</div>
        <div class="card-value"><?= $out_dishes ?></div>
        <div class="card-sub">hidden from ordering</div>
    </a>

    <a class="card card-link" href="<?= BASE_URL ?>/admin/accounts">
        <div class="card-label">Active staff</div>
        <div class="card-value"><?= $active_staff ?></div>
        <div class="card-sub">excluding admins</div>
    </a>
</div>

<div class="report-grid">
    <div>
        <h3 class="section-title">Open orders</h3>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>Table</th>
                        <th class="num">Total</th>
                        <th>Status</th>
                        <th>Placed</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($latest_open) === 0): ?>
                        <tr>
                            <td colspan="5" class="table-empty">No open orders right now.</td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($latest_open as $order): ?>
                        <tr>
                            <td>#<?= (int) $order['id'] ?></td>
                            <td><?= e($order['table_name']) ?></td>
                            <td class="num">Rs. <?= e(number_format((float) $order['total'], 2)) ?></td>
                            <td>
                                <span class="badge <?= e($badge_classes[$order['status']]) ?>">
                                    <?= e($order['status']) ?>
                                </span>
                            </td>
                            <td><?= e(date('d M, h:i A', strtotime($order['created_at']))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="more-link"><a href="<?= BASE_URL ?>/admin/orders">View all orders &rarr;</a></p>
    </div>

    <div>
        <h3 class="section-title">Needs restocking</h3>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Ingredient</th>
                        <th class="num">In stock</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($to_restock) === 0): ?>
                        <tr>
                            <td colspan="2" class="table-empty">Everything is stocked.</td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($to_restock as $ingredient): ?>
                        <tr>
                            <td><?= e($ingredient['name']) ?></td>
                            <td class="num">
                                <?= e(format_qty($ingredient['quantity'])) ?> <?= e($ingredient['unit']) ?>
                                <?php if ((float) $ingredient['quantity'] <= 0): ?>
                                    <span class="badge badge-inactive">Out</span>
                                <?php else: ?>
                                    <span class="badge badge-low">Low</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="more-link"><a href="<?= BASE_URL ?>/admin/inventory">Open inventory &rarr;</a></p>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>