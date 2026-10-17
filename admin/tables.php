<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_role(['admin']);

$statuses = ['free', 'occupied', 'reserved'];

// Quick buttons shown for each current status: [new status, button label]
$quick_actions = [
    'free'     => [['occupied', 'Occupy'],      ['reserved', 'Reserve']],
    'reserved' => [['occupied', 'Seat guests'], ['free', 'Free up']],
    'occupied' => [['free', 'Free up']],
];

$errors = [];
$form = ['name' => '', 'capacity' => '', 'status' => 'free'];

$edit_errors = [];
$edit_form = ['id' => 0, 'name' => '', 'capacity' => '', 'status' => 'free'];

// Where to go after saving: the same page, keeping any active filters
$back = '/admin/tables';
if ($_SERVER['QUERY_STRING'] !== '') {
    $back .= '?' . $_SERVER['QUERY_STRING'];
}

$action = $_POST['action'] ?? '';

// ---- Add table ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add_table') {
    $form['name'] = trim($_POST['name'] ?? '');
    $form['capacity'] = trim($_POST['capacity'] ?? '');
    $form['status'] = $_POST['status'] ?? '';

    if ($form['name'] === '') {
        $errors[] = 'Table name is required.';
    } elseif (mb_strlen($form['name']) > 30) {
        $errors[] = 'Table name can be at most 30 characters.';
    }
    if (!preg_match('/^\d{1,2}$/', $form['capacity']) || (int) $form['capacity'] < 1 || (int) $form['capacity'] > 50) {
        $errors[] = 'Seats must be a whole number from 1 to 50.';
    }
    if (!in_array($form['status'], $statuses, true)) {
        $errors[] = 'Choose a valid status.';
    }

    if (!$errors) {
        $check = $pdo->prepare('SELECT id FROM restaurant_tables WHERE name = ?');
        $check->execute([$form['name']]);
        if ($check->fetch()) {
            $errors[] = 'A table with that name already exists.';
        }
    }

    if (!$errors) {
        $stmt = $pdo->prepare('INSERT INTO restaurant_tables (name, capacity, status) VALUES (?, ?, ?)');
        $stmt->execute([$form['name'], (int) $form['capacity'], $form['status']]);
        set_flash('success', 'Table added.');
        redirect($back);
    }
}

// ---- Edit table ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'edit_table') {
    $edit_form['id'] = (int) ($_POST['id'] ?? 0);
    $edit_form['name'] = trim($_POST['name'] ?? '');
    $edit_form['capacity'] = trim($_POST['capacity'] ?? '');
    $edit_form['status'] = $_POST['status'] ?? '';

    if ($edit_form['name'] === '') {
        $edit_errors[] = 'Table name is required.';
    } elseif (mb_strlen($edit_form['name']) > 30) {
        $edit_errors[] = 'Table name can be at most 30 characters.';
    }
    if (!preg_match('/^\d{1,2}$/', $edit_form['capacity']) || (int) $edit_form['capacity'] < 1 || (int) $edit_form['capacity'] > 50) {
        $edit_errors[] = 'Seats must be a whole number from 1 to 50.';
    }
    if (!in_array($edit_form['status'], $statuses, true)) {
        $edit_errors[] = 'Choose a valid status.';
    }

    if (!$edit_errors) {
        $exists = $pdo->prepare('SELECT id FROM restaurant_tables WHERE id = ?');
        $exists->execute([$edit_form['id']]);
        if (!$exists->fetch()) {
            $edit_errors[] = 'That table no longer exists.';
        }
    }

    if (!$edit_errors) {
        $check = $pdo->prepare('SELECT id FROM restaurant_tables WHERE name = ? AND id != ?');
        $check->execute([$edit_form['name'], $edit_form['id']]);
        if ($check->fetch()) {
            $edit_errors[] = 'Another table already has that name.';
        }
    }

    if (!$edit_errors) {
        $stmt = $pdo->prepare('UPDATE restaurant_tables SET name = ?, capacity = ?, status = ? WHERE id = ?');
        $stmt->execute([
            $edit_form['name'],
            (int) $edit_form['capacity'],
            $edit_form['status'],
            $edit_form['id'],
        ]);
        set_flash('success', 'Table updated.');
        redirect($back);
    }
}

