<?php

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/data.php';

function adminlens_chart_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function adminlens_chart_palette(): array
{
    return [
        '#2563eb',
        '#0f766e',
        '#c2410c',
        '#b91c1c',
        '#7c3aed',
        '#ca8a04',
        '#db2777',
        '#15803d',
        '#0369a1',
        '#4f46e5',
    ];
}

function adminlens_named_product_colors(): array
{
    return [
        'black' => '#111827',
        'white' => '#e5e7eb',
        'blue' => '#2563eb',
        'navy' => '#1e3a8a',
        'teal' => '#0f766e',
        'green' => '#16a34a',
        'yellow' => '#eab308',
        'gold' => '#ca8a04',
        'orange' => '#ea580c',
        'red' => '#dc2626',
        'pink' => '#ec4899',
        'purple' => '#7c3aed',
        'violet' => '#8b5cf6',
        'brown' => '#92400e',
        'beige' => '#d6b88d',
        'gray' => '#6b7280',
        'grey' => '#6b7280',
        'silver' => '#94a3b8',
        'titanium' => '#94a3b8',
        'rose' => '#f43f5e',
    ];
}

function adminlens_chart_color_from_name(string $name): ?string
{
    foreach (adminlens_named_product_colors() as $token => $hex) {
        if (stripos($name, $token) !== false) {
            return $hex;
        }
    }

    return null;
}

function adminlens_chart_fallback_color(string $key): string
{
    $palette = adminlens_chart_palette();
    $hash = abs((int) sprintf('%u', crc32($key)));
    return $palette[$hash % count($palette)];
}

function adminlens_chart_product_color(array $product, int $index = 0): string
{
    $directColor = trim((string) ($product['color_hex'] ?? $product['product_color'] ?? $product['color'] ?? ''));
    if ($directColor !== '') {
        if (preg_match('/^#?[0-9a-fA-F]{6}$/', $directColor) === 1) {
            return str_starts_with($directColor, '#') ? $directColor : '#' . $directColor;
        }

        $mapped = adminlens_chart_color_from_name($directColor);
        if ($mapped !== null) {
            return $mapped;
        }
    }

    $nameColor = adminlens_chart_color_from_name((string) ($product['product_name'] ?? ''));
    if ($nameColor !== null) {
        return $nameColor;
    }

    $key = (string) ($product['sku'] ?? $product['product_name'] ?? (string) $index);
    return adminlens_chart_fallback_color($key);
}

function adminlens_chart_currency(float $value, int $decimals = 0): string
{
    return '&#8369;' . number_format($value, $decimals);
}

function adminlens_chart_shorten(string $value, int $max = 18): string
{
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($value) <= $max ? $value : mb_substr($value, 0, max(0, $max - 3)) . '...';
    }

    return strlen($value) <= $max ? $value : substr($value, 0, max(0, $max - 3)) . '...';
}

function adminlens_normalise_product(array $product): array
{
    $normalised = [
        'sku' => (string) ($product['sku'] ?? ''),
        'product_name' => (string) ($product['product_name'] ?? $product['name'] ?? 'Unnamed'),
        'units_sold' => (int) ($product['units_sold'] ?? $product['sold'] ?? 0),
        'stock_on_hand' => (int) ($product['stock_on_hand'] ?? $product['stock'] ?? 0),
        'reorder_point' => (int) ($product['reorder_point'] ?? 0),
        'price' => (float) ($product['price'] ?? 0),
    ];

    $normalised['color_hex'] = adminlens_chart_product_color($product);
    return $normalised;
}

function adminlens_products_payload(array $products, string $chart_type): array
{
    return [
        'chart_type' => $chart_type,
        'products' => array_map('adminlens_normalise_product', $products),
    ];
}

function adminlens_detect_rank(array $product, array $all_products): string
{
    if ($all_products === []) {
        return 'normal';
    }

    $sales = array_map(static fn(array $item): int => (int) ($item['units_sold'] ?? $item['sold'] ?? 0), $all_products);
    $sold = (int) ($product['units_sold'] ?? $product['sold'] ?? 0);

    if ($sold === max($sales)) {
        return 'best';
    }

    if ($sold === min($sales)) {
        return 'least';
    }

    return 'normal';
}

