<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_role(['cashier']);

$vat_percent = 13; // VAT added on top of the menu prices (change it here if the rate changes)
$methods = ['cash' => 'Cash', 'card' => 'Card', 'online' => 'Online'];

// Today: from midnight up to (not including) midnight tomorrow
$today = new DateTime('today');
$start = $today->format('Y-m-d') . ' 00:00:00';
$end = (clone $today)->modify('+1 day')->format('Y-m-d') . ' 00:00:00';

$show = $_GET['show'] ?? 'topay';
if (!in_array($show, ['topay', 'paid'], true)) {
    $show = 'topay';
}

// Where to go after an action: the same page, keeping the chosen view
$back = '/staff/billing';
if ($_SERVER['QUERY_STRING'] !== '') {
    $back .= '?' . $_SERVER['QUERY_STRING'];
}

$action = $_POST['action'] ?? '';

// ---- Take payment: served -> paid ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'pay_order') {
    $order_id = (int) ($_POST['id'] ?? 0);
    $discount_raw = trim($_POST['discount'] ?? '');
    $method = $_POST['method'] ?? '';

    if ($discount_raw === '') {
        $discount_raw = '0';
    }

    $problem = '';
    if (!preg_match('/^\d{1,3}(\.\d{1,2})?$/', $discount_raw) || (float) $discount_raw > 100) {
        $problem = 'Discount must be a percentage from 0 to 100.';
    } elseif (!isset($methods[$method])) {
        $problem = 'Choose a payment method.';
    }

    $paid = false;

    if ($problem === '') {
        try {
            $pdo->beginTransaction();

            // The amounts are worked out from the saved lines, in whole paisa
            $stmt = $pdo->prepare(
                'SELECT COALESCE(SUM(unit_price * quantity), 0) FROM order_items WHERE order_id = ?'
            );
            $stmt->execute([$order_id]);
            $subtotal_c = (int) round((float) $stmt->fetchColumn() * 100);

            if ($subtotal_c <= 0) {
                $pdo->rollBack();
                $problem = 'This order has no items to bill.';
            } else {
                $discount_pct = (float) $discount_raw;
                $discount_c = (int) round($subtotal_c * $discount_pct / 100);
                $after_c = $subtotal_c - $discount_c;
                $vat_c = (int) round($after_c * $vat_percent / 100);
                $total_c = $after_c + $vat_c;

                // Close the order, but only if it is still waiting for payment
                $stmt = $pdo->prepare(
                    "UPDATE orders
                     SET status = 'paid',
                         discount_percent = ?, discount_amount = ?,
                         vat_percent = ?, vat_amount = ?,
                         total = ?, payment_method = ?, paid_at = NOW()
                     WHERE id = ? AND status = 'served'"
                );
                $stmt->execute([
                    number_format($discount_pct, 2, '.', ''),
                    number_format($discount_c / 100, 2, '.', ''),
                    number_format($vat_percent, 2, '.', ''),
                    number_format($vat_c / 100, 2, '.', ''),
                    number_format($total_c / 100, 2, '.', ''),
                    $method,
                    $order_id,
                ]);

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
                    $paid = true;
                } else {
                    $pdo->rollBack();
                    $problem = 'That order is no longer waiting for payment.';
                }
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    if ($paid) {
        redirect('/staff/receipt?id=' . $order_id . '&paid=1');
    }
    set_flash('error', $problem);
    redirect($back);
}

// ---- Numbers for the chips and the summary line ----
$to_pay_count = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'served'")->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT COUNT(*) AS n, COALESCE(SUM(total), 0) AS money
     FROM orders WHERE status = 'paid' AND paid_at >= ? AND paid_at < ?"
);
$stmt->execute([$start, $end]);
$paid_today = $stmt->fetch();

// ---- The orders for the chosen view ----
if ($show === 'topay') {
    $condition = "o.status = 'served'";
    $params = [];
    $order_by = 'o.id ASC'; // the longest-waiting guests first
} else {
    $condition = "o.status = 'paid' AND o.paid_at >= ? AND o.paid_at < ?";
    $params = [$start, $end];
    $order_by = 'o.paid_at DESC';
}

$stmt = $pdo->prepare(
    "SELECT o.id, o.created_at, o.paid_at, o.payment_method, o.total AS paid_total,
            t.name AS table_name,
            u.name AS waiter_name,
            COALESCE(SUM(oi.quantity), 0) AS item_count,
            COALESCE(SUM(oi.unit_price * oi.quantity), 0) AS subtotal
     FROM orders o
     JOIN restaurant_tables t ON t.id = o.table_id
     JOIN users u ON u.id = o.waiter_id
     LEFT JOIN order_items oi ON oi.order_id = o.id
     WHERE $condition
     GROUP BY o.id, o.created_at, o.paid_at, o.payment_method, o.total, t.name, u.name
     ORDER BY $order_by
     LIMIT 50"
);
$stmt->execute($params);
$orders = $stmt->fetchAll();

