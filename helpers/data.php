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
            '- SKU %s | %s | type %s | stock %d | reorder %d | sold %d | price PHP %s | status %s',
            (string) ($product['sku'] ?? ''),
            (string) ($product['product_name'] ?? 'Unnamed Product'),
            (string) (($product['product_type'] ?? '') !== '' ? $product['product_type'] : 'Uncategorized'),
            (int) ($product['stock_on_hand'] ?? 0),
            (int) ($product['reorder_point'] ?? 0),
            (int) ($product['units_sold'] ?? 0),
            number_format((float) ($product['price'] ?? 0), 2),
            (string) ($product['status'] ?? 'OK')
        );
    }

    return "Inventory snapshot (authoritative, use these values exactly):\n" . implode("\n", $lines);
}

function adminlens_build_targeted_inventory_context(array $products, string $query, int $limit = 10): string
{
    if ($products === []) {
        return "Inventory snapshot: no active products found.";
    }

    $queryLower = strtolower(trim($query));
    $tokens = preg_split('/[^a-z0-9]+/', $queryLower) ?: [];
    $stopWords = [
        'the', 'and', 'for', 'with', 'from', 'that', 'this', 'what', 'which', 'should', 'would',
        'about', 'into', 'have', 'has', 'had', 'are', 'was', 'were', 'your', 'my', 'our', 'their',
        'today', 'show', 'list', 'need', 'want', 'please', 'tell', 'give', 'stock', 'product', 'products'
    ];
    $tokens = array_values(array_filter($tokens, static function (string $token) use ($stopWords): bool {
        return strlen($token) >= 3 && !in_array($token, $stopWords, true);
    }));

    $isRestockQuery = str_contains($queryLower, 'restock') || str_contains($queryLower, 'low stock') || str_contains($queryLower, 'reorder');
    $isOutOfStockQuery = str_contains($queryLower, 'out of stock');
    $isBestSellerQuery = str_contains($queryLower, 'best seller') || str_contains($queryLower, 'selling the most') || str_contains($queryLower, 'top seller');

    $scored = [];
    foreach ($products as $index => $product) {
        $sku = strtolower((string) ($product['sku'] ?? ''));
        $name = strtolower((string) ($product['product_name'] ?? ''));
        $type = strtolower((string) ($product['product_type'] ?? ''));
        $haystack = $sku . ' ' . $name . ' ' . $type;
        $score = 0;

        if ($queryLower !== '' && ($sku !== '' && str_contains($queryLower, $sku))) {
            $score += 12;
        }

        if ($queryLower !== '' && ($name !== '' && str_contains($queryLower, $name))) {
            $score += 10;
        }

        if ($queryLower !== '' && ($type !== '' && str_contains($queryLower, $type))) {
            $score += 8;
        }

        foreach ($tokens as $token) {
            if (str_contains($haystack, $token)) {
                $score += 3;
            }
        }

        $stock = (int) ($product['stock_on_hand'] ?? 0);
        $reorder = (int) ($product['reorder_point'] ?? 0);
        $sold = (int) ($product['units_sold'] ?? 0);

        if ($isRestockQuery && $stock <= $reorder) {
            $score += 8;
        }

        if ($isOutOfStockQuery && $stock === 0) {
            $score += 8;
        }

        if ($isBestSellerQuery) {
            $score += min(8, (int) floor($sold / 10));
        }

        $scored[] = [
            'product' => $product,
            'score' => $score,
            'index' => $index,
        ];
    }

    usort($scored, static function (array $a, array $b): int {
        $scoreCompare = $b['score'] <=> $a['score'];
        if ($scoreCompare !== 0) {
            return $scoreCompare;
        }

        $soldA = (int) ($a['product']['units_sold'] ?? 0);
        $soldB = (int) ($b['product']['units_sold'] ?? 0);
        $soldCompare = $soldB <=> $soldA;
        if ($soldCompare !== 0) {
            return $soldCompare;
        }

        return $a['index'] <=> $b['index'];
    });

    $selected = array_filter($scored, static fn(array $item): bool => $item['score'] > 0);
    if ($selected === []) {
        $selected = array_slice($scored, 0, max(1, $limit));
    } else {
        $selected = array_slice(array_values($selected), 0, max(1, $limit));
    }

    $lines = [];
    foreach ($selected as $item) {
        $product = $item['product'];
        $lines[] = sprintf(
            '- SKU %s | %s | type %s | stock %d | reorder %d | sold %d | price PHP %s | status %s',
            (string) ($product['sku'] ?? ''),
            (string) ($product['product_name'] ?? 'Unnamed Product'),
            (string) (($product['product_type'] ?? '') !== '' ? $product['product_type'] : 'Uncategorized'),
            (int) ($product['stock_on_hand'] ?? 0),
            (int) ($product['reorder_point'] ?? 0),
            (int) ($product['units_sold'] ?? 0),
            number_format((float) ($product['price'] ?? 0), 2),
            (string) ($product['status'] ?? 'OK')
        );
    }

    return "Relevant inventory snapshot (authoritative, use these values exactly):\n" . implode("\n", $lines);
}

