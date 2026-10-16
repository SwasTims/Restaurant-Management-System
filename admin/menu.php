<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_role(['admin']);

$upload_dir = __DIR__ . '/../assets/uploads/menu';
$upload_url = BASE_URL . '/assets/uploads/menu';
$max_image_bytes = 2 * 1024 * 1024; // 2 MB
$allowed_images = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];

$categories = $pdo->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();
$category_ids = array_map('intval', array_column($categories, 'id'));

$cat_errors = [];
$cat_form = ['name' => ''];

$item_errors = [];
$item_form = ['name' => '', 'category_id' => 0, 'price' => '', 'is_available' => '1'];

// Where to go after saving: the same page, keeping any active filters
$back = '/admin/menu';
if ($_SERVER['QUERY_STRING'] !== '') {
    $back .= '?' . $_SERVER['QUERY_STRING'];
}

$action = $_POST['action'] ?? '';

// ---- Add category ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add_category') {
    $cat_form['name'] = trim($_POST['name'] ?? '');

    if ($cat_form['name'] === '') {
        $cat_errors[] = 'Category name is required.';
    } elseif (mb_strlen($cat_form['name']) > 100) {
        $cat_errors[] = 'Category name can be at most 100 characters.';
    }

    if (!$cat_errors) {
        $check = $pdo->prepare('SELECT id FROM categories WHERE name = ?');
        $check->execute([$cat_form['name']]);
        if ($check->fetch()) {
            $cat_errors[] = 'A category with that name already exists.';
        }
    }

    if (!$cat_errors) {
        $stmt = $pdo->prepare('INSERT INTO categories (name) VALUES (?)');
        $stmt->execute([$cat_form['name']]);
        set_flash('success', 'Category added.');
        redirect($back);
    }
}

// ---- Add menu item ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add_item') {
    $item_form['name'] = trim($_POST['name'] ?? '');
    $item_form['category_id'] = (int) ($_POST['category_id'] ?? 0);
    $item_form['price'] = trim($_POST['price'] ?? '');
    $item_form['is_available'] = ($_POST['is_available'] ?? '') === '1' ? '1' : '0';

    if ($item_form['name'] === '') {
        $item_errors[] = 'Item name is required.';
    } elseif (mb_strlen($item_form['name']) > 150) {
        $item_errors[] = 'Item name can be at most 150 characters.';
    }
    if (!in_array($item_form['category_id'], $category_ids, true)) {
        $item_errors[] = 'Choose a category.';
    }
    if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $item_form['price']) || (float) $item_form['price'] <= 0) {
        $item_errors[] = 'Enter a valid price, like 180 or 180.50.';
    }

    // Image (optional): check it, but do not save it yet
    $image_ext = null;
    $file = $_FILES['image'] ?? null;

    if ($file && $file['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            $item_errors[] = 'Image must be 2 MB or smaller.';
        } elseif ($file['error'] !== UPLOAD_ERR_OK) {
            $item_errors[] = 'The image could not be uploaded. Try again.';
        } elseif ($file['size'] > $max_image_bytes) {
            $item_errors[] = 'Image must be 2 MB or smaller.';
        } else {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']);
            if (isset($allowed_images[$mime])) {
                $image_ext = $allowed_images[$mime];
            } else {
                $item_errors[] = 'Image must be a JPG, PNG or WebP file.';
            }
        }
    }

    if (!$item_errors) {
        $check = $pdo->prepare('SELECT id FROM menu_items WHERE category_id = ? AND name = ?');
        $check->execute([$item_form['category_id'], $item_form['name']]);
        if ($check->fetch()) {
            $item_errors[] = 'That item already exists in this category.';
        }
    }

    // Everything is valid: now save the image file, then the row
    $image_name = null;
    if (!$item_errors && $image_ext !== null) {
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        $image_name = bin2hex(random_bytes(16)) . '.' . $image_ext;
        if (!move_uploaded_file($file['tmp_name'], $upload_dir . '/' . $image_name)) {
            $item_errors[] = 'The image could not be saved. Check the uploads folder.';
            $image_name = null;
        }
    }

    if (!$item_errors) {
        $stmt = $pdo->prepare(
            'INSERT INTO menu_items (category_id, name, price, image, is_available) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $item_form['category_id'],
            $item_form['name'],
            number_format((float) $item_form['price'], 2, '.', ''),
            $image_name,
            $item_form['is_available'],
        ]);
        set_flash('success', 'Menu item added.');
        redirect($back);
    }
}

