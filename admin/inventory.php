<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_role(['admin']);

$units = ['kg', 'g', 'l', 'ml', 'pcs'];
$decimal = '/^\d{1,8}(\.\d{1,2})?$/'; // 0 or more, up to 2 decimal places

// 12.50 -> "12.5", 12.00 -> "12"
function format_qty($value)
{
    return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
}

// Works out the label for a row: out, low or ok
function stock_status($quantity, $low_level)
{
    if ((float) $quantity <= 0) {
        return 'out';
    }
    if ((float) $quantity <= (float) $low_level) {
        return 'low';
    }
    return 'ok';
}

$errors = [];
$form = ['name' => '', 'unit' => 'kg', 'quantity' => '', 'low_stock_level' => ''];

$edit_errors = [];
$edit_form = ['id' => 0, 'name' => '', 'unit' => 'kg', 'quantity' => '', 'low_stock_level' => ''];

// Where to go after saving: the same page, keeping any active filters
$back = '/admin/inventory';
if ($_SERVER['QUERY_STRING'] !== '') {
    $back .= '?' . $_SERVER['QUERY_STRING'];
}

$action = $_POST['action'] ?? '';

// ---- Add ingredient ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add_ingredient') {
    $form['name'] = trim($_POST['name'] ?? '');
    $form['unit'] = $_POST['unit'] ?? '';
    $form['quantity'] = trim($_POST['quantity'] ?? '');
    $form['low_stock_level'] = trim($_POST['low_stock_level'] ?? '');

    if ($form['name'] === '') {
        $errors[] = 'Ingredient name is required.';
    } elseif (mb_strlen($form['name']) > 100) {
        $errors[] = 'Ingredient name can be at most 100 characters.';
    }
    if (!in_array($form['unit'], $units, true)) {
        $errors[] = 'Choose a unit.';
    }
    if (!preg_match($decimal, $form['quantity'])) {
        $errors[] = 'Enter the opening stock as a number, like 25 or 2.5.';
    }
    if (!preg_match($decimal, $form['low_stock_level'])) {
        $errors[] = 'Enter the low-stock level as a number, like 5 or 0.5.';
    }

    if (!$errors) {
        $check = $pdo->prepare('SELECT id FROM ingredients WHERE name = ?');
        $check->execute([$form['name']]);
        if ($check->fetch()) {
            $errors[] = 'That ingredient already exists.';
        }
    }

    if (!$errors) {
        $stmt = $pdo->prepare(
            'INSERT INTO ingredients (name, unit, quantity, low_stock_level) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $form['name'],
            $form['unit'],
            number_format((float) $form['quantity'], 2, '.', ''),
            number_format((float) $form['low_stock_level'], 2, '.', ''),
        ]);
        set_flash('success', 'Ingredient added.');
        redirect($back);
    }
}

// ---- Edit ingredient (set exact values) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'edit_ingredient') {
    $edit_form['id'] = (int) ($_POST['id'] ?? 0);
    $edit_form['name'] = trim($_POST['name'] ?? '');
    $edit_form['unit'] = $_POST['unit'] ?? '';
    $edit_form['quantity'] = trim($_POST['quantity'] ?? '');
    $edit_form['low_stock_level'] = trim($_POST['low_stock_level'] ?? '');

    if ($edit_form['name'] === '') {
        $edit_errors[] = 'Ingredient name is required.';
    } elseif (mb_strlen($edit_form['name']) > 100) {
        $edit_errors[] = 'Ingredient name can be at most 100 characters.';
    }
    if (!in_array($edit_form['unit'], $units, true)) {
        $edit_errors[] = 'Choose a unit.';
    }
    if (!preg_match($decimal, $edit_form['quantity'])) {
        $edit_errors[] = 'Enter the stock as a number, like 25 or 2.5.';
    }
    if (!preg_match($decimal, $edit_form['low_stock_level'])) {
        $edit_errors[] = 'Enter the low-stock level as a number, like 5 or 0.5.';
    }

    if (!$edit_errors) {
        $exists = $pdo->prepare('SELECT id FROM ingredients WHERE id = ?');
        $exists->execute([$edit_form['id']]);
        if (!$exists->fetch()) {
            $edit_errors[] = 'That ingredient no longer exists.';
        }
    }

    if (!$edit_errors) {
        $check = $pdo->prepare('SELECT id FROM ingredients WHERE name = ? AND id != ?');
        $check->execute([$edit_form['name'], $edit_form['id']]);
        if ($check->fetch()) {
            $edit_errors[] = 'Another ingredient already has that name.';
        }
    }

    if (!$edit_errors) {
        $stmt = $pdo->prepare(
            'UPDATE ingredients SET name = ?, unit = ?, quantity = ?, low_stock_level = ? WHERE id = ?'
        );
        $stmt->execute([
            $edit_form['name'],
            $edit_form['unit'],
            number_format((float) $edit_form['quantity'], 2, '.', ''),
            number_format((float) $edit_form['low_stock_level'], 2, '.', ''),
            $edit_form['id'],
        ]);
        set_flash('success', 'Ingredient updated.');
        redirect($back);
    }
}