function adminlens_tokenize_query(string $query): array
{
    $query = strtolower(trim($query));
    $tokens = preg_split('/[^a-z0-9]+/', $query) ?: [];
    $stopWords = [
        'the', 'and', 'for', 'with', 'from', 'that', 'this', 'what', 'which', 'should', 'would',
        'about', 'into', 'have', 'has', 'had', 'are', 'was', 'were', 'your', 'my', 'our', 'their',
        'today', 'show', 'list', 'need', 'want', 'please', 'tell', 'give', 'stock', 'product', 'products',
        'many', 'does', 'do', 'how', 'much', 'summary', 'summarize', 'status', 'inventory'
    ];

    return array_values(array_filter($tokens, static function (string $token) use ($stopWords): bool {
        return strlen($token) >= 3 && !in_array($token, $stopWords, true);
    }));
}

function adminlens_try_fast_inventory_answer(array $products, string $query): ?string
{
    if ($products === []) {
        return 'There are no active products in the inventory right now.';
    }

    $queryLower = strtolower(trim($query));
    $tokens = adminlens_tokenize_query($query);

    if ((str_contains($queryLower, 'how many products') || str_contains($queryLower, 'how many items') || str_contains($queryLower, 'total products') || str_contains($queryLower, 'total items'))
        && (str_contains($queryLower, 'list') || str_contains($queryLower, 'all') || str_contains($queryLower, 'show'))) {
        $parts = array_map(static function (array $product): string {
            return sprintf(
                '%s (%s)',
                (string) ($product['product_name'] ?? 'Unnamed Product'),
                (string) ($product['sku'] ?? 'N/A')
            );
        }, $products);

        return sprintf(
            'You have %d active products: %s.',
            count($products),
            implode(', ', $parts)
        );
    }

    if (str_contains($queryLower, 'how many products') || str_contains($queryLower, 'how many items') || str_contains($queryLower, 'total products') || str_contains($queryLower, 'total items')) {
        return sprintf('You have %d active products in the inventory.', count($products));
    }

    if (str_contains($queryLower, 'price') || str_contains($queryLower, 'prices') || str_contains($queryLower, 'cost')) {
        $matches = [];

        foreach ($products as $product) {
            $sku = strtolower((string) ($product['sku'] ?? ''));
            $name = strtolower((string) ($product['product_name'] ?? ''));

            $matched = false;
            if ($sku !== '' && str_contains($queryLower, $sku)) {
                $matched = true;
            }

            if (!$matched && $name !== '' && str_contains($queryLower, $name)) {
                $matched = true;
            }

            if (!$matched && $tokens !== []) {
                $haystack = $sku . ' ' . $name;
                $tokenHits = 0;
                foreach ($tokens as $token) {
                    if (str_contains($haystack, $token)) {
                        $tokenHits++;
                    }
                }

                if ($tokenHits >= 2) {
                    $matched = true;
                }
            }

            if ($matched) {
                $matches[] = $product;
            }
        }

        $wantsAllPrices = str_contains($queryLower, 'all')
            || str_contains($queryLower, 'every')
            || str_contains($queryLower, 'list')
            || str_contains($queryLower, 'show');

        if ($matches === [] && $wantsAllPrices) {
            $matches = $products;
        }

        if ($matches !== []) {
            $items = array_slice($matches, 0, count($matches) <= 20 ? count($matches) : 10);
            $parts = array_map(static function (array $product): string {
                return sprintf(
                    '%s (%s): PHP %s',
                    (string) ($product['product_name'] ?? 'Unnamed Product'),
                    (string) ($product['sku'] ?? 'N/A'),
                    number_format((float) ($product['price'] ?? 0), 2)
                );
            }, $items);

            $reply = implode('; ', $parts) . '.';
            if (count($matches) > count($items)) {
                $reply .= ' Showing ' . count($items) . ' of ' . count($matches) . ' matched products.';
            }

            return $reply;
        }
    }

    if (str_contains($queryLower, 'best seller') || str_contains($queryLower, 'selling the most') || str_contains($queryLower, 'top seller')) {
        $best = $products[0] ?? null;
        if ($best === null) {
            return 'There is no best seller yet because there are no active products.';
        }

        return sprintf(
            'Your best seller is %s (%s) with %d units sold.',
            (string) ($best['product_name'] ?? 'Unnamed Product'),
            (string) ($best['sku'] ?? 'N/A'),
            (int) ($best['units_sold'] ?? 0)
        );
    }

    if (str_contains($queryLower, 'least purchased') || str_contains($queryLower, 'selling the least') || str_contains($queryLower, 'least seller')) {
        $least = $products[array_key_last($products)] ?? null;
        if ($least === null) {
            return 'There is no least-purchased product yet because there are no active products.';
        }

        return sprintf(
            'Your least purchased product is %s (%s) with %d units sold.',
            (string) ($least['product_name'] ?? 'Unnamed Product'),
            (string) ($least['sku'] ?? 'N/A'),
            (int) ($least['units_sold'] ?? 0)
        );
    }

    if (str_contains($queryLower, 'restock') || str_contains($queryLower, 'low stock') || str_contains($queryLower, 'reorder')) {
        $matches = array_values(array_filter($products, static function (array $product): bool {
            return (int) ($product['stock_on_hand'] ?? 0) <= (int) ($product['reorder_point'] ?? 0);
        }));

        if ($matches === []) {
            return 'No active products currently need restocking.';
        }

        usort($matches, static function (array $a, array $b): int {
            $gapA = (int) ($a['reorder_point'] ?? 0) - (int) ($a['stock_on_hand'] ?? 0);
            $gapB = (int) ($b['reorder_point'] ?? 0) - (int) ($b['stock_on_hand'] ?? 0);
            return $gapB <=> $gapA;
        });

        $top = array_slice($matches, 0, 3);
        $parts = array_map(static function (array $product): string {
            return sprintf(
                '%s (%s): stock %d, reorder %d',
                (string) ($product['product_name'] ?? 'Unnamed Product'),
                (string) ($product['sku'] ?? 'N/A'),
                (int) ($product['stock_on_hand'] ?? 0),
                (int) ($product['reorder_point'] ?? 0)
            );
        }, $top);

        return 'Products to restock first: ' . implode('; ', $parts) . '.';
    }

    if (str_contains($queryLower, 'out of stock')) {
        $matches = array_values(array_filter($products, static fn(array $product): bool => (int) ($product['stock_on_hand'] ?? 0) === 0));
        if ($matches === []) {
            return 'No active products are out of stock.';
        }

        $parts = array_map(static function (array $product): string {
            return sprintf('%s (%s)', (string) ($product['product_name'] ?? 'Unnamed Product'), (string) ($product['sku'] ?? 'N/A'));
        }, array_slice($matches, 0, 5));

        return 'Out of stock items: ' . implode(', ', $parts) . '.';
    }

    if (str_contains($queryLower, 'summary') || str_contains($queryLower, 'inventory status')) {
        $totalStock = 0;
        $lowStockCount = 0;
        $outOfStockCount = 0;

        foreach ($products as $product) {
            $stock = (int) ($product['stock_on_hand'] ?? 0);
            $reorder = (int) ($product['reorder_point'] ?? 0);
            $totalStock += $stock;

            if ($stock === 0) {
                $outOfStockCount++;
            } elseif ($stock < $reorder) {
                $lowStockCount++;
            }
        }

        return sprintf(
            'You have %d active products, %d total units in stock, %d low-stock items, and %d out-of-stock items.',
            count($products),
            $totalStock,
            $lowStockCount,
            $outOfStockCount
        );
    }

    if ((str_contains($queryLower, 'how many') || str_contains($queryLower, 'total stock')) && (str_contains($queryLower, 'type') || str_contains($queryLower, 'accessor') || str_contains($queryLower, 'apparel') || str_contains($queryLower, 'footwear'))) {
        $typeMatches = [];
        foreach ($products as $product) {
            $type = trim((string) ($product['product_type'] ?? ''));
            if ($type === '') {
                continue;
            }

            $typeLower = strtolower($type);
            foreach ($tokens as $token) {
                if (str_contains($typeLower, $token) || str_contains($token, $typeLower)) {
                    $typeMatches[$typeLower] = $type;
                }
            }
        }

        if ($typeMatches !== []) {
            $selectedType = array_values($typeMatches)[0];
            $totalStock = 0;
            $matchedProducts = [];
            foreach ($products as $product) {
                if (strcasecmp((string) ($product['product_type'] ?? ''), $selectedType) === 0) {
                    $totalStock += (int) ($product['stock_on_hand'] ?? 0);
                    $matchedProducts[] = $product;
                }
            }

            return sprintf(
                'Your %s products have %d total units in stock across %d item%s.',
                $selectedType,
                $totalStock,
                count($matchedProducts),
                count($matchedProducts) === 1 ? '' : 's'
            );
        }
    }

    return null;
}