// ---- Change availability (out of stock / back in stock) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'set_availability') {
    $item_id = (int) ($_POST['id'] ?? 0);
    $available = ($_POST['available'] ?? '') === '1' ? 1 : 0;

    $stmt = $pdo->prepare('UPDATE menu_items SET is_available = ? WHERE id = ?');
    $stmt->execute([$available, $item_id]);

    set_flash('success', $available ? 'Item is back in stock.' : 'Item marked out of stock.');
    redirect($back);
}

// ---- Filters (read from the address bar) ----
$search = trim($_GET['search'] ?? '');
$filter_category = (int) ($_GET['category'] ?? 0);
$filter_avail = $_GET['availability'] ?? '';

$where = [];
$params = [];

if ($search !== '') {
    $where[] = 'm.name LIKE ?';
    $params[] = '%' . $search . '%';
}
if (in_array($filter_category, $category_ids, true)) {
    $where[] = 'm.category_id = ?';
    $params[] = $filter_category;
}
if ($filter_avail === 'available') {
    $where[] = 'm.is_available = 1';
} elseif ($filter_avail === 'unavailable') {
    $where[] = 'm.is_available = 0';
}

$sql = 'SELECT m.id, m.name, m.price, m.image, m.is_available, c.name AS category_name
        FROM menu_items m
        JOIN categories c ON c.id = m.category_id';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY c.name, m.name';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll();

$is_filtered = $search !== ''
    || in_array($filter_category, $category_ids, true)
    || in_array($filter_avail, ['available', 'unavailable'], true);

