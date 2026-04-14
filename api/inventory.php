<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/status.php';
require_once __DIR__ . '/../helpers/data.php';

try {
    echo json_encode(get_all_products());
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Unable to fetch inventory data.']);
}