function adminlens_chart_ranking_items(array $products): array
{
    usort($products, static fn(array $a, array $b): int => ((int) $b['units_sold']) <=> ((int) $a['units_sold']));
    return array_values($products);
}

function adminlens_chart_stock_items(array $products): array
{
    return array_values($products);
}

function adminlens_chart_value_items(array $products): array
{
    usort($products, static function (array $a, array $b): int {
        $aValue = ((float) $a['price']) * ((int) $a['stock_on_hand']);
        $bValue = ((float) $b['price']) * ((int) $b['stock_on_hand']);
        return $bValue <=> $aValue;
    });

    return array_values($products);
}

function adminlens_render_product_chart(array $product, array $all_products = []): string
{
    $item = adminlens_normalise_product($product);
    $rank = $product['rank'] ?? adminlens_detect_rank($product, $all_products ?: [$product]);
    $name = adminlens_chart_escape($item['product_name']);
    $sku = adminlens_chart_escape($item['sku']);
    $sold = (int) $item['units_sold'];
    $stock = (int) $item['stock_on_hand'];
    $reorder = (int) $item['reorder_point'];
    $baseColor = $item['color_hex'];

    if ($rank === 'best') {
        $baseColor = '#16a34a';
    } elseif ($rank === 'least') {
        $baseColor = '#dc2626';
    }

    $max = max(1, $sold, $stock, $reorder);
    $soldWidth = max(14, ($sold / $max) * 520);
    $stockWidth = max(14, ($stock / $max) * 520);
    $markerX = ($reorder / $max) * 520;

    return
        '<div class="product-mini-chart">' .
            '<div class="product-mini-chart__title">' . $name . '</div>' .
            '<div class="product-mini-chart__meta">SKU ' . $sku . '</div>' .
            '<svg viewBox="0 0 560 110" xmlns="http://www.w3.org/2000/svg" style="width:100%;display:block">' .
                '<text x="0" y="14" font-size="10" fill="#64748b">Units sold</text>' .
                '<rect x="0" y="20" width="' . number_format($soldWidth, 1, '.', '') . '" height="20" rx="10" fill="' . $baseColor . '"/>' .
                '<text x="' . number_format($soldWidth + 8, 1, '.', '') . '" y="33" font-size="11" font-weight="700" fill="' . $baseColor . '">' . number_format($sold) . '</text>' .
                '<text x="0" y="60" font-size="10" fill="#64748b">Stock on hand</text>' .
                '<rect x="0" y="66" width="' . number_format($stockWidth, 1, '.', '') . '" height="20" rx="10" fill="#60a5fa"/>' .
                '<text x="' . number_format($stockWidth + 8, 1, '.', '') . '" y="79" font-size="11" font-weight="700" fill="#2563eb">' . number_format($stock) . '</text>' .
                '<line x1="' . number_format($markerX, 1, '.', '') . '" y1="12" x2="' . number_format($markerX, 1, '.', '') . '" y2="96" stroke="#ef4444" stroke-width="2" stroke-dasharray="4,3"/>' .
                '<text x="' . number_format($markerX, 1, '.', '') . '" y="106" font-size="10" fill="#ef4444" text-anchor="middle">Reorder ' . number_format($reorder) . '</text>' .
            '</svg>' .
        '</div>';
}

function adminlens_chart_legend_rows(array $items): string
{
    if ($items === []) {
        return '<div class="chart-empty">No products to display.</div>';
    }

    $rows = '<div class="line-legend">';
    foreach ($items as $item) {
        $inventoryValue = ((float) $item['price']) * ((int) $item['stock_on_hand']);
        $rows .=
            '<div class="line-legend__item">' .
                '<div class="line-legend__body">' .
                    '<div class="line-legend__head">' .
                        '<span class="line-legend__swatch" style="background:' . adminlens_chart_escape($item['color_hex']) . '"></span>' .
                        '<div class="line-legend__name">' . adminlens_chart_escape($item['product_name']) . '</div>' .
                    '</div>' .
                    '<div class="line-legend__meta">' .
                        adminlens_chart_escape($item['sku']) . ' &middot; ' .
                        number_format((int) $item['units_sold']) . ' sold &middot; ' .
                        number_format((int) $item['stock_on_hand']) . ' in stock &middot; ' .
                        adminlens_chart_currency($inventoryValue, 0) . ' total value' .
                    '</div>' .
                '</div>' .
            '</div>';
    }
    $rows .= '</div>';

    return $rows;
}