// ---- Delete table ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete_table') {
    $delete_id = (int) ($_POST['id'] ?? 0);

    $stmt = $pdo->prepare('DELETE FROM restaurant_tables WHERE id = ?');
    $stmt->execute([$delete_id]);

    if ($stmt->rowCount() > 0) {
        set_flash('success', 'Table deleted.');
    } else {
        set_flash('error', 'That table could not be deleted.');
    }
    redirect($back);
}

// ---- Change status quickly ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'set_status') {
    $table_id = (int) ($_POST['id'] ?? 0);
    $new_status = $_POST['status'] ?? '';

    if (!in_array($new_status, $statuses, true)) {
        set_flash('error', 'Choose a valid status.');
    } else {
        $stmt = $pdo->prepare('UPDATE restaurant_tables SET status = ? WHERE id = ?');
        $stmt->execute([$new_status, $table_id]);
        set_flash('success', 'Table marked as ' . $new_status . '.');
    }
    redirect($back);
}

// ---- Filters (read from the address bar) ----
$search = trim($_GET['search'] ?? '');
$filter_status = $_GET['status'] ?? '';

$where = [];
$params = [];

if ($search !== '') {
    $where[] = 'name LIKE ?';
    $params[] = '%' . $search . '%';
}
if (in_array($filter_status, $statuses, true)) {
    $where[] = 'status = ?';
    $params[] = $filter_status;
}

$sql = 'SELECT id, name, capacity, status FROM restaurant_tables';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
// Natural order, so T2 comes before T10
$sql .= ' ORDER BY LENGTH(name), name';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tables = $stmt->fetchAll();

$is_filtered = $search !== '' || in_array($filter_status, $statuses, true);

$page_title = 'Tables';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="toolbar">
    <form method="get" class="filters">
        <input type="text" name="search" placeholder="Search tables" value="<?= e($search) ?>">

        <select name="status">
            <option value="">All statuses</option>
            <?php foreach ($statuses as $status): ?>
                <option value="<?= e($status) ?>" <?= $filter_status === $status ? 'selected' : '' ?>>
                    <?= e(ucfirst($status)) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <button type="submit" class="btn">Filter</button>
        <?php if ($is_filtered): ?>
            <a class="btn btn-light" href="<?= BASE_URL ?>/admin/tables">Reset</a>
        <?php endif; ?>
    </form>

    <button type="button" class="btn" data-open-dialog="addDialog">+ Add table</button>
</div>

<p class="result-count">Showing <?= count($tables) ?> tables</p>