// ---- Restock (add an amount to the current stock) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'restock') {
    $restock_id = (int) ($_POST['id'] ?? 0);
    $amount = trim($_POST['amount'] ?? '');

    if (!preg_match($decimal, $amount) || (float) $amount <= 0) {
        set_flash('error', 'Enter an amount greater than 0, like 10 or 2.5.');
    } else {
        // The database does the addition itself; the cap keeps it inside the column's range
        $stmt = $pdo->prepare(
            'UPDATE ingredients SET quantity = quantity + ?
             WHERE id = ? AND quantity + ? <= 99999999.99'
        );
        $amount = number_format((float) $amount, 2, '.', '');
        $stmt->execute([$amount, $restock_id, $amount]);

        if ($stmt->rowCount() > 0) {
            set_flash('success', 'Stock updated.');
        } else {
            set_flash('error', 'Could not add that stock. Check the amount.');
        }
    }
    redirect($back);
}

// ---- Delete ingredient ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete_ingredient') {
    $delete_id = (int) ($_POST['id'] ?? 0);

    $stmt = $pdo->prepare('DELETE FROM ingredients WHERE id = ?');
    $stmt->execute([$delete_id]);

    if ($stmt->rowCount() > 0) {
        set_flash('success', 'Ingredient deleted.');
    } else {
        set_flash('error', 'That ingredient could not be deleted.');
    }
    redirect($back);
}

// ---- Filters (read from the address bar) ----
$search = trim($_GET['search'] ?? '');
$filter_stock = $_GET['stock'] ?? '';

$where = [];
$params = [];

if ($search !== '') {
    $where[] = 'name LIKE ?';
    $params[] = '%' . $search . '%';
}
if ($filter_stock === 'ok') {
    $where[] = 'quantity > low_stock_level';
} elseif ($filter_stock === 'low') {
    $where[] = 'quantity > 0 AND quantity <= low_stock_level';
} elseif ($filter_stock === 'out') {
    $where[] = 'quantity = 0';
}

$sql = 'SELECT id, name, unit, quantity, low_stock_level FROM ingredients';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY name';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$ingredients = $stmt->fetchAll();

$is_filtered = $search !== '' || in_array($filter_stock, ['ok', 'low', 'out'], true);

// How many need attention (shown as a reminder above the table)
$attention = (int) $pdo->query('SELECT COUNT(*) FROM ingredients WHERE quantity <= low_stock_level')->fetchColumn();

$page_title = 'Inventory';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="toolbar">
    <form method="get" class="filters">
        <input type="text" name="search" placeholder="Search ingredients" value="<?= e($search) ?>">

        <select name="stock">
            <option value="">All stock levels</option>
            <option value="ok" <?= $filter_stock === 'ok' ? 'selected' : '' ?>>In stock</option>
            <option value="low" <?= $filter_stock === 'low' ? 'selected' : '' ?>>Low stock</option>
            <option value="out" <?= $filter_stock === 'out' ? 'selected' : '' ?>>Out of stock</option>
        </select>

        <button type="submit" class="btn">Filter</button>
        <?php if ($is_filtered): ?>
            <a class="btn btn-light" href="<?= BASE_URL ?>/admin/inventory">Reset</a>
        <?php endif; ?>
    </form>

    <button type="button" class="btn" data-open-dialog="addDialog">+ Add ingredient</button>
