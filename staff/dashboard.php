<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_role(['waiter', 'kitchen', 'cashier']);

$role = current_user()['role'];
$user_id = (int) current_user()['id'];

// Today: from midnight up to (not including) midnight tomorrow
$today = new DateTime('today');
$start = $today->format('Y-m-d') . ' 00:00:00';
$end = (clone $today)->modify('+1 day')->format('Y-m-d') . ' 00:00:00';

// A short line under the greeting, per role
$intro = [
    'waiter'  => 'Pick a table to start a new order, or follow up on your open ones.',
    'kitchen' => 'New orders appear here as soon as the waiters send them.',
    'cashier' => 'Served orders wait here for payment.',
];

// Each card is [label, number, small text, link]
$cards = [];

if ($role === 'waiter') {
    $free_tables = (int) $pdo->query(
        "SELECT COUNT(*) FROM restaurant_tables WHERE status = 'free'"
    )->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM orders
         WHERE waiter_id = ? AND status NOT IN ('paid', 'cancelled')"
    );
    $stmt->execute([$user_id]);
    $my_open = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE waiter_id = ? AND status = 'ready'");
    $stmt->execute([$user_id]);
    $ready_to_serve = (int) $stmt->fetchColumn();

    $cards = [
        ['Free tables',    $free_tables,    'ready for guests',    '/staff/tables'],
        ['My open orders', $my_open,        'not yet paid',        '/staff/orders'],
        ['Ready to serve', $ready_to_serve, 'the kitchen is done', '/staff/orders?show=ready'],
    ];
} elseif ($role === 'kitchen') {
    $pending = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'")->fetchColumn();
    $preparing = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'preparing'")->fetchColumn();
    $ready = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'ready'")->fetchColumn();

    $cards = [
        ['New orders', $pending,   'waiting to be started',  '/staff/kitchen'],
        ['Preparing',  $preparing, 'on the stove',           '/staff/kitchen'],
        ['Ready',      $ready,     'waiting for the waiter', '/staff/kitchen'],
    ];
} else { // cashier
    $waiting = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'served'")->fetchColumn();

        $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM orders
         WHERE status = 'paid' AND paid_at >= ? AND paid_at < ?"
    );
    $stmt->execute([$start, $end]);
    $paid_today = (int) $stmt->fetchColumn();

    $cards = [
        ['Waiting for payment', $waiting,    'served, not yet paid', '/staff/billing'],
        ['Paid today',          $paid_today, 'orders closed',        '/staff/billing'],
    ];
}

$page_title = 'Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<p class="dash-welcome">
    Hello, <strong><?= e($user['name']) ?></strong>. <?= e($intro[$role]) ?>
</p>

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