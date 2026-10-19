<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_role(['waiter', 'kitchen', 'cashier']);

$role = current_user()['role'];
$my_id = (int) current_user()['id'];

// Each card: [label, number, small text, link path]
$cards = [];

if ($role === 'waiter') {
    $free = (int) $pdo->query(
        "SELECT COUNT(*) FROM restaurant_tables WHERE status = 'free'"
    )->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM orders
         WHERE waiter_id = ? AND status NOT IN ('paid', 'cancelled')"
    );
    $stmt->execute([$my_id]);
    $mine = (int) $stmt->fetchColumn();

    $cards = [
        ['Free tables',    $free, 'ready for guests',  '/staff/tables'],
        ['My open orders', $mine, 'not yet paid',      '/staff/orders'],
    ];
} elseif ($role === 'kitchen') {
    $waiting = (int) $pdo->query(
        "SELECT COUNT(*) FROM orders WHERE status = 'pending'"
    )->fetchColumn();

    $cooking = (int) $pdo->query(
        "SELECT COUNT(*) FROM orders WHERE status = 'preparing'"
    )->fetchColumn();

    $cards = [
        ['Waiting to start', $waiting, 'new orders',      '/staff/kitchen'],
        ['Being prepared',   $cooking, 'in the kitchen',  '/staff/kitchen'],
    ];
} else { // cashier
    $to_bill = (int) $pdo->query(
        "SELECT COUNT(*) FROM orders WHERE status = 'served'"
    )->fetchColumn();

    $in_progress = (int) $pdo->query(
        "SELECT COUNT(*) FROM orders WHERE status IN ('pending', 'preparing', 'ready')"
    )->fetchColumn();

    $cards = [
        ['Waiting for payment', $to_bill,     'served, not yet paid', '/staff/billing'],
        ['Still in the kitchen', $in_progress, 'bills coming soon',    '/staff/billing'],
    ];
}

$page_title = 'Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<p class="dash-welcome">Hello, <strong><?= e($user['name']) ?></strong>. Here is what is happening right now.</p>

<div class="cards">
    <?php foreach ($cards as $card): ?>
        <a class="card card-link" href="<?= BASE_URL . $card[3] ?>">
            <div class="card-label"><?= e($card[0]) ?></div>
            <div class="card-value"><?= (int) $card[1] ?></div>
            <div class="card-sub"><?= e($card[2]) ?></div>
        </a>
    <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>