</div>

<p class="result-count">
    Showing <?= count($ingredients) ?> ingredients<?php if ($attention > 0): ?>
        &middot; <strong class="attention"><?= $attention ?> need restocking</strong><?php endif; ?>
</p>

<div class="table-wrap">
    <table class="table">
        <thead>
            <tr>
                <th>Ingredient</th>
                <th>In stock</th>
                <th>Low-stock level</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($ingredients) === 0): ?>
                <tr>
                    <td colspan="5" class="table-empty">
                        <?= $is_filtered ? 'No ingredients match these filters.' : 'No ingredients yet.' ?>
                    </td>
                </tr>
            <?php endif; ?>

            <?php foreach ($ingredients as $ingredient): ?>
                <?php $status = stock_status($ingredient['quantity'], $ingredient['low_stock_level']); ?>
                <tr>
                    <td><?= e($ingredient['name']) ?></td>
                    <td><?= e(format_qty($ingredient['quantity'])) ?> <?= e($ingredient['unit']) ?></td>
                    <td><?= e(format_qty($ingredient['low_stock_level'])) ?> <?= e($ingredient['unit']) ?></td>
                    <td>
                        <?php if ($status === 'ok'): ?>
                            <span class="badge badge-active">In stock</span>
                        <?php elseif ($status === 'low'): ?>
                            <span class="badge badge-low">Low stock</span>
                        <?php else: ?>
                            <span class="badge badge-inactive">Out of stock</span>
                        <?php endif; ?>
                    </td>
                    <td class="actions">
                        <button type="button" class="btn btn-small" data-restock
                            data-id="<?= (int) $ingredient['id'] ?>"
                            data-name="<?= e($ingredient['name']) ?>"
                            data-unit="<?= e($ingredient['unit']) ?>">
                            Restock
                        </button>
                        <button type="button" class="btn btn-small btn-light" data-edit
                            data-id="<?= (int) $ingredient['id'] ?>"
                            data-name="<?= e($ingredient['name']) ?>"
                            data-unit="<?= e($ingredient['unit']) ?>"
                            data-quantity="<?= e(format_qty($ingredient['quantity'])) ?>"
                            data-low_stock_level="<?= e(format_qty($ingredient['low_stock_level'])) ?>">
                            Edit
                        </button>
                        <button type="button" class="btn btn-small btn-danger" data-delete
                            data-id="<?= (int) $ingredient['id'] ?>"
                            data-name="<?= e($ingredient['name']) ?>">
                            Delete
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Add ingredient popup -->
<dialog id="addDialog" class="dialog dialog-wide" <?= $errors ? 'data-auto-open' : '' ?>>
    <div class="dialog-body">
        <div class="dialog-head">
            <h2>Add ingredient</h2>
            <button type="button" class="dialog-close" data-close-dialog aria-label="Close">&times;</button>
        </div>

        <?php if ($errors): ?>
            <div class="alert alert-error">
                <ul class="error-list">
                    <?php foreach ($errors as $error): ?>
                        <li><?= e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="action" value="add_ingredient">

            <div class="dialog-grid">
                <div>
                    <label for="add_name">Ingredient name</label>
                    <input type="text" id="add_name" name="name" maxlength="100" value="<?= e($form['name']) ?>" required>
                </div>

                <div>
                    <label for="add_unit">Unit</label>
                    <select id="add_unit" name="unit">
                        <?php foreach ($units as $unit): ?>
                            <option value="<?= e($unit) ?>" <?= $form['unit'] === $unit ? 'selected' : '' ?>><?= e($unit) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="add_quantity">Opening stock</label>
                    <input type="text" id="add_quantity" name="quantity" inputmode="decimal" value="<?= e($form['quantity']) ?>" required>
                </div>

                <div>
                    <label for="add_low">Low-stock alert at</label>
                    <input type="text" id="add_low" name="low_stock_level" inputmode="decimal" value="<?= e($form['low_stock_level']) ?>" required>
                </div>
            </div>

            <div class="dialog-actions">
                <button type="button" class="btn btn-light" data-close-dialog>Cancel</button>
                <button type="submit" class="btn">Add ingredient</button>
            </div>
        </form>
    </div>
