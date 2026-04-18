<?php

declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';

adminlens_require_role('admin');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Details - AdminLens</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <div class="page-shell">
        <header class="site-header">
            <div class="brand">AdminLens</div>
            <nav class="site-nav">
                <a href="index.php">Dashboard</a>
                <a href="inventory.php">Inventory</a>
                <a href="orders.php">Orders</a>
                <a href="order_details.php" class="is-active">Order Details</a>
                <a href="index.php#charts">Charts</a>
                <a href="auth/logout.php?redirect=admin">Logout</a>
            </nav>
        </header>

        <main>
            <h1 class="page-title">Order Details</h1>
            <p class="page-intro">Use this page as the detail view for a selected order.</p>

            <section class="section-block">
                <div class="alert-box">
                    <h2 class="section-title">Detail View Placeholder</h2>
                    <p class="page-intro">
                        The admin navigation now includes a dedicated Order Details destination. When you are ready,
                        we can connect this page to a real order record and show items, totals, customer info, and status history.
                    </p>
                </div>
            </section>
        </main>
    </div>
</body>
</html>
