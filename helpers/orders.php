<?php

require_once __DIR__ . '/../config/db.php';

function get_pending_orders_count(): int
{
    try {
        $pdo = require __DIR__ . '/../config/db.php';
        $stmt = $pdo->query(
            "SELECT COUNT(*)
             FROM customer_orders
             WHERE LOWER(COALESCE(status, '')) IN ('pending', 'awaiting confirmation', 'awaiting-confirmation')"
        );

        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}
