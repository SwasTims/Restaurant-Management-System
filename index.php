<?php
require_once __DIR__ . '/includes/auth.php';

$logged_in = is_logged_in();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Restaurant Management System</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body class="landing">

<nav class="navbar">
    <div class="container navbar-inner">
        <a href="#top" class="brand">
            <span class="brand-mark">R</span>
            <span>Restaurant Management</span>
        </a>
        <div class="nav-links">
            <a href="#features">Features</a>
            <a href="#about">About</a>
            <a href="#contact">Contact</a>
            <?php if ($logged_in): ?>
                <a class="nav-cta" href="<?= BASE_URL . home_for_role(current_user()['role']) ?>">Dashboard</a>
            <?php else: ?>
                <a class="nav-cta" href="<?= BASE_URL ?>/login">Login</a>
            <?php endif; ?>
        </div>
    </div>
</nav>

<section class="hero" id="top">
    <div class="container">
        <h1>Run your restaurant from one place</h1>
        <p>Take orders, manage the menu, track stock and see daily sales, with a screen for every role on your team.</p>
        <a class="btn" href="<?= BASE_URL ?>/login">Staff login</a>
    </div>
</section>

<section class="section" id="features">
    <div class="container">
        <h2>Features</h2>
        <div class="feature-grid">
            <div class="feature">
                <h3>Orders</h3>
                <p>Waiters take orders per table and send them straight to the kitchen.</p>
            </div>
            <div class="feature">
                <h3>Kitchen screen</h3>
                <p>Live tickets with a clear status flow from pending to served.</p>
            </div>
            <div class="feature">
                <h3>Billing</h3>
                <p>Discounts, VAT, payment methods and printable receipts.</p>
            </div>
            <div class="feature">
                <h3>Inventory</h3>
                <p>Ingredients and recipes, with stock reduced as dishes are sold.</p>
            </div>
            <div class="feature">
                <h3>Reservations</h3>
                <p>Book tables by date and time without double bookings.</p>
            </div>
            <div class="feature">
                <h3>Reports</h3>
                <p>Daily sales and top-selling items at a glance.</p>
            </div>
        </div>
    </div>
</section>

<section class="section section-alt" id="about">
    <div class="container narrow">
        <h2>About</h2>
        <p>Restaurant Management System is a web application built with core PHP and MySQL. It gives admins, waiters, kitchen staff and cashiers each their own workspace, so every part of the restaurant runs from the same system.</p>
    </div>
</section>

<section class="section" id="contact">
    <div class="container narrow">
        <h2>Contact</h2>
        <p>Replace these with your own details.</p>
        <ul class="contact-list">
            <li>Email: hello@example.com</li>
            <li>Phone: +977 98XXXXXXXX</li>
            <li>Address: Biratnagar, Nepal</li>
        </ul>
    </div>
</section>

<footer class="footer">
    <div class="container">&copy; <?= date('Y') ?> Restaurant Management System</div>
</footer>

</body>
</html>