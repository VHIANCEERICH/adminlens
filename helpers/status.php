<?php

function get_status(array $product): string
{
    if ((int) ($product['stock_on_hand'] ?? 0) === 0) {
        return 'OUT OF STOCK';
    }

    if ((int) ($product['stock_on_hand'] ?? 0) < (int) ($product['reorder_point'] ?? 0)) {
        return 'LOW STOCK';
    }

    return 'OK';
}

function get_badge_class(string $status): string
{
    return match ($status) {
        'OUT OF STOCK' => 'badge-out',
        'LOW STOCK' => 'badge-low',
        default => 'badge-ok',
    };
}
