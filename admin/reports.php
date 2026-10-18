<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_role(['admin']);

$max_days = 92;

// Turns "2026-10-18" into a date at midnight, or null if it is not a real date
function parse_date($value)
{
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    return ($date && $date->format('Y-m-d') === $value) ? $date : null;
}

$today = new DateTime('today');

$from = parse_date(trim($_GET['from'] ?? ''));
$to = parse_date(trim($_GET['to'] ?? ''));

// Defaults: the last 7 days, ending today
if (!$to) {
    $to = clone $today;
}
if (!$from) {
    $from = clone $to;
    $from->modify('-6 days');
}

// If the dates were entered the wrong way round, swap them
if ($from > $to) {
    [$from, $to] = [$to, $from];
}

// Keep the range small enough for the page to stay quick
$note = '';
if ($from->diff($to)->days + 1 > $max_days) {
    $from = clone $to;
    $from->modify('-' . ($max_days - 1) . ' days');
    $note = 'The range was shortened to ' . $max_days . ' days.';
}

// From the start of the first day, up to (not including) the start of the day after the last
$start = $from->format('Y-m-d') . ' 00:00:00';
$end = (clone $to)->modify('+1 day')->format('Y-m-d') . ' 00:00:00';

// Sales are counted from paid orders only
$range_sql = "o.status = 'paid' AND o.created_at >= ? AND o.created_at < ?";

// ---- Summary numbers ----
$stmt = $pdo->prepare(
    "SELECT COUNT(DISTINCT o.id) AS orders,
            COALESCE(SUM(oi.quantity), 0) AS items,
            COALESCE(SUM(oi.unit_price * oi.quantity), 0) AS revenue
     FROM orders o
     LEFT JOIN order_items oi ON oi.order_id = o.id
     WHERE $range_sql"
);
$stmt->execute([$start, $end]);
$summary = $stmt->fetch();

$order_count = (int) $summary['orders'];
$revenue = (float) $summary['revenue'];
$average = $order_count > 0 ? $revenue / $order_count : 0;

// ---- Sales per day ----
$stmt = $pdo->prepare(
    "SELECT DATE(o.created_at) AS day,
            COUNT(DISTINCT o.id) AS orders,
            COALESCE(SUM(oi.unit_price * oi.quantity), 0) AS revenue
     FROM orders o
     LEFT JOIN order_items oi ON oi.order_id = o.id
     WHERE $range_sql
     GROUP BY DATE(o.created_at)"
);
$stmt->execute([$start, $end]);

$by_day = [];
foreach ($stmt->fetchAll() as $row) {
    $by_day[$row['day']] = $row;
}

// Every day in the range gets a row, with zeros where nothing was sold
$days = [];
$best = 0;
$cursor = clone $from;
while ($cursor <= $to) {
    $key = $cursor->format('Y-m-d');
    $day_orders = isset($by_day[$key]) ? (int) $by_day[$key]['orders'] : 0;
    $day_revenue = isset($by_day[$key]) ? (float) $by_day[$key]['revenue'] : 0.0;

    $days[] = [
        'date'    => clone $cursor,
        'orders'  => $day_orders,
        'revenue' => $day_revenue,
    ];
    $best = max($best, $day_revenue);
    $cursor->modify('+1 day');
}
$days = array_reverse($days); // newest first

// ---- Top selling items ----
$stmt = $pdo->prepare(
    "SELECT oi.item_name,
            SUM(oi.quantity) AS qty,
            SUM(oi.unit_price * oi.quantity) AS revenue
     FROM order_items oi
     JOIN orders o ON o.id = oi.order_id
     WHERE $range_sql
     GROUP BY oi.item_name
     ORDER BY qty DESC, revenue DESC
     LIMIT 10"
);
$stmt->execute([$start, $end]);
$top_items = $stmt->fetchAll();

// ---- Quick range links ----
$month_start = new DateTime('first day of this month');
$quick = [
    'Today'        => [$today->format('Y-m-d'), $today->format('Y-m-d')],
    'Last 7 days'  => [(clone $today)->modify('-6 days')->format('Y-m-d'), $today->format('Y-m-d')],
    'Last 30 days' => [(clone $today)->modify('-29 days')->format('Y-m-d'), $today->format('Y-m-d')],
    'This month'   => [$month_start->format('Y-m-d'), $today->format('Y-m-d')],
];

$page_title = 'Reports';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="toolbar">
    <form method="get" class="filters">
        <label class="inline-label" for="from">From</label>
        <input type="date" id="from" name="from" value="<?= e($from->format('Y-m-d')) ?>">

        <label class="inline-label" for="to">To</label>
        <input type="date" id="to" name="to" value="<?= e($to->format('Y-m-d')) ?>">

        <button type="submit" class="btn">Show</button>
    </form>

    <div class="range-links">
        <?php foreach ($quick as $label => $range): ?>
            <a class="btn btn-small btn-light"
               href="<?= BASE_URL ?>/admin/reports?<?= e(http_build_query(['from' => $range[0], 'to' => $range[1]])) ?>">
                <?= e($label) ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>

<p class="result-count">
    <?= e($from->format('d M Y')) ?> to <?= e($to->format('d M Y')) ?>.
    Sales are counted from paid orders only.
    <?= $note !== '' ? '<strong class="attention">' . e($note) . '</strong>' : '' ?>
</p>

<div class="cards">
    <div class="card">
        <div class="card-label">Revenue</div>
        <div class="card-value">Rs. <?= e(number_format($revenue, 2)) ?></div>
    </div>
    <div class="card">
        <div class="card-label">Paid orders</div>
        <div class="card-value"><?= $order_count ?></div>
    </div>
    <div class="card">
        <div class="card-label">Average order</div>
        <div class="card-value">Rs. <?= e(number_format($average, 2)) ?></div>
    </div>
    <div class="card">
        <div class="card-label">Items sold</div>
        <div class="card-value"><?= (int) $summary['items'] ?></div>
    </div>
</div>

<div class="report-grid">
    <div>
        <h3 class="section-title">Sales per day</h3>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th class="num">Orders</th>
                        <th class="num">Revenue</th>
                        <th class="bar-col"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($days as $day): ?>
                        <?php $percent = $best > 0 ? round($day['revenue'] / $best * 100, 1) : 0; ?>
                        <tr>
                            <td><?= e($day['date']->format('D, d M')) ?></td>
                            <td class="num"><?= $day['orders'] ?></td>
                            <td class="num">Rs. <?= e(number_format($day['revenue'], 2)) ?></td>
                            <td class="bar-col">
                                <div class="bar"><span style="width: <?= (float) $percent ?>%"></span></div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div>
        <h3 class="section-title">Top selling items</h3>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th class="num">Sold</th>
                        <th class="num">Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($top_items) === 0): ?>
                        <tr>
                            <td colspan="3" class="table-empty">No paid orders in this range.</td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($top_items as $item): ?>
                        <tr>
                            <td><?= e($item['item_name']) ?></td>
                            <td class="num"><?= (int) $item['qty'] ?></td>
                            <td class="num">Rs. <?= e(number_format((float) $item['revenue'], 2)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>