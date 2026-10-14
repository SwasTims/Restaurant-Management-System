<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

if (is_logged_in()) {
    redirect(home_for_role(current_user()['role']));
}

$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        set_flash('error', 'Enter your email and password.');
    } else {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? AND status = ?');
        $stmt->execute([$email, 'active']);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            session_regenerate_id(true);
            $_SESSION['user'] = [
                'id'   => $user['id'],
                'name' => $user['name'],
                'role' => $user['role'],
            ];
            set_flash('success', 'Welcome back, ' . $user['name'] . '.');
            redirect(home_for_role($user['role']));
        } else {
            set_flash('error', 'Wrong email or password.');
        }
    }
}

$flash = get_flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Restaurant Management System</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body class="auth-page">

<div class="auth-card">
    <div class="auth-logo">
        <span class="brand-mark">R</span>
        <span>Restaurant Management</span>
    </div>

    <h1>Staff login</h1>
    <p class="auth-sub">Sign in to continue.</p>

    <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?>">
            <?= e($flash['message']) ?>
        </div>
    <?php endif; ?>

    <form method="post">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" value="<?= e($email) ?>" required>

        <label for="password">Password</label>
        <input type="password" id="password" name="password" required>

        <button type="submit" class="btn btn-block">Log in</button>
    </form>

    <a class="auth-back" href="<?= BASE_URL ?>/">&larr; Back to home</a>
</div>

</body>
</html>