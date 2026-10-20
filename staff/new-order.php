<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_role(['waiter']);

$user_id = (int) current_user()['id'];
$upload_url = BASE_URL . '/assets/uploads/menu';
$errors = [];

// ---- The table this order is for ----
$table_id = (int) ($_POST['table_id'] ?? $_GET['table'] ?? 0);

$stmt = $pdo->prepare('SELECT id, name, capacity, status FROM restaurant_tables WHERE id = ?');
$stmt->execute([$table_id]);
$table = $stmt->fetch();

if (!$table) {
    set_flash('error', 'Choose a table first.');
    redirect('/staff/tables');
}
if ($table['status'] === 'occupied') {
    set_flash('error', 'Table ' . $table['name'] . ' is already occupied.');
    redirect('/staff/tables');
}

// ---- The dishes that can be ordered right now ----
$menu = $pdo->query(
    'SELECT m.id, m.name, m.price, m.image, c.name AS category_name
     FROM menu_items m
     JOIN categories c ON c.id = m.category_id
     WHERE m.is_available = 1
     ORDER BY c.name, m.name'
)->fetchAll();

$menu_by_id = [];
$by_category = [];
foreach ($menu as $item) {
    $menu_by_id[(int) $item['id']] = $item;
    $by_category[$item['category_name']][] = $item;
}

// ---- Send the order ----
$quantities = []; // remembered so a failed submit keeps what the waiter picked

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = $_POST['qty'] ?? [];
    if (!is_array($raw)) {
        $raw = [];
    }

    $lines = [];
    foreach ($raw as $id => $qty) {
        if (!is_scalar($qty)) {
            continue;
        }
        $id = (int) $id;
        $qty = trim((string) $qty);

        if ($qty === '' || $qty === '0') {
            continue; // not ordered
        }
        if (!preg_match('/^\d{1,2}$/', $qty)) {
            $errors[] = 'Quantities must be whole numbers from 1 to 99.';
            continue;
        }
        if (!isset($menu_by_id[$id])) {
            $errors[] = 'A dish is no longer available. Check the list and try again.';
            continue;
        }
        $lines[$id] = (int) $qty;
        $quantities[$id] = (int) $qty;
    }

    if (!$errors && count($lines) === 0) {
        $errors[] = 'Add at least one dish to the order.';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            // Seat the table, but only if it is still free or reserved
            $seat = $pdo->prepare(
                "UPDATE restaurant_tables SET status = 'occupied'
                 WHERE id = ? AND status IN ('free', 'reserved')"
            );
            $seat->execute([$table['id']]);

            if ($seat->rowCount() === 0) {
                $pdo->rollBack();
                set_flash('error', 'Table ' . $table['name'] . ' was just taken by someone else.');
                redirect('/staff/tables');
            }

            $stmt = $pdo->prepare("INSERT INTO orders (table_id, waiter_id, status) VALUES (?, ?, 'pending')");
            $stmt->execute([$table['id'], $user_id]);
            $order_id = (int) $pdo->lastInsertId();

            // Each line keeps a copy of the dish's name and price (prices come from the database)
            $line = $pdo->prepare(
                'INSERT INTO order_items (order_id, menu_item_id, item_name, unit_price, quantity)
                 VALUES (?, ?, ?, ?, ?)'
            );
            foreach ($lines as $id => $qty) {
                $dish = $menu_by_id[$id];
                $line->execute([$order_id, $id, $dish['name'], $dish['price'], $qty]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        set_flash('success', 'Order #' . $order_id . ' sent to the kitchen.');
        redirect('/staff/tables');
    }
}

$page_title = 'New order';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="toolbar">
    <div class="order-for">
        Table <strong><?= e($table['name']) ?></strong>
        &middot; <?= (int) $table['capacity'] ?> seats
        <?php if ($table['status'] === 'reserved'): ?>
            <span class="badge badge-low">Reserved</span>
            <span class="order-for-note">Sending the order seats the guests.</span>
        <?php endif; ?>
    </div>
    <a class="btn btn-light" href="<?= BASE_URL ?>/staff/tables">&larr; Back to tables</a>
</div>

<?php if ($errors): ?>
    <div class="alert alert-error">
        <ul class="error-list">
            <?php foreach (array_unique($errors) as $error): ?>
                <li><?= e($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if (count($menu) === 0): ?>
    <div class="table-wrap">
        <p class="table-empty">No dishes are available right now. Ask the admin to update the menu.</p>
    </div>
<?php else: ?>
    <form method="post" id="orderForm">
        <input type="hidden" name="table_id" value="<?= (int) $table['id'] ?>">

        <div class="order-layout">
            <div>
                <input type="text" id="menuSearch" class="menu-search" placeholder="Search the menu" autocomplete="off">

                <?php foreach ($by_category as $category_name => $items): ?>
                    <div class="menu-group">
                        <h3 class="menu-group-title"><?= e($category_name) ?></h3>

                        <?php foreach ($items as $item): ?>
                            <?php $picked = $quantities[(int) $item['id']] ?? 0; ?>
                            <div class="menu-row <?= $picked > 0 ? 'is-picked' : '' ?>"
                                 data-name="<?= e($item['name']) ?>"
                                 data-price="<?= e($item['price']) ?>">
                                <?php if ($item['image']): ?>
                                    <img class="thumb" src="<?= e($upload_url . '/' . $item['image']) ?>" alt="">
                                <?php else: ?>
                                    <div class="thumb thumb-empty"><?= e(mb_strtoupper(mb_substr($item['name'], 0, 1))) ?></div>
                                <?php endif; ?>

                                <div class="menu-row-info">
                                    <div class="menu-row-name"><?= e($item['name']) ?></div>
                                    <div class="menu-row-price">Rs. <?= e(number_format((float) $item['price'], 2)) ?></div>
                                </div>

                                <div class="qty-control">
                                    <button type="button" class="qty-btn qty-minus" aria-label="One less">&minus;</button>
                                    <input type="text" class="qty-input" name="qty[<?= (int) $item['id'] ?>]"
                                           value="<?= (int) $picked ?>" inputmode="numeric" maxlength="2"
                                           aria-label="Quantity of <?= e($item['name']) ?>">
                                    <button type="button" class="qty-btn qty-plus" aria-label="One more">+</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <aside class="order-summary">
                <h3 class="section-title">Order summary</h3>
                <p class="summary-empty" id="summaryEmpty">Nothing added yet.</p>
                <ul class="summary-list" id="summaryList"></ul>
                <div class="summary-total">
                    <span>Total</span>
                    <span id="summaryTotal">Rs. 0.00</span>
                </div>
                <button type="submit" class="btn" id="sendOrder">Send to kitchen</button>
            </aside>
        </div>
    </form>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>