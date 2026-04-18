<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../config/constants.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

if (AI_PROVIDER !== 'ollama') {
    echo json_encode(['warmed' => false, 'provider' => AI_PROVIDER]);
    exit;
}

$payload = [
    'model' => OLLAMA_MODEL,
    'stream' => false,
    'keep_alive' => OLLAMA_KEEP_ALIVE,
    'options' => [
        'temperature' => 0,
        'num_predict' => 1,
        'num_ctx' => min(OLLAMA_NUM_CTX, 1024),
    ],
    'messages' => [
        ['role' => 'system', 'content' => 'Reply with OK.'],
        ['role' => 'user', 'content' => 'OK'],
    ],
];

$ch = curl_init(rtrim(OLLAMA_URL, '/') . '/api/chat');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_CONNECTTIMEOUT => 2,
    CURLOPT_TIMEOUT => 12,
]);

$response = curl_exec($ch);
$error = curl_error($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false || $error !== '' || $httpCode !== 200) {
    http_response_code(200);
    echo json_encode([
        'warmed' => false,
        'error' => $error !== '' ? $error : ('Ollama HTTP ' . $httpCode),
    ]);
    exit;
}

echo json_encode(['warmed' => true]);
