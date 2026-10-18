<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_role(['admin']);

$statuses = ['pending', 'preparing', 'ready', 'served', 'paid', 'cancelled'];

// Which badge style each status uses
$badge_classes = [
    'pending'   => 'badge-low',
    'preparing' => 'badge-info',
    'ready'     => 'badge-active',
    'served'    => 'badge-muted',
    'paid'      => 'badge-paid',
    'cancelled' => 'badge-inactive',
];

// Where to go after saving: the same page, keeping any active filters
$back = '/admin/orders';
if ($_SERVER['QUERY_STRING'] !== '') {
    $back .= '?' . $_SERVER['QUERY_STRING'];
}

$action = $_POST['action'] ?? '';

// ---- Change an order's status ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'set_order_status') {
    $order_id = (int) ($_POST['id'] ?? 0);
    $new_status = $_POST['status'] ?? '';

    if (!in_array($new_status, $statuses, true)) {
        set_flash('error', 'Choose a valid status.');
    } else {
        $stmt = $pdo->prepare('UPDATE orders SET status = ? WHERE id = ?');
        $stmt->execute([$new_status, $order_id]);
        set_flash('success', 'Order #' . $order_id . ' marked as ' . $new_status . '.');
    }
    redirect($back);
}

// ---- Filters (read from the address bar) ----
$tables = $pdo->query('SELECT id, name FROM restaurant_tables ORDER BY LENGTH(name), name')->fetchAll();
$table_ids = array_map('intval', array_column($tables, 'id'));

$filter_status = $_GET['status'] ?? '';
$filter_table = (int) ($_GET['table'] ?? 0);
$filter_date = trim($_GET['date'] ?? '');

// The date must be a real date in the form YYYY-MM-DD
$date_check = DateTime::createFromFormat('Y-m-d', $filter_date);
if (!$date_check || $date_check->format('Y-m-d') !== $filter_date) {
    $filter_date = '';
}

$where = [];
$params = [];

if (in_array($filter_status, $statuses, true)) {
    $where[] = 'o.status = ?';
    $params[] = $filter_status;
}
if (in_array($filter_table, $table_ids, true)) {
    $where[] = 'o.table_id = ?';
    $params[] = $filter_table;
}
if ($filter_date !== '') {
    $where[] = 'DATE(o.created_at) = ?';
    $params[] = $filter_date;
}

$sql = 'SELECT o.id, o.status, o.created_at,
               t.name AS table_name,
               u.name AS waiter_name,
               COALESCE(SUM(oi.quantity), 0) AS item_count,
               COALESCE(SUM(oi.unit_price * oi.quantity), 0) AS total
        FROM orders o
        JOIN restaurant_tables t ON t.id = o.table_id
        JOIN users u ON u.id = o.waiter_id
        LEFT JOIN order_items oi ON oi.order_id = o.id';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' GROUP BY o.id, o.status, o.created_at, t.name, u.name
          ORDER BY o.id DESC
          LIMIT 100';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

$is_filtered = in_array($filter_status, $statuses, true)
    || in_array($filter_table, $table_ids, true)
    || $filter_date !== '';

