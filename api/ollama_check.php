<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/constants.php';

$result = [];

$ch = curl_init(OLLAMA_URL . '/api/tags');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 10,
]);
$resp = curl_exec($ch);
$http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_err = curl_error($ch);
curl_close($ch);

if ($curl_err !== '') {
    $result['connection'] = 'FAIL — ' . $curl_err;
    $result['fix'] = 'Run: ollama serve';
    echo json_encode($result, JSON_PRETTY_PRINT);
    exit;
}

$result['connection'] = 'OK (HTTP ' . $http . ')';
$tags = json_decode((string) $resp, true);
$result['available_models'] = array_column($tags['models'] ?? [], 'name');

$configured = OLLAMA_MODEL;
$loaded = $result['available_models'];
$result['configured_model'] = $configured;
$result['model_available'] = in_array($configured, $loaded, true)
    ? 'YES'
    : 'NO — run: ollama pull ' . $configured;

$start = microtime(true);
$ch = curl_init(OLLAMA_URL . '/api/chat');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode([
        'model' => OLLAMA_MODEL,
        'stream' => false,
        'options' => ['num_predict' => 10],
        'messages' => [['role' => 'user', 'content' => 'Say OK']],
    ]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 120,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Expect:'],
]);
$test_resp = curl_exec($ch);
$test_http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$test_err = curl_error($ch);
curl_close($ch);
$elapsed = round(microtime(true) - $start, 2);

if ($test_err !== '') {
    $result['speed_test'] = 'FAIL — ' . $test_err;
} elseif ($test_http === 200) {
    $test_data = json_decode((string) $test_resp, true);
    $result['speed_test'] = 'OK — responded in ' . $elapsed . 's';
    $result['test_response'] = $test_data['message']['content'] ?? '(empty)';
} else {
    $result['speed_test'] = 'HTTP ' . $test_http . ' in ' . $elapsed . 's';
}

$result['php_timeout'] = ini_get('max_execution_time') . 's';
$result['recommendation'] = $elapsed > 30
    ? 'Too slow! Switch to tinyllama: ollama pull tinyllama'
    : 'Speed is acceptable.';

echo json_encode($result, JSON_PRETTY_PRINT);