<div class="table-wrap">
    <table class="table">
        <thead>
            <tr>
                <th>Table</th>
                <th>Seats</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($tables) === 0): ?>
                <tr>
                    <td colspan="4" class="table-empty">
                        <?= $is_filtered ? 'No tables match these filters.' : 'No tables yet.' ?>
                    </td>
                </tr>
            <?php endif; ?>

            <?php foreach ($tables as $table): ?>
                <tr>
                    <td><?= e($table['name']) ?></td>
                    <td><?= (int) $table['capacity'] ?></td>
                    <td>
                        <div class="status-cell">
                            <?php if ($table['status'] === 'free'): ?>
                                <span class="badge badge-active">Free</span>
                            <?php elseif ($table['status'] === 'reserved'): ?>
                                <span class="badge badge-low">Reserved</span>
                            <?php else: ?>
                                <span class="badge badge-inactive">Occupied</span>
                            <?php endif; ?>

                            <?php foreach ($quick_actions[$table['status']] as $quick): ?>
                                <form method="post">
                                    <input type="hidden" name="action" value="set_status">
                                    <input type="hidden" name="id" value="<?= (int) $table['id'] ?>">
                                    <input type="hidden" name="status" value="<?= e($quick[0]) ?>">
                                    <button type="submit" class="btn btn-small btn-light"><?= e($quick[1]) ?></button>
                                </form>
                            <?php endforeach; ?>
                        </div>
                    </td>
                    <td class="actions">
                        <button type="button" class="btn btn-small btn-light" data-edit
                            data-id="<?= (int) $table['id'] ?>"
                            data-name="<?= e($table['name']) ?>"
                            data-capacity="<?= (int) $table['capacity'] ?>"
                            data-status="<?= e($table['status']) ?>">
                            Edit
                        </button>
                        <button type="button" class="btn btn-small btn-danger" data-delete
                            data-id="<?= (int) $table['id'] ?>"
                            data-name="<?= e($table['name']) ?>">
                            Delete
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Add table popup -->
<dialog id="addDialog" class="dialog" <?= $errors ? 'data-auto-open' : '' ?>>
    <div class="dialog-body">
        <div class="dialog-head">
            <h2>Add table</h2>
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
            <input type="hidden" name="action" value="add_table">

            <label for="add_name">Table name</label>
            <input type="text" id="add_name" name="name" maxlength="30" placeholder="T1" value="<?= e($form['name']) ?>" required>

            <label for="add_capacity">Seats</label>
            <input type="number" id="add_capacity" name="capacity" min="1" max="50" value="<?= e($form['capacity']) ?>" required>

            <label for="add_status">Status</label>
            <select id="add_status" name="status">
                <?php foreach ($statuses as $status): ?>
                    <option value="<?= e($status) ?>" <?= $form['status'] === $status ? 'selected' : '' ?>>
                        <?= e(ucfirst($status)) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <div class="dialog-actions">
                <button type="button" class="btn btn-light" data-close-dialog>Cancel</button>
                <button type="submit" class="btn">Add table</button>
            </div>
        </form>
    </div>
</dialog>

<!-- Edit table popup (one dialog, filled by JavaScript for whichever row was clicked) -->
<dialog id="editDialog" class="dialog" <?= $edit_errors ? 'data-auto-open' : '' ?>>
    <div class="dialog-body">
        <div class="dialog-head">
            <h2>Edit table</h2>
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
            <input type="hidden" name="action" value="edit_table">
            <input type="hidden" name="id" value="<?= (int) $edit_form['id'] ?>">

            <label for="edit_name">Table name</label>
            <input type="text" id="edit_name" name="name" maxlength="30" value="<?= e($edit_form['name']) ?>" required>

            <label for="edit_capacity">Seats</label>
            <input type="number" id="edit_capacity" name="capacity" min="1" max="50" value="<?= e($edit_form['capacity']) ?>" required>

            <label for="edit_status">Status</label>
            <select id="edit_status" name="status">
                <?php foreach ($statuses as $status): ?>
                    <option value="<?= e($status) ?>" <?= $edit_form['status'] === $status ? 'selected' : '' ?>>
                        <?= e(ucfirst($status)) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <div class="dialog-actions">
                <button type="button" class="btn btn-light" data-close-dialog>Cancel</button>
                <button type="submit" class="btn">Save changes</button>
            </div>
        </form>
    </div>
</dialog>

<!-- Delete confirmation popup -->
<dialog id="deleteDialog" class="dialog">
    <div class="dialog-body">
        <div class="dialog-head">
            <h2>Delete table</h2>
            <button type="button" class="dialog-close" data-close-dialog aria-label="Close">&times;</button>
        </div>

        <p class="confirm-text">
            Delete <strong id="deleteName"></strong>? This cannot be undone.
        </p>

        <form method="post">
            <input type="hidden" name="action" value="delete_table">
            <input type="hidden" name="id" value="">

            <div class="dialog-actions">
                <button type="button" class="btn btn-light" data-close-dialog>Cancel</button>
                <button type="submit" class="btn btn-danger">Delete</button>
            </div>
        </form>
    </div>
</dialog>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>