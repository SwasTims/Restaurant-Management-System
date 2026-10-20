<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_role(['waiter']);

$statuses = ['free', 'occupied', 'reserved'];
$status_labels = ['free' => 'Free', 'occupied' => 'Occupied', 'reserved' => 'Reserved'];
$status_badges = [
    'free'     => 'badge-active',
    'occupied' => 'badge-inactive',
    'reserved' => 'badge-low',
];

// Which badge style each order status uses (same as the admin Orders page)
$order_badges = [
    'pending'   => 'badge-low',
    'preparing' => 'badge-info',
    'ready'     => 'badge-active',
    'served'    => 'badge-muted',
];

$filter = $_GET['status'] ?? '';
if (!in_array($filter, $statuses, true)) {
    $filter = '';
}

// ---- How many tables in each status (for the chips) ----
$counts = array_fill_keys($statuses, 0);
foreach ($pdo->query('SELECT status, COUNT(*) AS n FROM restaurant_tables GROUP BY status')->fetchAll() as $row) {
    $counts[$row['status']] = (int) $row['n'];
}
$total_tables = array_sum($counts);

// ---- The tables to show ----
$sql = 'SELECT id, name, capacity, status FROM restaurant_tables';
$params = [];
if ($filter !== '') {
    $sql .= ' WHERE status = ?';
    $params[] = $filter;
}
$sql .= ' ORDER BY LENGTH(name), name';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tables = $stmt->fetchAll();

// ---- Open orders for all tables, in one query, grouped by table ----
$open_orders = [];
$stmt = $pdo->query(
    "SELECT o.id, o.table_id, o.status, o.created_at,
            u.name AS waiter_name,
            COALESCE(SUM(oi.unit_price * oi.quantity), 0) AS total
     FROM orders o
     JOIN users u ON u.id = o.waiter_id
     LEFT JOIN order_items oi ON oi.order_id = o.id
     WHERE o.status NOT IN ('paid', 'cancelled')
     GROUP BY o.id, o.table_id, o.status, o.created_at, u.name
     ORDER BY o.id"
);
foreach ($stmt->fetchAll() as $order) {
    $open_orders[$order['table_id']][] = $order;
}

$page_title = 'Tables';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="chip-row">
    <a class="btn btn-small btn-light <?= $filter === '' ? 'btn-active' : '' ?>"
       href="<?= BASE_URL ?>/staff/tables">All (<?= $total_tables ?>)</a>
    <?php foreach ($statuses as $status): ?>
        <a class="btn btn-small btn-light <?= $filter === $status ? 'btn-active' : '' ?>"
           href="<?= BASE_URL ?>/staff/tables?status=<?= e($status) ?>">
            <?= e($status_labels[$status]) ?> (<?= $counts[$status] ?>)
        </a>
    <?php endforeach; ?>
</div>

<?php if (count($tables) === 0): ?>
    <div class="table-wrap">
        <p class="table-empty">
            <?= $filter !== '' ? 'No tables with this status.' : 'No tables have been set up yet. Ask the admin to add them.' ?>
        </p>
    </div>
<?php endif; ?>

<div class="table-grid">
    <?php foreach ($tables as $table): ?>
        <?php $orders_here = $open_orders[$table['id']] ?? []; ?>
        <div class="table-card table-<?= e($table['status']) ?>">
            <div class="table-card-head">
                <span class="table-card-name"><?= e($table['name']) ?></span>
                <span class="badge <?= e($status_badges[$table['status']]) ?>">
                    <?= e($status_labels[$table['status']]) ?>
                </span>
            </div>

            <div class="table-card-seats"><?= (int) $table['capacity'] ?> seats</div>

            <?php if (count($orders_here) > 0): ?>
                <ul class="table-orders">
                    <?php foreach ($orders_here as $order): ?>
                        <li>
                            <div class="table-orders-top">
                                <strong>#<?= (int) $order['id'] ?></strong>
                                <span class="badge <?= e($order_badges[$order['status']] ?? 'badge-muted') ?>">
                                    <?= e($order['status']) ?>
                                </span>
                            </div>
                            <div class="table-orders-sub">
                                Rs. <?= e(number_format((float) $order['total'], 2)) ?>
                                &middot; <?= e($order['waiter_name']) ?>
                                &middot; <?= e(date('h:i A', strtotime($order['created_at']))) ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if ($table['status'] !== 'occupied'): ?>
                <a class="btn btn-small table-card-action"
                   href="<?= BASE_URL ?>/staff/new-order?table=<?= (int) $table['id'] ?>">
                    New order
                </a>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>