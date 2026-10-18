<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_role(['admin']);

$roles = ['waiter', 'kitchen', 'cashier'];
$statuses = ['active', 'inactive'];

$errors = [];
$form = ['name' => '', 'email' => '', 'role' => 'waiter'];

$edit_errors = [];
$edit_form = ['id' => 0, 'name' => '', 'email' => '', 'role' => 'waiter', 'status' => 'active'];

// Where to go after saving: the same page, keeping any active filters
$back = '/admin/accounts';
if ($_SERVER['QUERY_STRING'] !== '') {
    $back .= '?' . $_SERVER['QUERY_STRING'];
}

$action = $_POST['action'] ?? '';

// ---- Add staff ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add') {
    $form['name'] = trim($_POST['name'] ?? '');
    $form['email'] = trim($_POST['email'] ?? '');
    $form['role'] = $_POST['role'] ?? '';
    $password = $_POST['password'] ?? '';

    if ($form['name'] === '') {
        $errors[] = 'Name is required.';
    }
    if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address.';
    }
    if (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters.';
    }
    if (!in_array($form['role'], $roles, true)) {
        $errors[] = 'Choose a valid role.';
    }

    if (!$errors) {
        $check = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $check->execute([$form['email']]);
        if ($check->fetch()) {
            $errors[] = 'That email is already used by another account.';
        }
    }

    if (!$errors) {
        $stmt = $pdo->prepare('INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)');
        $stmt->execute([
            $form['name'],
            $form['email'],
            password_hash($password, PASSWORD_DEFAULT),
            $form['role'],
        ]);
        set_flash('success', 'Staff account added.');
        redirect($back);
    }
}

// ---- Edit staff ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'edit') {
    $edit_form['id'] = (int) ($_POST['id'] ?? 0);
    $edit_form['name'] = trim($_POST['name'] ?? '');
    $edit_form['email'] = trim($_POST['email'] ?? '');
    $edit_form['role'] = $_POST['role'] ?? '';
    $edit_form['status'] = $_POST['status'] ?? '';
    $new_password = $_POST['password'] ?? '';

    if ($edit_form['name'] === '') {
        $edit_errors[] = 'Name is required.';
    }
    if (!filter_var($edit_form['email'], FILTER_VALIDATE_EMAIL)) {
        $edit_errors[] = 'Enter a valid email address.';
    }
    if (!in_array($edit_form['role'], $roles, true)) {
        $edit_errors[] = 'Choose a valid role.';
    }
    if (!in_array($edit_form['status'], $statuses, true)) {
        $edit_errors[] = 'Choose a valid status.';
    }
    if ($new_password !== '' && strlen($new_password) < 6) {
        $edit_errors[] = 'New password must be at least 6 characters.';
    }

    if (!$edit_errors) {
        $exists = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role != 'admin'");
        $exists->execute([$edit_form['id']]);
        if (!$exists->fetch()) {
            $edit_errors[] = 'That account cannot be edited.';
        }
    }

    if (!$edit_errors) {
        $check = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
        $check->execute([$edit_form['email'], $edit_form['id']]);
        if ($check->fetch()) {
            $edit_errors[] = 'That email is already used by another account.';
        }
    }

    if (!$edit_errors) {
        $sql = 'UPDATE users SET name = ?, email = ?, role = ?, status = ?';
        $params = [
            $edit_form['name'],
            $edit_form['email'],
            $edit_form['role'],
            $edit_form['status'],
        ];

        if ($new_password !== '') {
            $sql .= ', password = ?';
            $params[] = password_hash($new_password, PASSWORD_DEFAULT);
        }

        $sql .= " WHERE id = ? AND role != 'admin'";
        $params[] = $edit_form['id'];

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        set_flash('success', 'Staff account updated.');
        redirect($back);
    }
}
// ---- Delete staff ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete') {
    $delete_id = (int) ($_POST['id'] ?? 0);

    try {
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role != 'admin'");
        $stmt->execute([$delete_id]);

        if ($stmt->rowCount() > 0) {
            set_flash('success', 'Staff account deleted.');
        } else {
            set_flash('error', 'That account cannot be deleted.');
        }
    } catch (PDOException $e) {
        if ((int) $e->errorInfo[1] === 1451) {
            set_flash('error', 'This person has orders, so the account cannot be deleted. Set it to inactive instead.');
        } else {
            throw $e;
        }
    }
    redirect($back);
}

// ---- Filters (read from the address bar) ----
$search = trim($_GET['search'] ?? '');
$filter_role = $_GET['role'] ?? '';
$filter_status = $_GET['status'] ?? '';

$where = ["role != 'admin'"];
$params = [];

