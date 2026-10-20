<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_role(['waiter']);

$user_id = (int) current_user()['id'];

// Which badge style each order status uses
$badge_classes = [
    'pending'   => 'badge-low',
    'preparing' => 'badge-info',
    'ready'     => 'badge-active',
    'served'    => 'badge-muted',
    'paid'      => 'badge-paid',
    'cancelled' => 'badge-inactive',
];

// The views the chips switch between: [label, SQL condition]
$views = [
    'open'    => ['Open',    "o.status NOT IN ('paid', 'cancelled')"],
    'ready'   => ['Ready',   "o.status = 'ready'"],
    'history' => ['History', "o.status IN ('paid', 'cancelled')"],
    'all'     => ['All',     '1 = 1'],
];

$show = $_GET['show'] ?? 'open';
if (!isset($views[$show])) {
    $show = 'open';
}

// Where to go after an action: the same page, keeping the chosen view
$back = '/staff/orders';
if ($_SERVER['QUERY_STRING'] !== '') {
    $back .= '?' . $_SERVER['QUERY_STRING'];
}

$action = $_POST['action'] ?? '';

// ---- Mark an order as served ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'mark_served') {
    $order_id = (int) ($_POST['id'] ?? 0);

    $stmt = $pdo->prepare(
        "UPDATE orders SET status = 'served'
         WHERE id = ? AND waiter_id = ? AND status = 'ready'"
    );
    $stmt->execute([$order_id, $user_id]);

    if ($stmt->rowCount() > 0) {
        set_flash('success', 'Order #' . $order_id . ' marked as served.');
    } else {
        set_flash('error', 'That order cannot be marked as served. It may not be ready yet.');
    }
    redirect($back);
}

// ---- Cancel an order (only while it is still pending) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'cancel_order') {
    $order_id = (int) ($_POST['id'] ?? 0);
    $cancelled = false;

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            "UPDATE orders SET status = 'cancelled'
             WHERE id = ? AND waiter_id = ? AND status = 'pending'"
        );
        $stmt->execute([$order_id, $user_id]);

        if ($stmt->rowCount() > 0) {
            $stmt = $pdo->prepare('SELECT table_id FROM orders WHERE id = ?');
            $stmt->execute([$order_id]);
            $table_id = (int) $stmt->fetchColumn();

            // Free the table, but only if no other open order is using it
            $stmt = $pdo->prepare(
                "UPDATE restaurant_tables SET status = 'free'
                 WHERE id = ? AND status = 'occupied'
                   AND NOT EXISTS (
                       SELECT 1 FROM orders
                       WHERE table_id = ? AND status NOT IN ('paid', 'cancelled')
                   )"
            );
            $stmt->execute([$table_id, $table_id]);

            $pdo->commit();
            $cancelled = true;
        } else {
            $pdo->rollBack();
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    if ($cancelled) {
        set_flash('success', 'Order #' . $order_id . ' cancelled.');
    } else {
        set_flash('error', 'Only pending orders can be cancelled. The kitchen may already have started it.');
    }
    redirect($back);
}

// ---- Counts for the chips ----
$stmt = $pdo->prepare(
    "SELECT COUNT(*) AS total,
            COALESCE(SUM(status NOT IN ('paid', 'cancelled')), 0) AS open_count,
            COALESCE(SUM(status = 'ready'), 0) AS ready_count,
            COALESCE(SUM(status IN ('paid', 'cancelled')), 0) AS history_count
     FROM orders WHERE waiter_id = ?"
);
$stmt->execute([$user_id]);
$counts_row = $stmt->fetch();
$counts = [
    'open'    => (int) $counts_row['open_count'],
    'ready'   => (int) $counts_row['ready_count'],
    'history' => (int) $counts_row['history_count'],
    'all'     => (int) $counts_row['total'],
];