// ---- Lines of the listed orders (one query for all of them) ----
$lines = [];
if ($orders) {
    $ids = array_column($orders, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $stmt = $pdo->prepare(
        'SELECT order_id, item_name, unit_price, quantity
         FROM order_items
         WHERE order_id IN (' . $placeholders . ')
         ORDER BY id'
    );
    $stmt->execute($ids);

    foreach ($stmt->fetchAll() as $line) {
        $lines[$line['order_id']][] = $line;
    }
}

$page_title = 'Orders';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="toolbar">
    <form method="get" class="filters">
        <select name="status">
            <option value="">All statuses</option>
            <?php foreach ($statuses as $status): ?>
                <option value="<?= e($status) ?>" <?= $filter_status === $status ? 'selected' : '' ?>>
                    <?= e(ucfirst($status)) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select name="table">
            <option value="0">All tables</option>
            <?php foreach ($tables as $table): ?>
                <option value="<?= (int) $table['id'] ?>" <?= $filter_table === (int) $table['id'] ? 'selected' : '' ?>>
                    <?= e($table['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <input type="date" name="date" value="<?= e($filter_date) ?>">

        <button type="submit" class="btn">Filter</button>
        <?php if ($is_filtered): ?>
            <a class="btn btn-light" href="<?= BASE_URL ?>/admin/orders">Reset</a>
        <?php endif; ?>
    </form>
</div>

<p class="result-count">
    Showing <?= count($orders) ?> orders<?= count($orders) === 100 ? ' (the latest 100, use the filters to narrow down)' : '' ?>
</p>

<div class="table-wrap">
    <table class="table">
        <thead>
            <tr>
                <th>Order</th>
                <th>Table</th>
                <th>Waiter</th>
                <th>Items</th>
                <th>Total</th>
                <th>Status</th>
                <th>Placed</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($orders) === 0): ?>
                <tr>
                    <td colspan="8" class="table-empty">
                        <?= $is_filtered ? 'No orders match these filters.' : 'No orders yet.' ?>
                    </td>
                </tr>
            <?php endif; ?>

            <?php foreach ($orders as $order): ?>
                <tr>
                    <td>#<?= (int) $order['id'] ?></td>
                    <td><?= e($order['table_name']) ?></td>
                    <td><?= e($order['waiter_name']) ?></td>
                    <td><?= (int) $order['item_count'] ?></td>
                    <td>Rs. <?= e(number_format((float) $order['total'], 2)) ?></td>
                    <td>
                        <span class="badge <?= e($badge_classes[$order['status']]) ?>">
                            <?= e($order['status']) ?>
                        </span>
                    </td>
                    <td><?= e(date('d M, h:i A', strtotime($order['created_at']))) ?></td>
                    <td class="actions">
                        <button type="button" class="btn btn-small btn-light"
                                data-open-dialog="order<?= (int) $order['id'] ?>">
                            View
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- One popup per order: its lines, total and a status control -->
<?php foreach ($orders as $order): ?>
    <?php $order_lines = $lines[$order['id']] ?? []; ?>
    <dialog id="order<?= (int) $order['id'] ?>" class="dialog dialog-wide">
        <div class="dialog-body">
            <div class="dialog-head">
                <h2>Order #<?= (int) $order['id'] ?></h2>
                <button type="button" class="dialog-close" data-close-dialog aria-label="Close">&times;</button>
            </div>

            <p class="order-meta">
                Table <?= e($order['table_name']) ?>
                &middot; Waiter <?= e($order['waiter_name']) ?>
                &middot; <?= e(date('d M Y, h:i A', strtotime($order['created_at']))) ?>
            </p>

            <div class="table-wrap">
                <table class="table lines-table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th class="num">Price</th>
                            <th class="num">Qty</th>
                            <th class="num">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($order_lines) === 0): ?>
                            <tr>
                                <td colspan="4" class="table-empty">This order has no items.</td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($order_lines as $line): ?>
                            <tr>
                                <td><?= e($line['item_name']) ?></td>
                                <td class="num"><?= e(number_format((float) $line['unit_price'], 2)) ?></td>
                                <td class="num"><?= (int) $line['quantity'] ?></td>
                                <td class="num"><?= e(number_format((float) $line['unit_price'] * (int) $line['quantity'], 2)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3" class="num">Total</td>
                            <td class="num">Rs. <?= e(number_format((float) $order['total'], 2)) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <form method="post">
                <input type="hidden" name="action" value="set_order_status">
                <input type="hidden" name="id" value="<?= (int) $order['id'] ?>">

                <label for="status<?= (int) $order['id'] ?>">Status</label>
                <select id="status<?= (int) $order['id'] ?>" name="status">
                    <?php foreach ($statuses as $status): ?>
                        <option value="<?= e($status) ?>" <?= $order['status'] === $status ? 'selected' : '' ?>>
                            <?= e(ucfirst($status)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <div class="dialog-actions">
                    <button type="button" class="btn btn-light" data-close-dialog>Close</button>
                    <button type="submit" class="btn">Update status</button>
                </div>
            </form>
        </div>
    </dialog>
<?php endforeach; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>