function adminlens_render_ranking_svg(array $items): string
{
    if ($items === []) {
        return '<div class="chart-empty">No products to display.</div>';
    }

    $top = max(1, ...array_map(static fn(array $item): int => (int) $item['units_sold'], $items));
    $rowHeight = 56;
    $left = 220;
    $barWidth = 620;
    $padTop = 24;
    $padBottom = 40;
    $count = count($items);
    $svgHeight = $padTop + ($count * $rowHeight) + $padBottom;
    $svgWidth = $left + $barWidth + 70;

    $grid = '';
    for ($step = 0; $step <= 5; $step++) {
        $x = $left + (($step / 5) * $barWidth);
        $value = (int) round(($step / 5) * $top);
        $grid .=
            '<line x1="' . number_format($x, 1, '.', '') . '" y1="' . $padTop . '" x2="' . number_format($x, 1, '.', '') . '" y2="' . ($padTop + ($count * $rowHeight)) . '" stroke="#e2e8f0" stroke-width="1"/>' .
            '<text x="' . number_format($x, 1, '.', '') . '" y="' . ($padTop + ($count * $rowHeight) + 18) . '" font-size="12" fill="#64748b" text-anchor="middle">' . number_format($value) . '</text>';
    }

    $bars = '';
    foreach ($items as $index => $item) {
        $value = (int) $item['units_sold'];
        $bar = max(4, ($value / $top) * $barWidth);
        $y = $padTop + ($index * $rowHeight);
        $mid = $y + (int) ($rowHeight / 2);
        $bars .=
            '<text x="' . ($left - 12) . '" y="' . ($mid + 5) . '" font-size="16" font-weight="700" fill="#1e293b" text-anchor="end">' . adminlens_chart_escape(adminlens_chart_shorten($item['product_name'], 20)) . '</text>' .
            '<rect x="' . $left . '" y="' . ($y + 10) . '" width="' . number_format($bar, 1, '.', '') . '" height="' . ($rowHeight - 20) . '" rx="6" fill="' . adminlens_chart_escape($item['color_hex']) . '"/>' .
            '<text x="' . number_format($left + $bar + 10, 1, '.', '') . '" y="' . ($mid + 5) . '" font-size="14" font-weight="700" fill="' . adminlens_chart_escape($item['color_hex']) . '">' . number_format($value) . '</text>';
    }

    return
        '<svg class="chart-svg chart-svg--ranking" viewBox="0 0 ' . $svgWidth . ' ' . $svgHeight . '" xmlns="http://www.w3.org/2000/svg" style="width:100%;display:block;min-width:700px">' .
            $grid .
            $bars .
            '<line x1="' . $left . '" y1="' . $padTop . '" x2="' . $left . '" y2="' . ($padTop + ($count * $rowHeight)) . '" stroke="#94a3b8" stroke-width="1"/>' .
        '</svg>';
}

function adminlens_chart_describe_arc(float $cx, float $cy, float $outerRadius, float $innerRadius, float $startDeg, float $endDeg): string
{
    $startOuterX = $cx + ($outerRadius * cos(deg2rad($startDeg - 90)));
    $startOuterY = $cy + ($outerRadius * sin(deg2rad($startDeg - 90)));
    $endOuterX = $cx + ($outerRadius * cos(deg2rad($endDeg - 90)));
    $endOuterY = $cy + ($outerRadius * sin(deg2rad($endDeg - 90)));
    $endInnerX = $cx + ($innerRadius * cos(deg2rad($endDeg - 90)));
    $endInnerY = $cy + ($innerRadius * sin(deg2rad($endDeg - 90)));
    $startInnerX = $cx + ($innerRadius * cos(deg2rad($startDeg - 90)));
    $startInnerY = $cy + ($innerRadius * sin(deg2rad($startDeg - 90)));
    $largeArc = ($endDeg - $startDeg) > 180 ? 1 : 0;

    return sprintf(
        'M %.2f,%.2f A %.2f,%.2f 0 %d,1 %.2f,%.2f L %.2f,%.2f A %.2f,%.2f 0 %d,0 %.2f,%.2f Z',
        $startOuterX,
        $startOuterY,
        $outerRadius,
        $outerRadius,
        $largeArc,
        $endOuterX,
        $endOuterY,
        $endInnerX,
        $endInnerY,
        $innerRadius,
        $innerRadius,
        $largeArc,
        $startInnerX,
        $startInnerY
    );
}