$page_title = 'Menu items';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="toolbar">
    <form method="get" class="filters">
        <input type="text" name="search" placeholder="Search items" value="<?= e($search) ?>">

        <select name="category">
            <option value="0">All categories</option>
            <?php foreach ($categories as $category): ?>
                <option value="<?= (int) $category['id'] ?>" <?= $filter_category === (int) $category['id'] ? 'selected' : '' ?>>
                    <?= e($category['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select name="availability">
            <option value="">All availability</option>
            <option value="available" <?= $filter_avail === 'available' ? 'selected' : '' ?>>Available</option>
            <option value="unavailable" <?= $filter_avail === 'unavailable' ? 'selected' : '' ?>>Out of stock</option>
        </select>

        <button type="submit" class="btn">Filter</button>
        <?php if ($is_filtered): ?>
            <a class="btn btn-light" href="<?= BASE_URL ?>/admin/menu">Reset</a>
        <?php endif; ?>
    </form>

    <div class="toolbar-actions">
        <button type="button" class="btn btn-light" data-open-dialog="categoryDialog">+ Add category</button>
        <button type="button" class="btn" data-open-dialog="itemDialog">+ Add item</button>
    </div>
</div>

<p class="result-count">Showing <?= count($items) ?> items</p>

<div class="table-wrap">
    <table class="table">
        <thead>
            <tr>
                <th>Photo</th>
                <th>Item</th>
                <th>Category</th>
                <th>Price</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($items) === 0): ?>
                <tr>
                    <td colspan="5" class="table-empty">
                        <?= $is_filtered ? 'No items match these filters.' : 'No menu items yet.' ?>
                    </td>
                </tr>
            <?php endif; ?>

            <?php foreach ($items as $item): ?>
                <tr>
                    <td>
                        <?php if ($item['image']): ?>
                            <img class="thumb" src="<?= e($upload_url . '/' . $item['image']) ?>" alt="<?= e($item['name']) ?>">
                        <?php else: ?>
                            <div class="thumb thumb-empty"><?= e(mb_strtoupper(mb_substr($item['name'], 0, 1))) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?= e($item['name']) ?></td>
                    <td><?= e($item['category_name']) ?></td>
                    <td>Rs. <?= e(number_format((float) $item['price'], 2)) ?></td>
                    <td>
                        <div class="status-cell">
                            <?php if ($item['is_available']): ?>
                                <span class="badge badge-active">Available</span>
                            <?php else: ?>
                                <span class="badge badge-inactive">Out of stock</span>
                            <?php endif; ?>

                            <form method="post">
                                <input type="hidden" name="action" value="set_availability">
                                <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                                <?php if ($item['is_available']): ?>
                                    <input type="hidden" name="available" value="0">
                                    <button type="submit" class="btn btn-small btn-light">Out of stock</button>
                                <?php else: ?>
                                    <input type="hidden" name="available" value="1">
                                    <button type="submit" class="btn btn-small btn-light">Back in stock</button>
                                <?php endif; ?>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Add category popup -->
<dialog id="categoryDialog" class="dialog" <?= $cat_errors ? 'data-auto-open' : '' ?>>
    <div class="dialog-body">
        <div class="dialog-head">
            <h2>Add category</h2>
            <button type="button" class="dialog-close" data-close-dialog aria-label="Close">&times;</button>
        </div>

        <?php if ($cat_errors): ?>
            <div class="alert alert-error">
                <ul class="error-list">
                    <?php foreach ($cat_errors as $error): ?>
                        <li><?= e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="action" value="add_category">

            <label for="cat_name">Category name</label>
            <input type="text" id="cat_name" name="name" maxlength="100" value="<?= e($cat_form['name']) ?>" required>

            <div class="dialog-actions">
                <button type="button" class="btn btn-light" data-close-dialog>Cancel</button>
                <button type="submit" class="btn">Add category</button>
            </div>
        </form>
    </div>
</dialog>

<!-- Add item popup -->
<dialog id="itemDialog" class="dialog dialog-wide" <?= $item_errors ? 'data-auto-open' : '' ?>>
    <div class="dialog-body">
        <div class="dialog-head">
            <h2>Add menu item</h2>
            <button type="button" class="dialog-close" data-close-dialog aria-label="Close">&times;</button>
        </div>

        <?php if ($item_errors): ?>
            <div class="alert alert-error">
                <ul class="error-list">
                    <?php foreach ($item_errors as $error): ?>
                        <li><?= e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (count($categories) === 0): ?>
            <p class="confirm-text">Add a category first, then you can add items to it.</p>
            <div class="dialog-actions">
                <button type="button" class="btn btn-light" data-close-dialog>Close</button>
            </div>
        <?php else: ?>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="add_item">

                <div class="dialog-grid">
                    <div class="field-full">
                        <label for="item_name">Item name</label>
                        <input type="text" id="item_name" name="name" maxlength="150" value="<?= e($item_form['name']) ?>" required>
                    </div>

                    <div>
                        <label for="item_category">Category</label>
                        <select id="item_category" name="category_id">
                            <option value="0">Choose...</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= (int) $category['id'] ?>" <?= $item_form['category_id'] === (int) $category['id'] ? 'selected' : '' ?>>
                                    <?= e($category['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="item_price">Price (Rs.)</label>
                        <input type="text" id="item_price" name="price" inputmode="decimal" value="<?= e($item_form['price']) ?>" required>
                    </div>

                    <div class="field-full">
                        <label for="item_image">Photo (optional, JPG, PNG or WebP, up to 2 MB)</label>
                        <input type="file" id="item_image" name="image"
                               accept="image/jpeg,image/png,image/webp"
                               data-preview="itemPreview">
                        <div class="preview-box">
                            <img id="itemPreview" alt="Photo preview" hidden>
                            <span class="preview-empty">No photo chosen</span>
                        </div>
                    </div>

                    <div class="field-full">
                        <label class="check">
                            <input type="checkbox" name="is_available" value="1" <?= $item_form['is_available'] === '1' ? 'checked' : '' ?>>
                            Available for ordering
                        </label>
                    </div>
                </div>

                <div class="dialog-actions">
                    <button type="button" class="btn btn-light" data-close-dialog>Cancel</button>
                    <button type="submit" class="btn">Add item</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</dialog>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>