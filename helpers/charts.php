<?php

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/data.php';

function generate_chart_for_product(array $product, string $rank): void
{
    if (!is_dir(CHARTS_DIR)) {
        mkdir(CHARTS_DIR, 0777, true);
    }

    $payload = [
        'sku' => (string) ($product['sku'] ?? ''),
        'product_name' => (string) ($product['product_name'] ?? ''),
        'stock_on_hand' => (int) ($product['stock_on_hand'] ?? 0),
        'reorder_point' => (int) ($product['reorder_point'] ?? 0),
        'units_sold' => (int) ($product['units_sold'] ?? 0),
        'rank' => $rank,
        'output_dir' => CHARTS_DIR,
    ];

    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Unable to encode chart payload.');
    }

    $command = escapeshellcmd(PYTHON_CMD)
        . ' '
        . escapeshellarg(__DIR__ . '/../charts/product_chart.py')
        . ' '
        . escapeshellarg($json);

    shell_exec($command);
}

function generate_all_charts(): void
{
    $products = get_all_products();
    $bestSku = (string) (get_best_seller()['sku'] ?? '');
    $leastSku = (string) (get_least_sold()['sku'] ?? '');

    foreach ($products as $product) {
        $rank = 'normal';

        if ((string) ($product['sku'] ?? '') === $bestSku) {
            $rank = 'best';
        }

        if ((string) ($product['sku'] ?? '') === $leastSku) {
            $rank = 'least';
        }

        generate_chart_for_product($product, $rank);
    }
}