function adminlens_render_stock_svg(array $items): string
{
    $total = array_sum(array_map(static fn(array $item): int => max(0, (int) $item['stock_on_hand']), $items));
    if ($total <= 0) {
        return '<div class="chart-empty">No stock data to display.</div>';
    }

    $cx = 300;
    $cy = 290;
    $outerRadius = 220;
    $innerRadius = 120;
    $svgHeight = 640;
    $svgWidth = 720;

    $paths = '';
    $labels = '';
    $offset = 0.0;
    foreach ($items as $item) {
        $stock = max(0, (int) $item['stock_on_hand']);
        if ($stock === 0) {
            continue;
        }

        $degrees = ($stock / $total) * 360;
        $end = min($offset + $degrees, 359.999);
        $mid = $offset + ($degrees / 2);
        $midRad = deg2rad($mid - 90);
        $lineStartX = $cx + (($outerRadius + 4) * cos($midRad));
        $lineStartY = $cy + (($outerRadius + 4) * sin($midRad));
        $lineEndX = $cx + (($outerRadius + 28) * cos($midRad));
        $lineEndY = $cy + (($outerRadius + 28) * sin($midRad));
        $labelX = $cx + (($outerRadius + 52) * cos($midRad));
        $labelY = $cy + (($outerRadius + 52) * sin($midRad));
        $textAnchor = $labelX >= $cx ? 'start' : 'end';
        $shortName = adminlens_chart_escape(adminlens_chart_shorten($item['product_name'], 12));
        $paths .= '<path d="' . adminlens_chart_describe_arc($cx, $cy, $outerRadius, $innerRadius, $offset, $end) . '" fill="' . adminlens_chart_escape($item['color_hex']) . '" stroke="#ffffff" stroke-width="2"></path>';
        $labels .=
            '<line x1="' . number_format($lineStartX, 1, '.', '') . '" y1="' . number_format($lineStartY, 1, '.', '') . '" x2="' . number_format($lineEndX, 1, '.', '') . '" y2="' . number_format($lineEndY, 1, '.', '') . '" stroke="' . adminlens_chart_escape($item['color_hex']) . '" stroke-width="1.5"></line>' .
            '<text x="' . number_format($labelX, 1, '.', '') . '" y="' . number_format($labelY, 1, '.', '') . '" font-size="12" font-weight="700" fill="#0f172a" text-anchor="' . $textAnchor . '">' . $shortName . '</text>' .
            '<text x="' . number_format($labelX, 1, '.', '') . '" y="' . number_format($labelY + 15, 1, '.', '') . '" font-size="11" fill="#64748b" text-anchor="' . $textAnchor . '">' . number_format($stock) . ' units</text>';
        $offset += $degrees;
    }

    return
        '<div class="pie-chart-wrap">' .
            '<svg class="chart-svg chart-svg--pie" viewBox="0 0 ' . $svgWidth . ' ' . $svgHeight . '" xmlns="http://www.w3.org/2000/svg" style="display:block;margin:0 auto;width:100%;height:100%">' .
                $paths .
                $labels .
                '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . ($innerRadius - 2) . '" fill="#ffffff"></circle>' .
                '<text x="' . $cx . '" y="' . ($cy - 10) . '" font-size="34" font-weight="700" fill="#0f172a" text-anchor="middle">' . number_format($total) . '</text>' .
                '<text x="' . $cx . '" y="' . ($cy + 18) . '" font-size="14" fill="#64748b" text-anchor="middle">total units</text>' .
            '</svg>' .
        '</div>';
}

