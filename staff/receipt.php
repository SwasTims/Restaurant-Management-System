<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_role(['cashier']);

$methods = ['cash' => 'Cash', 'card' => 'Card', 'online' => 'Online'];
$order_id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT o.id, o.created_at, o.paid_at, o.discount_percent, o.discount_amount,
            o.vat_percent, o.vat_amount, o.total, o.payment_method,
            t.name AS table_name, u.name AS waiter_name
     FROM orders o
     JOIN restaurant_tables t ON t.id = o.table_id
     JOIN users u ON u.id = o.waiter_id
     WHERE o.id = ? AND o.status = 'paid'"
);
$stmt->execute([$order_id]);
$order = $stmt->fetch();

if (!$order) {
    set_flash('error', 'That receipt is not available. Only paid orders have one.');
    redirect('/staff/billing?show=paid');
}

$stmt = $pdo->prepare(
    'SELECT item_name, unit_price, quantity FROM order_items WHERE order_id = ? ORDER BY id'
);
$stmt->execute([$order_id]);
$lines = $stmt->fetchAll();

$subtotal_c = 0;
foreach ($lines as $line) {
    $subtotal_c += (int) round((float) $line['unit_price'] * 100) * (int) $line['quantity'];
}

// Orders paid before billing existed have no saved amounts: show them without discount or VAT
if ($order['total'] === null) {
    $discount = 0.0;
    $vat = 0.0;
    $total = $subtotal_c / 100;
} else {
    $discount = (float) $order['discount_amount'];
    $vat = (float) $order['vat_amount'];
    $total = (float) $order['total'];
}

$paid_time = $order['paid_at'] ?? $order['created_at'];
$just_paid = ($_GET['paid'] ?? '') === '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt #<?= (int) $order['id'] ?> | Restaurant Management System</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body class="receipt-page">

<?php if ($just_paid): ?>
    <div class="alert alert-success receipt-banner no-print">
        Payment recorded. The table has been freed.
    </div>
<?php endif; ?>

<div class="receipt">
    <h1>Restaurant Management System</h1>
    <p class="receipt-sub">Receipt</p>

    <div class="receipt-meta">
        Order #<?= (int) $order['id'] ?><br>
        Table <?= e($order['table_name']) ?> &middot; Waiter <?= e($order['waiter_name']) ?><br>
        <?= e(date('d M Y, h:i A', strtotime($paid_time))) ?>
    </div>

    <table>
        <thead>
            <tr>
                <th>Item</th>
                <th class="num">Qty</th>
                <th class="num">Amount</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($lines as $line): ?>
                <tr>
                    <td><?= e($line['item_name']) ?></td>
                    <td class="num"><?= (int) $line['quantity'] ?></td>
                    <td class="num"><?= e(number_format((float) $line['unit_price'] * (int) $line['quantity'], 2)) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="bill-summary">
        <div class="bill-row">
            <span>Subtotal</span>
            <span>Rs. <?= e(number_format($subtotal_c / 100, 2)) ?></span>
        </div>
        <?php if ($discount > 0): ?>
            <div class="bill-row">
                <span>Discount (<?= e(rtrim(rtrim(number_format((float) $order['discount_percent'], 2), '0'), '.')) ?>%)</span>
                <span>- Rs. <?= e(number_format($discount, 2)) ?></span>
            </div>
        <?php endif; ?>
        <?php if ($vat > 0): ?>
            <div class="bill-row">
                <span>VAT (<?= e(rtrim(rtrim(number_format((float) $order['vat_percent'], 2), '0'), '.')) ?>%)</span>
                <span>Rs. <?= e(number_format($vat, 2)) ?></span>
            </div>
        <?php endif; ?>
        <div class="bill-row bill-row-total">
            <span>Total</span>
            <span>Rs. <?= e(number_format($total, 2)) ?></span>
        </div>
        <div class="bill-row">
            <span>Paid by</span>
            <span><?= e($methods[$order['payment_method']] ?? '-') ?></span>
        </div>
    </div>

    <p class="receipt-thanks">Thank you for dining with us.</p>
</div>

<div class="receipt-actions no-print">
    <button type="button" class="btn" onclick="window.print()">Print receipt</button>
    <a class="btn btn-light" href="<?= BASE_URL ?>/staff/billing">Back to billing</a>
</div>

</body>
</html>