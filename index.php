<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/helpers/data.php';
require_once __DIR__ . '/helpers/status.php';
require_once __DIR__ . '/helpers/charts.php';

try {
    generate_all_charts();
    $products = get_all_products();
    $best_seller = get_best_seller();
    $least_sold = get_least_sold();
    $total_value = get_total_inventory_value();
    $low_stock = get_low_stock();
    $out_of_stock = get_out_of_stock();
    $total_skus = count($products);
} catch (Throwable $e) {
    header('Location: error.php?message=' . rawurlencode('Unable to load dashboard data.'));
    exit;
}

function adminlens_status_badge_class(string $status): string
{
    return 'badge ' . get_badge_class($status);
}

function adminlens_card_class(array $product, array $bestSeller, array $leastSold): string
{
    if (($product['sku'] ?? '') === ($bestSeller['sku'] ?? '')) {
        return 'chart-card chart-card-best';
    }

    if (($product['sku'] ?? '') === ($leastSold['sku'] ?? '')) {
        return 'chart-card chart-card-least';
    }

    return 'chart-card';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AdminLens Dashboard</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <div class="page-shell">
        <header class="site-header">
            <div class="brand">AdminLens</div>
            <nav class="site-nav">
                <a href="index.php" class="is-active">Dashboard</a>
                <a href="inventory.php">Inventory</a>
                <a href="#charts">Charts</a>
            </nav>
        </header>

        <main>
            <h1 class="page-title">Inventory Dashboard</h1>
            <p class="page-intro">A clear view of boutique performance, stock pressure, and product-level chart snapshots.</p>

            <div class="dashboard-grid">
                <div class="dashboard-main">
                    <section class="kpi-grid">
                        <article class="kpi-card">
                            <p class="kpi-label">Total SKUs</p>
                            <p class="kpi-value"><?= number_format($total_skus) ?></p>
                        </article>

                        <article class="kpi-card kpi-card-primary">
                            <p class="kpi-label">Total Inventory Value</p>
                            <p class="kpi-value">&#8369;<?= number_format((float) $total_value, 2) ?></p>
                        </article>

                        <article class="kpi-card kpi-card-success">
                            <p class="kpi-label">Best Seller</p>
                            <p class="kpi-value"><?= htmlspecialchars((string) ($best_seller['product_name'] ?? 'N/A')) ?></p>
                            <p class="kpi-meta"><?= number_format((int) ($best_seller['units_sold'] ?? 0)) ?> units sold</p>
                        </article>

                        <article class="kpi-card kpi-card-danger">
                            <p class="kpi-label">Least Purchased</p>
                            <p class="kpi-value"><?= htmlspecialchars((string) ($least_sold['product_name'] ?? 'N/A')) ?></p>
                            <p class="kpi-meta"><?= number_format((int) ($least_sold['units_sold'] ?? 0)) ?> units sold</p>
                        </article>
                    </section>

                    <section class="section-block" id="charts">
                        <h2 class="section-title">Product Performance Charts</h2>
                        <div class="chart-grid">
                            <?php foreach ($products as $product): ?>
                                <article class="<?= adminlens_card_class($product, $best_seller, $least_sold) ?>">
                                    <h3 class="chart-card-title"><?= htmlspecialchars((string) ($product['product_name'] ?? 'Unnamed Product')) ?></h3>
                                    <div class="chart-card-meta">
                                        <p class="sku"><?= htmlspecialchars((string) ($product['sku'] ?? '')) ?></p>
                                        <span class="<?= adminlens_status_badge_class((string) ($product['status'] ?? 'OK')) ?>">
                                            <?= htmlspecialchars((string) ($product['status'] ?? 'OK')) ?>
                                        </span>
                                    </div>
                                    <div class="chart-frame">
                                        <img
                                            src="assets/charts/<?= rawurlencode((string) ($product['sku'] ?? 'product')) ?>_chart.png"
                                            alt="<?= htmlspecialchars((string) ($product['product_name'] ?? 'Product')) ?> chart"
                                        >
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <?php if (!empty($low_stock) || !empty($out_of_stock)): ?>
                        <section class="section-block">
                            <div class="alert-box">
                                <h2 class="section-title">Low Stock Alerts</h2>
                                <p class="page-intro">Products below target levels need attention.</p>
                                <ul>
                                    <?php foreach (array_merge($out_of_stock, $low_stock) as $product): ?>
                                        <li>
                                            <strong><?= htmlspecialchars((string) ($product['product_name'] ?? 'Unnamed Product')) ?></strong>
                                            - stock on hand: <?= number_format((int) ($product['stock_on_hand'] ?? 0)) ?>
                                            - reorder point: <?= number_format((int) ($product['reorder_point'] ?? 0)) ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </section>
                    <?php endif; ?>
                </div>

            </div>
        </main>
    </div>

    <a class="assistant-fab" href="chat.php" aria-label="Open AI Assistant">
        <span class="assistant-fab__icon"><span class="chatbot-logo" aria-hidden="true"><span class="chatbot-logo__head"><span class="chatbot-logo__face"><span class="chatbot-logo__eye chatbot-logo__eye--left"></span><span class="chatbot-logo__eye chatbot-logo__eye--right"></span></span></span><span class="chatbot-logo__base"></span></span></span>
        <span class="assistant-fab__label">Ask AdminLens</span>
    </a>
</body>
</html>
