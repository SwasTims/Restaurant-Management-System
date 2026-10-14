<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('BASE_URL', '/restaurant-management-system');

// Escape text before printing it in HTML
function e($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

// Send the browser to another page, then stop the script
function redirect($path)
{
    header('Location: ' . BASE_URL . $path);
    exit;
}

// Store a one-time message for the next page load
function set_flash($type, $message)
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

// Read the one-time message and delete it so it shows only once
function get_flash()
{
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}