function adminlens_render_value_svg(array $items): string
{
    if ($items === []) {
        return '<div class="chart-empty">No products to display.</div>';
    }

    $values = array_map(static fn(array $item): float => ((float) $item['price']) * ((int) $item['stock_on_hand']), $items);
    $max = max(1, ...$values);
    $count = count($items);
    $padLeft = 96;
    $padRight = 40;
    $padTop = 26;
    $padBottom = 86;
    $width = 840;
    $height = 340;
    $plotWidth = $width - $padLeft - $padRight;
    $plotHeight = $height - $padTop - $padBottom;

    $getX = static function (int $index) use ($count, $padLeft, $plotWidth): float {
        return $count === 1 ? $padLeft : $padLeft + (($index / ($count - 1)) * $plotWidth);
    };

    $getY = static function (float $value) use ($max, $padTop, $plotHeight): float {
        return $padTop + $plotHeight - (($value / $max) * $plotHeight);
    };

    $points = [];
    foreach ($values as $index => $value) {
        $points[] = [$getX($index), $getY($value)];
    }

    $polyline = implode(' ', array_map(static fn(array $point): string => number_format($point[0], 1, '.', '') . ',' . number_format($point[1], 1, '.', ''), $points));
    $area = $polyline . ' ' .
        number_format($points[array_key_last($points)][0], 1, '.', '') . ',' . ($padTop + $plotHeight) . ' ' .
        number_format($points[0][0], 1, '.', '') . ',' . ($padTop + $plotHeight);

    $grid = '';
    for ($step = 1; $step <= 4; $step++) {
        $y = $padTop + $plotHeight - (($step / 4) * $plotHeight);
        $value = ($step / 4) * $max;
        $label = $value >= 1000 ? adminlens_chart_currency($value / 1000, 1) . 'K' : adminlens_chart_currency($value, 0);
        $grid .=
            '<line x1="' . $padLeft . '" y1="' . number_format($y, 1, '.', '') . '" x2="' . ($width - $padRight) . '" y2="' . number_format($y, 1, '.', '') . '" stroke="#e2e8f0" stroke-width="1"/>' .
            '<text x="' . ($padLeft - 8) . '" y="' . number_format($y + 5, 1, '.', '') . '" font-size="12" fill="#64748b" text-anchor="end">' . $label . '</text>';
    }

    $dots = '';
    foreach ($items as $index => $item) {
        $point = $points[$index];
        $value = $values[$index];
        $label = $value >= 1000 ? adminlens_chart_currency($value / 1000, 1) . 'K' : adminlens_chart_currency($value, 0);
        $name = adminlens_chart_escape(adminlens_chart_shorten($item['product_name'], 12));
        $color = adminlens_chart_escape($item['color_hex']);
        $dots .=
            '<circle cx="' . number_format($point[0], 1, '.', '') . '" cy="' . number_format($point[1], 1, '.', '') . '" r="6" fill="' . $color . '" stroke="#ffffff" stroke-width="2"></circle>' .
            '<text x="' . number_format($point[0], 1, '.', '') . '" y="' . number_format(max($padTop + 14, $point[1] - 10), 1, '.', '') . '" font-size="11" font-weight="700" fill="' . $color . '" text-anchor="middle">' . $label . '</text>' .
            '<text x="' . number_format($point[0], 1, '.', '') . '" y="' . ($padTop + $plotHeight + 30) . '" font-size="11" fill="#334155" text-anchor="end" transform="rotate(-32 ' . number_format($point[0], 1, '.', '') . ',' . ($padTop + $plotHeight + 30) . ')">' . $name . '</text>';
    }

    return
        '<svg class="chart-svg chart-svg--value" viewBox="0 0 ' . $width . ' ' . $height . '" xmlns="http://www.w3.org/2000/svg" style="width:100%;display:block;min-width:760px">' .
            '<defs>' .
                '<linearGradient id="valueAreaGradient" x1="0" y1="0" x2="0" y2="1">' .
                    '<stop offset="0%" stop-color="#2563eb" stop-opacity="0.22"></stop>' .
                    '<stop offset="100%" stop-color="#2563eb" stop-opacity="0.03"></stop>' .
                '</linearGradient>' .
            '</defs>' .
            $grid .
            '<line x1="' . $padLeft . '" y1="' . $padTop . '" x2="' . $padLeft . '" y2="' . ($padTop + $plotHeight) . '" stroke="#94a3b8" stroke-width="1"></line>' .
            '<line x1="' . $padLeft . '" y1="' . ($padTop + $plotHeight) . '" x2="' . ($width - $padRight) . '" y2="' . ($padTop + $plotHeight) . '" stroke="#94a3b8" stroke-width="1"></line>' .
            '<polygon points="' . $area . '" fill="url(#valueAreaGradient)"></polygon>' .
            '<polyline points="' . $polyline . '" fill="none" stroke="#1d4ed8" stroke-width="3" stroke-linejoin="round" stroke-linecap="round"></polyline>' .
            $dots .
        '</svg>';
}

