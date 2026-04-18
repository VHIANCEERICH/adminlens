<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/helpers/data.php';
require_once __DIR__ . '/helpers/status.php';
require_once __DIR__ . '/helpers/charts.php';

try {
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
                        <div class="chart-switcher" role="tablist" aria-label="Product charts">
                            <button type="button" class="chart-switcher__btn is-active" data-chart-target="chart-ranking" aria-controls="chart-ranking" aria-selected="true">Sales ranking</button>
                            <button type="button" class="chart-switcher__btn" data-chart-target="chart-stock" aria-controls="chart-stock" aria-selected="false">Stock share</button>
                            <button type="button" class="chart-switcher__btn" data-chart-target="chart-value" aria-controls="chart-value" aria-selected="false">Inventory value</button>
                        </div>
                        <div class="chart-panels">
                            <article class="chart-card chart-card-best chart-panel is-active" id="chart-ranking" role="tabpanel">
                                <h3 class="chart-card-title">Sales ranking</h3>
                                <div class="chart-card-meta">
                                    <p class="sku">Units sold - best seller to least purchased</p>
                                    <span class="badge badge-ok">Ranking</span>
                                </div>
                                <div class="chart-frame chart-frame--viz">
                                    <?= adminlens_render_chart([
                                        'chart_type' => 'ranking',
                                        'products' => $products,
                                    ]) ?>
                                </div>
                            </article>

                            <article class="chart-card chart-panel" id="chart-stock" role="tabpanel" hidden>
                                <h3 class="chart-card-title">Stock share</h3>
                                <div class="chart-card-meta">
                                    <p class="sku">Current stock quantities across all products</p>
                                    <span class="badge badge-ok">Availability</span>
                                </div>
                                <div class="chart-frame chart-frame--viz">
                                    <?= adminlens_render_chart([
                                        'chart_type' => 'stock',
                                        'products' => $products,
                                    ]) ?>
                                </div>
                            </article>

                            <article class="chart-card chart-card-least chart-panel" id="chart-value" role="tabpanel" hidden>
                                <h3 class="chart-card-title">Inventory value</h3>
                                <div class="chart-card-meta">
                                    <p class="sku">Inventory value per product</p>
                                    <span class="badge badge-low">Value</span>
                                </div>
                                <div class="chart-frame chart-frame--viz">
                                    <?= adminlens_render_chart([
                                        'chart_type' => 'value',
                                        'products' => $products,
                                    ]) ?>
                                </div>
                            </article>
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

    <button type="button" class="assistant-fab" id="assistant-fab" aria-label="Ask AdminLens" aria-controls="assistant-modal" aria-expanded="false" data-tooltip="Ask AdminLens" title="Ask AdminLens">
        <span class="assistant-fab__icon"><span class="chatbot-logo" aria-hidden="true"><span class="chatbot-logo__head"><span class="chatbot-logo__face"><span class="chatbot-logo__eye chatbot-logo__eye--left"></span><span class="chatbot-logo__eye chatbot-logo__eye--right"></span></span></span><span class="chatbot-logo__base"></span></span></span>
        <span class="assistant-fab__label">Ask AdminLens</span>
    </button>

    <div class="assistant-modal" id="assistant-modal" aria-hidden="true">
        <div class="assistant-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="assistant-modal-title">
            <div class="assistant-modal__header">
                <div>
                    <div class="assistant-modal__title" id="assistant-modal-title">AdminLens AI Assistant</div>
                    <div class="assistant-modal__subtitle">Ask about stock, sales trends, restocking, and product insights without leaving the dashboard.</div>
                </div>
                <button type="button" class="assistant-modal__close" id="assistant-modal-close" aria-label="Close assistant">&times;</button>
            </div>
            <iframe
                class="assistant-modal__frame"
                id="assistant-modal-frame"
                src="about:blank"
                title="AdminLens AI Assistant"
                loading="lazy"
            ></iframe>
        </div>
    </div>
    <script>
        (function () {
            const buttons = Array.from(document.querySelectorAll('.chart-switcher__btn'));
            const panels = Array.from(document.querySelectorAll('.chart-panel'));
            const assistantFab = document.getElementById('assistant-fab');
            const assistantModal = document.getElementById('assistant-modal');
            const assistantClose = document.getElementById('assistant-modal-close');
            const assistantFrame = document.getElementById('assistant-modal-frame');
            const assistantSrc = 'chat.php?embed=1';

            function activate(targetId) {
                buttons.forEach((button) => {
                    const isActive = button.dataset.chartTarget === targetId;
                    button.classList.toggle('is-active', isActive);
                    button.setAttribute('aria-selected', isActive ? 'true' : 'false');
                });

                panels.forEach((panel) => {
                    const isActive = panel.id === targetId;
                    panel.classList.toggle('is-active', isActive);
                    panel.hidden = !isActive;
                });
            }

            buttons.forEach((button) => {
                button.addEventListener('click', () => activate(button.dataset.chartTarget));
            });

            if (buttons.length > 0) {
                activate(buttons[0].dataset.chartTarget);
            }

            function openAssistant() {
                if (assistantModal === null || assistantFab === null || assistantFrame === null) {
                    return;
                }

                if (assistantFrame.dataset.loaded !== 'true') {
                    assistantFrame.src = assistantSrc;
                    assistantFrame.dataset.loaded = 'true';
                }

                assistantModal.classList.add('is-open');
                assistantModal.setAttribute('aria-hidden', 'false');
                assistantFab.setAttribute('aria-expanded', 'true');
                document.body.style.overflow = 'hidden';
            }

            function closeAssistant() {
                if (assistantModal === null || assistantFab === null) {
                    return;
                }

                assistantModal.classList.remove('is-open');
                assistantModal.setAttribute('aria-hidden', 'true');
                assistantFab.setAttribute('aria-expanded', 'false');
                document.body.style.overflow = '';
            }

            if (assistantFab !== null) {
                assistantFab.addEventListener('click', openAssistant);
            }

            if (assistantClose !== null) {
                assistantClose.addEventListener('click', closeAssistant);
            }

            if (assistantModal !== null) {
                assistantModal.addEventListener('click', (event) => {
                    if (event.target === assistantModal) {
                        closeAssistant();
                    }
                });
            }

            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && assistantModal !== null && assistantModal.classList.contains('is-open')) {
                    closeAssistant();
                }
            });
        })();
    </script>
</body>
</html>
