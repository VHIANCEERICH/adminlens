<?php

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/data.php';

// ---------------------------------------------------------------------------
// Core renderer
// Calls product_chart.py with a JSON payload file and returns inline HTML.
// Output is pure HTML/SVG — no PNG files, no image tags.
// ---------------------------------------------------------------------------

function adminlens_render_chart(array $payload): string
{
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return '<div class="chart-empty">Unable to encode chart data.</div>';
    }

    $tmpBase = tempnam(sys_get_temp_dir(), 'alc_');
    if ($tmpBase === false) {
        return '<div class="chart-empty">Unable to create temp file.</div>';
    }

    $jsonFile = $tmpBase . '.json';
    rename($tmpBase, $jsonFile);

    if (file_put_contents($jsonFile, $json) === false) {
        @unlink($jsonFile);
        return '<div class="chart-empty">Unable to write chart data.</div>';
    }

    $cmd = escapeshellarg(PYTHON_CMD)
        . ' ' . escapeshellarg(__DIR__ . '/../charts/product_chart.py')
        . ' ' . escapeshellarg($jsonFile)
        . ' 2>&1';

    $output = shell_exec($cmd);
    @unlink($jsonFile);

    if (!is_string($output) || trim($output) === '') {
        return '<div class="chart-empty">Chart rendering returned no output.</div>';
    }

    $trimmed = trim($output);
    if (str_starts_with($trimmed, 'Chart generation failed:')) {
        return '<div class="chart-empty">' . htmlspecialchars($trimmed, ENT_QUOTES, 'UTF-8') . '</div>';
    }

    return $output;
}

// ---------------------------------------------------------------------------
// Normalise a product row into the shape product_chart.py expects
// Accepts either snake_case DB columns or aliased keys.
// ---------------------------------------------------------------------------

function adminlens_normalise_product(array $p): array
{
    return [
        'sku'           => $p['sku']            ?? '',
        'product_name'  => $p['product_name']   ?? $p['name']  ?? 'Unnamed',
        'units_sold'    => (int)   ($p['units_sold']    ?? $p['sold']  ?? 0),
        'stock_on_hand' => (int)   ($p['stock_on_hand'] ?? $p['stock'] ?? 0),
        'reorder_point' => (int)   ($p['reorder_point'] ?? 0),
        'price'         => (float) ($p['price']         ?? 0),
    ];
}

// ---------------------------------------------------------------------------
// Build a products-list payload
// ---------------------------------------------------------------------------

function adminlens_products_payload(array $products, string $chart_type): array
{
    return [
        'chart_type' => $chart_type,
        'products'   => array_map('adminlens_normalise_product', $products),
    ];
}

// ---------------------------------------------------------------------------
// Detect rank of a product within a list (best / least / normal)
// Used only for individual product cards.
// ---------------------------------------------------------------------------

function adminlens_detect_rank(array $product, array $all_products): string
{
    if (empty($all_products)) {
        return 'normal';
    }

    $sold = (int) ($product['units_sold'] ?? $product['sold'] ?? 0);
    $all  = array_map(fn($p) => (int) ($p['units_sold'] ?? $p['sold'] ?? 0), $all_products);
    $max  = max($all);
    $min  = min($all);

    if ($sold === $max) return 'best';
    if ($sold === $min) return 'least';
    return 'normal';
}

// ---------------------------------------------------------------------------
// MAIN API
// ---------------------------------------------------------------------------

/**
 * Full tabbed dashboard (recommended for the performance page).
 *
 * Renders three tab buttons (Sales ranking / Stock share / Inventory value).
 * Clicking each button swaps the chart.
 * Product legend rows at the bottom — always visible.
 *
 * Usage:
 *   $products = get_all_products();   // your existing fetch function
 *   echo adminlens_render_dashboard($products);
 */
function adminlens_render_dashboard(array $products): string
{
    if (empty($products)) {
        return '<div class="chart-empty">No products to display.</div>';
    }

    $payload = adminlens_products_payload($products, 'dashboard');
    return adminlens_render_chart($payload);
}

/**
 * Individual product performance card.
 * Shows units sold vs stock on hand with a reorder-point marker.
 *
 * @param array $product     A single product row.
 * @param array $all_products  Full list — used to determine best/least rank colouring.
 */
function adminlens_render_product_chart(array $product, array $all_products = []): string
{
    $normalised = adminlens_normalise_product($product);
    $normalised['chart_type'] = 'product';
    $normalised['rank']       = adminlens_detect_rank($product, $all_products ?: [$product]);
    return adminlens_render_chart($normalised);
}

/**
 * Horizontal bar chart only — Sales ranking, best to least.
 */
function adminlens_render_ranking_chart(array $products): string
{
    if (empty($products)) {
        return '<div class="chart-empty">No products to display.</div>';
    }

    return adminlens_render_chart(adminlens_products_payload($products, 'ranking'));
}

/**
 * Donut / pie chart only — Stock share per product.
 */
function adminlens_render_stock_chart(array $products): string
{
    if (empty($products)) {
        return '<div class="chart-empty">No products to display.</div>';
    }

    return adminlens_render_chart(adminlens_products_payload($products, 'stock'));
}

/**
 * Line chart only — Inventory value (price × stock) per product.
 */
function adminlens_render_value_chart(array $products): string
{
    if (empty($products)) {
        return '<div class="chart-empty">No products to display.</div>';
    }

    return adminlens_render_chart(adminlens_products_payload($products, 'value'));
}

// ---------------------------------------------------------------------------
// Shared CSS — injected once per page, idempotent
// ---------------------------------------------------------------------------

function adminlens_chart_styles(): string
{
    static $done = false;
    if ($done) {
        return '';
    }
    $done = true;

    return <<<'CSS'
<style>
.chart-empty {
    padding: 16px;
    color: #64748b;
    font-size: 13px;
    font-style: italic;
}

/* Outer wrapper injected by PHP callers if needed */
.adminlens-chart-wrap {
    font-family: system-ui, -apple-system, sans-serif;
}
</style>
CSS;
}

// ---------------------------------------------------------------------------
// Legacy stubs — kept for backward compatibility, safe no-ops
// ---------------------------------------------------------------------------

function generate_chart_for_product(array $product, string $rank): void
{
    // Charts are now rendered as inline HTML/SVG — no file generation.
}

function generate_all_charts(): void
{
    // Charts are now rendered as inline HTML/SVG — no file generation.
}