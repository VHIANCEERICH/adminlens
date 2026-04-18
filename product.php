<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/helpers/data.php';
require_once __DIR__ . '/helpers/status.php';
require_once __DIR__ . '/helpers/charts.php';

$sku = (string) ($_GET['sku'] ?? '');

try {
    $product = get_product_by_sku($sku);

    if (!$product) {
        header('Location: inventory.php');
        exit;
    }

    $product['status'] = get_status($product);
    $is_best = ($product['sku'] === (string) (get_best_seller()['sku'] ?? ''));
    $is_least = ($product['sku'] === (string) (get_least_sold()['sku'] ?? ''));
} catch (Throwable $e) {
    header('Location: error.php?message=' . rawurlencode('Unable to load product details.'));
    exit;
}

function adminlens_status_badge_class(string $status): string
{
    return 'badge ' . get_badge_class($status);
}

$inventoryValue = ((float) ($product['stock_on_hand'] ?? 0)) * ((float) ($product['price'] ?? 0));
$productImage = get_product_image_url($sku);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AdminLens Product Detail</title>
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
                <a href="order_details.php">Order Details</a>
                <a href="index.php#charts">Charts</a>
                <a href="auth/logout.php?redirect=admin">Logout</a>
            </nav>
        </header>

        <main>
            <div class="detail-grid">
                <section class="detail-panel">
                    <?php if ($productImage): ?>
                        <div class="product-image-frame">
                            <img src="<?= htmlspecialchars($productImage) ?>" alt="<?= htmlspecialchars((string) ($product['product_name'] ?? 'Product')) ?> image">
                        </div>
                    <?php endif; ?>
                    <h1 class="page-title"><?= htmlspecialchars((string) ($product['product_name'] ?? 'Unnamed Product')) ?></h1>
                    <p class="sku"><?= htmlspecialchars($sku) ?></p>
                    <p style="margin: 14px 0 18px;">
                        <span class="<?= adminlens_status_badge_class((string) ($product['status'] ?? 'OK')) ?>">
                            <?= htmlspecialchars((string) ($product['status'] ?? 'OK')) ?>
                        </span>
                    </p>

                    <?php if ($is_best): ?>
                        <p class="rank-badge rank-best">Best Seller</p>
                    <?php elseif ($is_least): ?>
                        <p class="rank-badge rank-least">Least Purchased</p>
                    <?php endif; ?>

                    <table class="detail-table">
                        <tbody>
                            <tr>
                                <td>Product type</td>
                                <td><?= htmlspecialchars((string) (($product['product_type'] ?? '') !== '' ? $product['product_type'] : 'Uncategorized')) ?></td>
                            </tr>
                            <tr>
                                <td>Stock on hand</td>
                                <td><?= number_format((int) ($product['stock_on_hand'] ?? 0)) ?></td>
                            </tr>
                            <tr>
                                <td>Reorder point</td>
                                <td><?= number_format((int) ($product['reorder_point'] ?? 0)) ?></td>
                            </tr>
                            <tr>
                                <td>Units sold</td>
                                <td><?= number_format((int) ($product['units_sold'] ?? 0)) ?></td>
                            </tr>
                            <tr>
                                <td>Unit price</td>
                                <td>&#8369;<?= number_format((float) ($product['price'] ?? 0), 2) ?></td>
                            </tr>
                            <tr>
                                <td>Inventory value (stock x price)</td>
                                <td>&#8369;<?= number_format($inventoryValue, 2) ?></td>
                            </tr>
                        </tbody>
                    </table>

                    <a class="back-link" href="inventory.php">&larr; Back to Inventory</a>
                </section>

                <section class="detail-panel">
                    <div class="chart-frame chart-frame--viz">
                        <?= adminlens_render_chart([
                            'chart_type' => 'product',
                            'sku' => (string) ($product['sku'] ?? ''),
                            'product_name' => (string) ($product['product_name'] ?? ''),
                            'stock_on_hand' => (int) ($product['stock_on_hand'] ?? 0),
                            'reorder_point' => (int) ($product['reorder_point'] ?? 0),
                            'units_sold' => (int) ($product['units_sold'] ?? 0),
                            'rank' => $is_best ? 'best' : ($is_least ? 'least' : 'normal'),
                        ]) ?>
                    </div>
                    <p class="chart-caption">
                        Green = best seller state, blue = stock on hand, dashed red line = reorder point.
                    </p>
                </section>
            </div>
        </main>
    </div>
</body>
</html>
