<?php

declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/helpers/orders.php';

adminlens_require_role('admin');

$pending_orders = get_pending_orders_count();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orders - AdminLens</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <div class="page-shell">
        <header class="site-header">
            <div class="brand">AdminLens</div>
            <nav class="site-nav">
                <a href="index.php">Dashboard</a>
                <a href="inventory.php">Inventory</a>
                <a href="orders.php" class="is-active">Orders</a>
                <a href="order_details.php">Order Details</a>
                <a href="index.php#charts">Charts</a>
                <a href="auth/logout.php?redirect=admin">Logout</a>
            </nav>
        </header>

        <main>
            <h1 class="page-title">Orders</h1>
            <p class="page-intro">Quick access point for customer order management inside the admin area.</p>

            <section class="section-block">
                <div class="kpi-grid">
                    <article class="kpi-card kpi-card-primary">
                        <p class="kpi-label">Pending Orders</p>
                        <p class="kpi-value"><?= number_format($pending_orders) ?></p>
                        <p class="kpi-meta">Orders waiting for admin review.</p>
                    </article>
                </div>
            </section>

            <section class="section-block">
                <div class="alert-box">
                    <h2 class="section-title">Orders Page Ready</h2>
                    <p class="page-intro">
                        The navigation is now connected. You can extend this page with a full orders table once your
                        order schema and fields are finalized.
                    </p>
                </div>
            </section>
        </main>
    </div>
</body>
</html>