function adminlens_render_split_chart(string $body, string $legend): string
{
    return
        '<div class="chart-output chart-output--single">' .
            '<div class="chart-stage chart-stage--split">' .
                '<div class="chart-pane">' .
                    '<div class="chart-pane__card chart-pane__card--chart">' .
                        '<div class="chart-stage__main">' .
                            '<div class="chart-scroll">' . $body . '</div>' .
                        '</div>' .
                    '</div>' .
                    '<div class="chart-pane__card chart-pane__card--legend">' .
                        '<div class="chart-legend-shell chart-legend-shell--side">' . $legend . '</div>' .
                    '</div>' .
                '</div>' .
            '</div>' .
        '</div>';
}

function adminlens_render_ranking_chart(array $products): string
{
    $items = adminlens_chart_ranking_items(array_map('adminlens_normalise_product', $products));
    return adminlens_render_split_chart(adminlens_render_ranking_svg($items), adminlens_chart_legend_rows($items));
}

function adminlens_render_stock_chart(array $products): string
{
    $items = adminlens_chart_stock_items(array_map('adminlens_normalise_product', $products));
    return adminlens_render_split_chart(adminlens_render_stock_svg($items), adminlens_chart_legend_rows($items));
}

function adminlens_render_value_chart(array $products): string
{
    $items = adminlens_chart_value_items(array_map('adminlens_normalise_product', $products));
    return adminlens_render_split_chart(adminlens_render_value_svg($items), adminlens_chart_legend_rows($items));
}

function adminlens_render_dashboard(array $products): string
{
    if ($products === []) {
        return '<div class="chart-empty">No products to display.</div>';
    }

    $ranking = adminlens_render_ranking_chart($products);
    $stock = adminlens_render_stock_chart($products);
    $value = adminlens_render_value_chart($products);

    return
        '<div class="chart-output chart-output--dashboard">' .
            '<div class="chart-tabs">' .
                '<button type="button" class="chart-switcher__btn is-active" data-chart-target="dashboard-ranking">Sales ranking</button>' .
                '<button type="button" class="chart-switcher__btn" data-chart-target="dashboard-stock">Stock share</button>' .
                '<button type="button" class="chart-switcher__btn" data-chart-target="dashboard-value">Inventory value</button>' .
            '</div>' .
            '<div id="dashboard-ranking" class="chart-panel is-active">' . $ranking . '</div>' .
            '<div id="dashboard-stock" class="chart-panel" hidden>' . $stock . '</div>' .
            '<div id="dashboard-value" class="chart-panel" hidden>' . $value . '</div>' .
        '</div>';
}

function adminlens_render_chart(array $payload): string
{
    $chartType = (string) ($payload['chart_type'] ?? 'product');

    if ($chartType === 'product') {
        return adminlens_render_product_chart($payload);
    }

    $products = array_map('adminlens_normalise_product', $payload['products'] ?? []);
    if ($products === []) {
        return '<div class="chart-empty">No products to display.</div>';
    }

    return match ($chartType) {
        'ranking' => adminlens_render_split_chart(
            adminlens_render_ranking_svg(adminlens_chart_ranking_items($products)),
            adminlens_chart_legend_rows(adminlens_chart_ranking_items($products))
        ),
        'stock' => adminlens_render_split_chart(
            adminlens_render_stock_svg(adminlens_chart_stock_items($products)),
            adminlens_chart_legend_rows(adminlens_chart_stock_items($products))
        ),
        'value' => adminlens_render_split_chart(
            adminlens_render_value_svg(adminlens_chart_value_items($products)),
            adminlens_chart_legend_rows(adminlens_chart_value_items($products))
        ),
        'dashboard' => adminlens_render_dashboard($products),
        default => '<div class="chart-empty">Unknown chart type.</div>',
    };
}

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

.product-mini-chart {
    padding: 6px 2px;
}

.product-mini-chart__title {
    font-size: 1rem;
    font-weight: 700;
    color: #0f172a;
}

.product-mini-chart__meta {
    margin-bottom: 14px;
    font-size: 0.82rem;
    color: #64748b;
}
</style>
CSS;
}

function generate_chart_for_product(array $product, string $rank): void
{
    // Charts are rendered inline.
}

function generate_all_charts(): void
{
    // Charts are rendered inline.
}
