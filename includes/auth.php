<?php
require_once __DIR__ . '/functions.php';

function is_logged_in()
{
    return isset($_SESSION['user']);
}

function current_user()
{
    return $_SESSION['user'] ?? null;
}

// Where each role lands after login
function home_for_role($role)
{
    return $role === 'admin' ? '/admin/dashboard' : '/staff/dashboard';
}

function require_login()
{
    if (!is_logged_in()) {
        set_flash('error', 'Please log in first.');
        redirect('/login');
    }
}

// Example: require_role(['admin'])
function require_role(array $roles)
{
    require_login();
    $role = $_SESSION['user']['role'];

    if (!in_array($role, $roles, true)) {
        set_flash('error', 'You do not have access to that page.');
        redirect(home_for_role($role));
    }
}