function adminlens_build_inventory_fallback_reply(array $products, string $query): string
{
    $queryLower = strtolower(trim($query));

    if ($products === []) {
        return 'I could not reach the AI model right now, but your active inventory is currently empty.';
    }

    if (str_contains($queryLower, 'list') || str_contains($queryLower, 'all')) {
        $items = array_slice($products, 0, 10);
        $parts = array_map(static function (array $product): string {
            return sprintf(
                '%s (%s)',
                (string) ($product['product_name'] ?? 'Unnamed Product'),
                (string) ($product['sku'] ?? 'N/A')
            );
        }, $items);

        $reply = 'I could not reach the AI model right now, but here are active inventory items from the database: ' . implode(', ', $parts) . '.';
        if (count($products) > count($items)) {
            $reply .= ' There are ' . count($products) . ' active products in total.';
        }

        return $reply;
    }

    $best = $products[0] ?? null;
    $lowStockCount = 0;
    $outOfStockCount = 0;
    foreach ($products as $product) {
        $stock = (int) ($product['stock_on_hand'] ?? 0);
        $reorder = (int) ($product['reorder_point'] ?? 0);
        if ($stock === 0) {
            $outOfStockCount++;
        } elseif ($stock < $reorder) {
            $lowStockCount++;
        }
    }

    return sprintf(
        'I could not reach the AI model right now, but the database shows %d active products, %d low-stock items, %d out-of-stock items, and the best seller is %s (%s).',
        count($products),
        $lowStockCount,
        $outOfStockCount,
        (string) ($best['product_name'] ?? 'N/A'),
        (string) ($best['sku'] ?? 'N/A')
    );
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

