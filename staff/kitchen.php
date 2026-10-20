<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_role(['kitchen']);

// The three columns of the board: status => heading
$columns = [
    'pending'   => 'New',
    'preparing' => 'Preparing',
    'ready'     => 'Ready',
];

// How many minutes an unfinished order may wait before it turns amber
$late_after = 15;

// 7 -> "7 min", 75 -> "1h 15m", 0 -> "just now"
function format_age($minutes)
{
    $minutes = (int) $minutes;
    if ($minutes < 1) {
        return 'just now';
    }
    if ($minutes < 60) {
        return $minutes . ' min';
    }
    return intdiv($minutes, 60) . 'h ' . ($minutes % 60) . 'm';
}

$action = $_POST['action'] ?? '';

// ---- Start cooking: pending -> preparing ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'start_order') {
    $order_id = (int) ($_POST['id'] ?? 0);

    $stmt = $pdo->prepare("UPDATE orders SET status = 'preparing' WHERE id = ? AND status = 'pending'");
    $stmt->execute([$order_id]);

    if ($stmt->rowCount() > 0) {
        set_flash('success', 'Order #' . $order_id . ' is being prepared.');
    } else {
        set_flash('error', 'Order #' . $order_id . ' could not be started. It may have been cancelled.');
    }
    redirect('/staff/kitchen');
}

// ---- Mark ready: preparing -> ready ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'mark_ready') {
    $order_id = (int) ($_POST['id'] ?? 0);

    $stmt = $pdo->prepare("UPDATE orders SET status = 'ready' WHERE id = ? AND status = 'preparing'");
    $stmt->execute([$order_id]);

    if ($stmt->rowCount() > 0) {
        set_flash('success', 'Order #' . $order_id . ' is ready for the waiter.');
    } else {
        set_flash('error', 'Order #' . $order_id . ' could not be marked ready.');
    }
    redirect('/staff/kitchen');
}

// ---- The orders on the board, oldest first ----
$orders = $pdo->query(
    "SELECT o.id, o.status, o.created_at,
            TIMESTAMPDIFF(MINUTE, o.created_at, NOW()) AS age_minutes,
            t.name AS table_name,
            u.name AS waiter_name
     FROM orders o
     JOIN restaurant_tables t ON t.id = o.table_id
     JOIN users u ON u.id = o.waiter_id
     WHERE o.status IN ('pending', 'preparing', 'ready')
     ORDER BY o.id
     LIMIT 60"
)->fetchAll();

// ---- The dishes of those orders (one query for all of them) ----
$lines = [];
if ($orders) {
    $ids = array_column($orders, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $stmt = $pdo->prepare(
        'SELECT order_id, item_name, quantity
         FROM order_items
         WHERE order_id IN (' . $placeholders . ')
         ORDER BY id'
    );
    $stmt->execute($ids);

    foreach ($stmt->fetchAll() as $line) {
        $lines[$line['order_id']][] = $line;
    }
}

// ---- Sort the orders into their columns ----
$board = array_fill_keys(array_keys($columns), []);
foreach ($orders as $order) {
    $board[$order['status']][] = $order;
}

$page_title = 'Kitchen';
require_once __DIR__ . '/../includes/header.php';
?>

<div data-refresh="30"></div>

<p class="result-count">
    This screen refreshes by itself every 30 seconds.
    Tickets turn amber after <?= (int) $late_after ?> minutes.
</p>

<div class="kitchen-board">
    <?php foreach ($columns as $status => $heading): ?>
        <section class="kitchen-column">
            <h3 class="kitchen-column-title">
                <?= e($heading) ?>
                <span class="kitchen-count"><?= count($board[$status]) ?></span>
            </h3>

            <?php if (count($board[$status]) === 0): ?>
                <p class="kitchen-empty">
                    <?php if ($status === 'pending'): ?>
                        No new orders.
                    <?php elseif ($status === 'preparing'): ?>
                        Nothing on the stove.
                    <?php else: ?>
                        Nothing waiting for the waiter.
                    <?php endif; ?>
                </p>
            <?php endif; ?>

            <?php foreach ($board[$status] as $order): ?>
                <?php
                $is_late = $status !== 'ready' && (int) $order['age_minutes'] >= $late_after;
                $order_lines = $lines[$order['id']] ?? [];
                ?>
                <article class="ticket <?= $is_late ? 'ticket-late' : '' ?>">
                    <div class="ticket-head">
                        <span class="ticket-table">Table <?= e($order['table_name']) ?></span>
                        <span class="ticket-id">#<?= (int) $order['id'] ?></span>
                    </div>

                    <div class="ticket-meta">
                        <?= e(format_age($order['age_minutes'])) ?> ago
                        &middot; <?= e($order['waiter_name']) ?>
                    </div>

                    <ul class="ticket-lines">
                        <?php foreach ($order_lines as $line): ?>
                            <li>
                                <span class="ticket-qty"><?= (int) $line['quantity'] ?> &times;</span>
                                <?= e($line['item_name']) ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <?php if ($status === 'pending'): ?>
                        <form method="post">
                            <input type="hidden" name="action" value="start_order">
                            <input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
                            <button type="submit" class="btn btn-small ticket-button">Start cooking</button>
                        </form>
                    <?php elseif ($status === 'preparing'): ?>
                        <form method="post">
                            <input type="hidden" name="action" value="mark_ready">
                            <input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
                            <button type="submit" class="btn btn-small ticket-button">Mark ready</button>
                        </form>
                    <?php else: ?>
                        <div class="ticket-note">Waiting for the waiter</div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>