// ---- This waiter's orders for the chosen view ----
$stmt = $pdo->prepare(
    'SELECT o.id, o.status, o.created_at,
            t.name AS table_name,
            COALESCE(SUM(oi.quantity), 0) AS item_count,
            COALESCE(SUM(oi.unit_price * oi.quantity), 0) AS total
     FROM orders o
     JOIN restaurant_tables t ON t.id = o.table_id
     LEFT JOIN order_items oi ON oi.order_id = o.id
     WHERE o.waiter_id = ? AND ' . $views[$show][1] . '
     GROUP BY o.id, o.status, o.created_at, t.name
     ORDER BY o.id DESC
     LIMIT 50'
);
$stmt->execute([$user_id]);
$orders = $stmt->fetchAll();

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

$empty_text = [
    'open'    => 'You have no open orders. Pick a table to start one.',
    'ready'   => 'Nothing is ready to serve right now.',
    'history' => 'No paid or cancelled orders yet.',
    'all'     => 'You have not taken any orders yet.',
];

$page_title = 'My orders';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="chip-row">
    <?php foreach ($views as $key => $view): ?>
        <a class="btn btn-small btn-light <?= $show === $key ? 'btn-active' : '' ?>"
           href="<?= BASE_URL ?>/staff/orders?show=<?= e($key) ?>">
            <?= e($view[0]) ?> (<?= $counts[$key] ?>)
        </a>
    <?php endforeach; ?>
</div>

<p class="result-count">
    Showing <?= count($orders) ?> orders<?= count($orders) === 50 ? ' (the latest 50)' : '' ?>
</p>

<div class="table-wrap">
    <table class="table">
        <thead>
            <tr>
                <th>Order</th>
                <th>Table</th>
                <th>Items</th>
                <th class="num">Total</th>
                <th>Status</th>
                <th>Placed</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($orders) === 0): ?>
                <tr>
                    <td colspan="7" class="table-empty"><?= e($empty_text[$show]) ?></td>
                </tr>
            <?php endif; ?>

            <?php foreach ($orders as $order): ?>
                <tr class="<?= $order['status'] === 'ready' ? 'row-ready' : '' ?>">
                    <td>#<?= (int) $order['id'] ?></td>
                    <td><?= e($order['table_name']) ?></td>
                    <td><?= (int) $order['item_count'] ?></td>
                    <td class="num">Rs. <?= e(number_format((float) $order['total'], 2)) ?></td>
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

                        <?php if ($order['status'] === 'ready'): ?>
                            <form method="post" class="inline-form">
                                <input type="hidden" name="action" value="mark_served">
                                <input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
                                <button type="submit" class="btn btn-small">Mark served</button>
                            </form>
                        <?php elseif ($order['status'] === 'pending'): ?>
                            <button type="button" class="btn btn-small btn-danger" data-cancel
                                    data-id="<?= (int) $order['id'] ?>"
                                    data-name="#<?= (int) $order['id'] ?> (Table <?= e($order['table_name']) ?>)">
                                Cancel
                            </button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- One details popup per order -->
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
                &middot; <?= e(date('d M Y, h:i A', strtotime($order['created_at']))) ?>
                &middot;
                <span class="badge <?= e($badge_classes[$order['status']]) ?>"><?= e($order['status']) ?></span>
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

            <div class="dialog-actions">
                <button type="button" class="btn btn-light" data-close-dialog>Close</button>
            </div>
        </div>
    </dialog>
<?php endforeach; ?>

<!-- Cancel confirmation popup -->
<dialog id="cancelDialog" class="dialog">
    <div class="dialog-body">
        <div class="dialog-head">
            <h2>Cancel order</h2>
            <button type="button" class="dialog-close" data-close-dialog aria-label="Close">&times;</button>
        </div>

        <p class="confirm-text">
            Cancel order <strong id="cancelName"></strong>? The kitchen will not make it,
            and the table is freed if nothing else is open on it.
        </p>

        <form method="post">
            <input type="hidden" name="action" value="cancel_order">
            <input type="hidden" name="id" value="">

            <div class="dialog-actions">
                <button type="button" class="btn btn-light" data-close-dialog>Keep order</button>
                <button type="submit" class="btn btn-danger">Cancel order</button>
            </div>
        </form>
    </div>
</dialog>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>