if ($search !== '') {
    $where[] = '(name LIKE ? OR email LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
if (in_array($filter_role, $roles, true)) {
    $where[] = 'role = ?';
    $params[] = $filter_role;
}
if (in_array($filter_status, $statuses, true)) {
    $where[] = 'status = ?';
    $params[] = $filter_status;
}

$sql = 'SELECT id, name, email, role, status, created_at FROM users WHERE '
     . implode(' AND ', $where) . ' ORDER BY id';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$accounts = $stmt->fetchAll();

$is_filtered = $search !== ''
    || in_array($filter_role, $roles, true)
    || in_array($filter_status, $statuses, true);

$page_title = 'Staff accounts';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="toolbar">
    <form method="get" class="filters">
        <input type="text" name="search" placeholder="Search name or email" value="<?= e($search) ?>">

        <select name="role">
            <option value="">All roles</option>
            <?php foreach ($roles as $role): ?>
                <option value="<?= e($role) ?>" <?= $filter_role === $role ? 'selected' : '' ?>>
                    <?= e(ucfirst($role)) ?>
                </option>
            <?php endforeach; ?>
        </select>

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
            <a class="btn btn-light" href="<?= BASE_URL ?>/admin/accounts">Reset</a>
        <?php endif; ?>
    </form>

    <button type="button" class="btn" data-open-dialog="addDialog">+ Add staff</button>
</div>

<p class="result-count">Showing <?= count($accounts) ?> staff</p>

<div class="table-wrap">
    <table class="table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Role</th>
                <th>Status</th>
                <th>Created</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($accounts) === 0): ?>
                <tr>
                    <td colspan="6" class="table-empty">
                        <?= $is_filtered ? 'No staff match these filters.' : 'No staff accounts yet.' ?>
                    </td>
                </tr>
            <?php endif; ?>

            <?php foreach ($accounts as $account): ?>
                <tr>
                    <td><?= e($account['name']) ?></td>
                    <td><?= e($account['email']) ?></td>
                    <td><?= e(ucfirst($account['role'])) ?></td>
                    <td>
                        <span class="badge badge-<?= e($account['status']) ?>">
                            <?= e($account['status']) ?>
                        </span>
                    </td>
                    <td><?= e(date('d M Y', strtotime($account['created_at']))) ?></td>
                    <td class="actions">
                        <button type="button" class="btn btn-small btn-light" data-edit
                            data-id="<?= (int) $account['id'] ?>"
                            data-name="<?= e($account['name']) ?>"
                            data-email="<?= e($account['email']) ?>"
                            data-role="<?= e($account['role']) ?>"
                            data-status="<?= e($account['status']) ?>">
                            Edit
                        </button>
                        <button type="button" class="btn btn-small btn-danger" data-delete
                            data-id="<?= (int) $account['id'] ?>"
                            data-name="<?= e($account['name']) ?>">
                            Delete
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Add popup -->
<dialog id="addDialog" class="dialog" <?= $errors ? 'data-auto-open' : '' ?>>
    <div class="dialog-body">
        <div class="dialog-head">
            <h2>Add staff</h2>
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
            <input type="hidden" name="action" value="add">

            <label for="add_name">Name</label>
            <input type="text" id="add_name" name="name" maxlength="100" value="<?= e($form['name']) ?>" required>

            <label for="add_email">Email</label>
            <input type="email" id="add_email" name="email" maxlength="150" value="<?= e($form['email']) ?>" required>

            <label for="add_role">Role</label>
            <select id="add_role" name="role">
                <?php foreach ($roles as $role): ?>
                    <option value="<?= e($role) ?>" <?= $form['role'] === $role ? 'selected' : '' ?>>
                        <?= e(ucfirst($role)) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="add_password">Password</label>
            <input type="password" id="add_password" name="password" required>

            <div class="dialog-actions">
                <button type="button" class="btn btn-light" data-close-dialog>Cancel</button>
                <button type="submit" class="btn">Add staff</button>
            </div>
        </form>
    </div>
</dialog>

<!-- Edit popup (one dialog, filled by JavaScript for whichever row was clicked) -->
<dialog id="editDialog" class="dialog dialog-wide" <?= $edit_errors ? 'data-auto-open' : '' ?>>
    <div class="dialog-body">
        <div class="dialog-head">
            <h2>Edit staff</h2>
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
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" value="<?= (int) $edit_form['id'] ?>">

            <div class="dialog-grid">
                <div>
                    <label for="edit_name">Name</label>
                    <input type="text" id="edit_name" name="name" maxlength="100" value="<?= e($edit_form['name']) ?>" required>
                </div>

                <div>
                    <label for="edit_email">Email</label>
                    <input type="email" id="edit_email" name="email" maxlength="150" value="<?= e($edit_form['email']) ?>" required>
                </div>

                <div>
                    <label for="edit_role">Role</label>
                    <select id="edit_role" name="role">
                        <?php foreach ($roles as $role): ?>
                            <option value="<?= e($role) ?>" <?= $edit_form['role'] === $role ? 'selected' : '' ?>>
                                <?= e(ucfirst($role)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="edit_status">Status</label>
                    <select id="edit_status" name="status">
                        <?php foreach ($statuses as $status): ?>
                            <option value="<?= e($status) ?>" <?= $edit_form['status'] === $status ? 'selected' : '' ?>>
                                <?= e(ucfirst($status)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field-full">
                    <label for="edit_password">New password</label>
                    <input type="password" id="edit_password" name="password" placeholder="Leave blank to keep the current one">
                </div>
            </div>

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
            <h2>Delete staff</h2>
            <button type="button" class="dialog-close" data-close-dialog aria-label="Close">&times;</button>
        </div>

        <p class="confirm-text">
            Delete <strong id="deleteName"></strong>? This cannot be undone.
        </p>

        <form method="post">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="">

            <div class="dialog-actions">
                <button type="button" class="btn btn-light" data-close-dialog>Cancel</button>
                <button type="submit" class="btn btn-danger">Delete</button>
            </div>
        </form>
    </div>
</dialog>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>