</dialog>

<!-- Edit ingredient popup (one dialog, filled by JavaScript for whichever row was clicked) -->
<dialog id="editDialog" class="dialog dialog-wide" <?= $edit_errors ? 'data-auto-open' : '' ?>>
    <div class="dialog-body">
        <div class="dialog-head">
            <h2>Edit ingredient</h2>
            <button type="button" class="dialog-close" data-close-dialog aria-label="Close">&times;</button>
        </div>

        <?php if ($edit_errors): ?>
            <div class="alert alert-error">
                <ul class="error-list">
                    <?php foreach ($edit_errors as $error): ?>
                        <li><?= e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="action" value="edit_ingredient">
            <input type="hidden" name="id" value="<?= (int) $edit_form['id'] ?>">

            <div class="dialog-grid">
                <div>
                    <label for="edit_name">Ingredient name</label>
                    <input type="text" id="edit_name" name="name" maxlength="100" value="<?= e($edit_form['name']) ?>" required>
                </div>

                <div>
                    <label for="edit_unit">Unit</label>
                    <select id="edit_unit" name="unit">
                        <?php foreach ($units as $unit): ?>
                            <option value="<?= e($unit) ?>" <?= $edit_form['unit'] === $unit ? 'selected' : '' ?>><?= e($unit) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="edit_quantity">In stock</label>
                    <input type="text" id="edit_quantity" name="quantity" inputmode="decimal" value="<?= e($edit_form['quantity']) ?>" required>
                </div>

                <div>
                    <label for="edit_low">Low-stock alert at</label>
                    <input type="text" id="edit_low" name="low_stock_level" inputmode="decimal" value="<?= e($edit_form['low_stock_level']) ?>" required>
                </div>
            </div>

            <div class="dialog-actions">
                <button type="button" class="btn btn-light" data-close-dialog>Cancel</button>
                <button type="submit" class="btn">Save changes</button>
            </div>
        </form>
    </div>
</dialog>

<!-- Restock popup -->
<dialog id="restockDialog" class="dialog">
    <div class="dialog-body">
        <div class="dialog-head">
            <h2>Restock</h2>
            <button type="button" class="dialog-close" data-close-dialog aria-label="Close">&times;</button>
        </div>

        <p class="confirm-text">Add stock to <strong id="restockName"></strong>.</p>

        <form method="post">
            <input type="hidden" name="action" value="restock">
            <input type="hidden" name="id" value="">

            <label for="restock_amount">Amount received (<span id="restockUnit"></span>)</label>
            <input type="text" id="restock_amount" name="amount" inputmode="decimal" required>

            <div class="dialog-actions">
                <button type="button" class="btn btn-light" data-close-dialog>Cancel</button>
                <button type="submit" class="btn">Add stock</button>
            </div>
        </form>
    </div>
</dialog>

<!-- Delete confirmation popup -->
<dialog id="deleteDialog" class="dialog">
    <div class="dialog-body">
        <div class="dialog-head">
            <h2>Delete ingredient</h2>
            <button type="button" class="dialog-close" data-close-dialog aria-label="Close">&times;</button>
        </div>

        <p class="confirm-text">
            Delete <strong id="deleteName"></strong>? This cannot be undone.
        </p>

        <form method="post">
            <input type="hidden" name="action" value="delete_ingredient">
            <input type="hidden" name="id" value="">

            <div class="dialog-actions">
                <button type="button" class="btn btn-light" data-close-dialog>Cancel</button>
                <button type="submit" class="btn btn-danger">Delete</button>
            </div>
        </form>
    </div>
</dialog>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>