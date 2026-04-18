<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/status.php';
require_once __DIR__ . '/archive.php';

function adminlens_pdo(): PDO
{
    static $pdo = null;

    if (!$pdo instanceof PDO) {
        $pdo = require __DIR__ . '/../config/db.php';
    }

    return $pdo;
}

function adminlens_with_status(array $product): array
{
    $product['status'] = get_status($product);
    return $product;
}

function adminlens_filter_archived_products(array $rows): array
{
    $archived = adminlens_get_archived_skus();

    if ($archived === []) {
        return $rows;
    }

    return array_values(array_filter($rows, static function (array $row) use ($archived): bool {
        $sku = adminlens_normalize_sku((string) ($row['sku'] ?? ''));
        return $sku !== '' && !in_array($sku, $archived, true);
    }));
}

function get_all_products(bool $includeArchived = false): array
{
    try {
        $stmt = adminlens_pdo()->query('SELECT * FROM products ORDER BY units_sold DESC');
        $rows = $stmt->fetchAll();

        if (!$includeArchived) {
            $rows = adminlens_filter_archived_products($rows);
        }

        return array_map('adminlens_with_status', $rows);
    } catch (Throwable $e) {
        throw new RuntimeException('Unable to fetch products.');
    }
}

function get_best_seller(): array
{
    try {
        $products = get_all_products();
        return $products[0] ?? [];
    } catch (Throwable $e) {
        throw new RuntimeException('Unable to fetch best seller.');
    }
}

function get_least_sold(): array
{
    try {
        $products = get_all_products();

        if ($products === []) {
            return [];
        }

        return $products[array_key_last($products)] ?? [];
    } catch (Throwable $e) {
        throw new RuntimeException('Unable to fetch least sold product.');
    }
}

function get_product_by_sku(string $sku, bool $includeArchived = true): ?array
{
    try {
        $stmt = adminlens_pdo()->prepare('SELECT * FROM products WHERE sku = ? LIMIT 1');
        $stmt->execute([$sku]);
        $row = $stmt->fetch();

        if ($row && !$includeArchived && adminlens_is_archived((string) ($row['sku'] ?? ''))) {
            return null;
        }

        return $row ? adminlens_with_status($row) : null;
    } catch (Throwable $e) {
        throw new RuntimeException('Unable to fetch product.');
    }
}

function get_low_stock(): array
{
    try {
        $rows = array_filter(get_all_products(), static function (array $product): bool {
            $stock = (int) ($product['stock_on_hand'] ?? 0);
            $reorder = (int) ($product['reorder_point'] ?? 0);

            return $stock > 0 && $stock < $reorder;
        });

        return array_values($rows);
    } catch (Throwable $e) {
        throw new RuntimeException('Unable to fetch low stock products.');
    }
}

function get_out_of_stock(): array
{
    try {
        $rows = array_filter(get_all_products(), static function (array $product): bool {
            return (int) ($product['stock_on_hand'] ?? 0) === 0;
        });

        return array_values($rows);
    } catch (Throwable $e) {
        throw new RuntimeException('Unable to fetch out of stock products.');
    }
}

function get_total_inventory_value(): float
{
    try {
        $total = 0.0;

        foreach (get_all_products() as $product) {
            $total += ((float) ($product['stock_on_hand'] ?? 0)) * ((float) ($product['price'] ?? 0));
        }

        return $total;
    } catch (Throwable $e) {
        throw new RuntimeException('Unable to calculate inventory value.');
    }
}

function get_archived_products(): array
{
    try {
        $products = get_all_products(true);

        return array_values(array_filter($products, static function (array $product): bool {
            return adminlens_is_archived((string) ($product['sku'] ?? ''));
        }));
    } catch (Throwable $e) {
        throw new RuntimeException('Unable to fetch archived products.');
    }
}

function adminlens_build_ai_inventory_context(array $products): string
{
    if ($products === []) {
        return "Inventory snapshot: no active products found.";
    }

    $lines = [];
    foreach ($products as $product) {
        $lines[] = sprintf(
            '- SKU %s | %s | stock %d | reorder %d | sold %d | price PHP %s | status %s',
            (string) ($product['sku'] ?? ''),
            (string) ($product['product_name'] ?? 'Unnamed Product'),
            (int) ($product['stock_on_hand'] ?? 0),
            (int) ($product['reorder_point'] ?? 0),
            (int) ($product['units_sold'] ?? 0),
            number_format((float) ($product['price'] ?? 0), 2),
            (string) ($product['status'] ?? 'OK')
        );
    }

    return "Inventory snapshot (authoritative, use these values exactly):\n" . implode("\n", $lines);
}

function get_product_image_url(string $sku): ?string
{
    $baseDir = __DIR__ . '/../assets/product_images/';
    $baseUrl = 'assets/product_images/';
    $safeSku = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower($sku));
    $extensions = ['png', 'jpg', 'jpeg', 'webp'];

    foreach ($extensions as $ext) {
        $candidate = $baseDir . $safeSku . '.' . $ext;
        if (is_file($candidate)) {
            return $baseUrl . $safeSku . '.' . $ext;
        }
    }

    return null;
}