// ---- Lines of the orders waiting for payment (one query for all of them) ----
$lines = [];
if ($show === 'topay' && $orders) {
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

$page_title = 'Billing';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="chip-row">
    <a class="btn btn-small btn-light <?= $show === 'topay' ? 'btn-active' : '' ?>"
       href="<?= BASE_URL ?>/staff/billing">To pay (<?= $to_pay_count ?>)</a>
    <a class="btn btn-small btn-light <?= $show === 'paid' ? 'btn-active' : '' ?>"
       href="<?= BASE_URL ?>/staff/billing?show=paid">Paid today (<?= (int) $paid_today['n'] ?>)</a>
</div>

<p class="result-count">
    <?php if ($show === 'topay'): ?>
        Orders the waiters have served. VAT of <?= e(rtrim(rtrim(number_format($vat_percent, 2), '0'), '.')) ?>% is added on top of the menu prices.
    <?php else: ?>
        Collected today: <strong>Rs. <?= e(number_format((float) $paid_today['money'], 2)) ?></strong>
        from <?= (int) $paid_today['n'] ?> orders.
    <?php endif; ?>
</p>

<div class="table-wrap">
    <table class="table">
        <thead>
            <tr>
                <th>Order</th>
                <th>Table</th>
                <th>Waiter</th>
                <th>Items</th>
                <th class="num"><?= $show === 'topay' ? 'Subtotal' : 'Paid' ?></th>
                <th><?= $show === 'topay' ? 'Placed' : 'Method' ?></th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($orders) === 0): ?>
                <tr>
                    <td colspan="7" class="table-empty">
                        <?= $show === 'topay' ? 'No orders are waiting for payment.' : 'No payments taken today yet.' ?>
                    </td>
                </tr>
            <?php endif; ?>

            <?php foreach ($orders as $order): ?>
                <tr>
                    <td>#<?= (int) $order['id'] ?></td>
                    <td><?= e($order['table_name']) ?></td>
                    <td><?= e($order['waiter_name']) ?></td>
                    <td><?= (int) $order['item_count'] ?></td>
                    <?php if ($show === 'topay'): ?>
                        <td class="num">Rs. <?= e(number_format((float) $order['subtotal'], 2)) ?></td>
                        <td><?= e(date('h:i A', strtotime($order['created_at']))) ?></td>
                        <td class="actions">
                            <button type="button" class="btn btn-small"
                                    data-open-dialog="bill<?= (int) $order['id'] ?>">
                                Bill
                            </button>
                        </td>
                    <?php else: ?>
                        <td class="num">Rs. <?= e(number_format((float) $order['paid_total'], 2)) ?></td>
                        <td><?= e($methods[$order['payment_method']] ?? '-') ?></td>
                        <td class="actions">
                            <a class="btn btn-small btn-light"
                               href="<?= BASE_URL ?>/staff/receipt?id=<?= (int) $order['id'] ?>">
                                Receipt
                            </a>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- One bill popup per order waiting for payment -->
<?php if ($show === 'topay'): ?>
    <?php foreach ($orders as $order): ?>
        <?php
        $order_lines = $lines[$order['id']] ?? [];
        $subtotal_c = (int) round((float) $order['subtotal'] * 100);
        $vat_c = (int) round($subtotal_c * $vat_percent / 100);
        $total_c = $subtotal_c + $vat_c;
        ?>
        <dialog id="bill<?= (int) $order['id'] ?>" class="dialog dialog-wide">
            <div class="dialog-body">
                <div class="dialog-head">
                    <h2>Bill for order #<?= (int) $order['id'] ?></h2>
                    <button type="button" class="dialog-close" data-close-dialog aria-label="Close">&times;</button>
                </div>

                <p class="order-meta">
                    Table <?= e($order['table_name']) ?> &middot; Waiter <?= e($order['waiter_name']) ?>
                </p>

                <div class="table-wrap">
                    <table class="table lines-table">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th class="num">Qty</th>
                                <th class="num">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($order_lines as $line): ?>
                                <tr>
                                    <td><?= e($line['item_name']) ?></td>
                                    <td class="num"><?= (int) $line['quantity'] ?></td>
                                    <td class="num"><?= e(number_format((float) $line['unit_price'] * (int) $line['quantity'], 2)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <form method="post"
                      data-bill
                      data-subtotal="<?= e(number_format($subtotal_c / 100, 2, '.', '')) ?>"
                      data-vat="<?= (float) $vat_percent ?>">
                    <input type="hidden" name="action" value="pay_order">
                    <input type="hidden" name="id" value="<?= (int) $order['id'] ?>">

                    <div class="dialog-grid">
                        <div>
                            <label for="discount<?= (int) $order['id'] ?>">Discount (%)</label>
                            <input type="text" id="discount<?= (int) $order['id'] ?>" name="discount"
                                   inputmode="decimal" value="0">
                        </div>
                        <div>
                            <label for="method<?= (int) $order['id'] ?>">Paid by</label>
                            <select id="method<?= (int) $order['id'] ?>" name="method">
                                <?php foreach ($methods as $key => $label): ?>
                                    <option value="<?= e($key) ?>"><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="bill-summary">
                        <div class="bill-row">
                            <span>Subtotal</span>
                            <span>Rs. <?= e(number_format($subtotal_c / 100, 2)) ?></span>
                        </div>
                        <div class="bill-row">
                            <span>Discount</span>
                            <span>- Rs. <span class="bill-discount">0.00</span></span>
                        </div>
                        <div class="bill-row">
                            <span>VAT (<?= e(rtrim(rtrim(number_format($vat_percent, 2), '0'), '.')) ?>%)</span>
                            <span>Rs. <span class="bill-vat"><?= e(number_format($vat_c / 100, 2)) ?></span></span>
                        </div>
                        <div class="bill-row bill-row-total">
                            <span>Total to collect</span>
                            <span>Rs. <span class="bill-total"><?= e(number_format($total_c / 100, 2)) ?></span></span>
                        </div>
                    </div>

                    <div class="dialog-actions">
                        <button type="button" class="btn btn-light" data-close-dialog>Cancel</button>
                        <button type="submit" class="btn">Confirm payment</button>
                    </div>
                </form>
            </div>
        </dialog>
    <?php endforeach; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>