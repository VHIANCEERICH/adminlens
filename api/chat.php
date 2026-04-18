<?php
set_time_limit(180);
ini_set('max_execution_time', '180');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../helpers/data.php';
require_once __DIR__ . '/../helpers/orders.php';
require_once __DIR__ . '/../helpers/status.php';

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
$user_message = trim((string) ($input['message'] ?? ''));

if ($user_message === '') {
    echo json_encode(['error' => 'Message cannot be empty.']);
    exit;
}

try {
    $all_products = get_all_products();
    $best_seller = get_best_seller();
    $least_sold = get_least_sold();
    $low_stock = get_low_stock();
    $out_of_stock = get_out_of_stock();
    $pending_count = get_pending_orders_count();
} catch (Exception $e) {
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    exit;
}

$fast_reply = adminlens_try_fast_inventory_answer($all_products, $user_message);
if ($fast_reply !== null) {
    echo json_encode([
        'reply' => $fast_reply,
        'model' => 'database-fast-path',
        'context' => [
            'best_seller' => (string) ($best_seller['product_name'] ?? ''),
            'least_purchased' => (string) ($least_sold['product_name'] ?? ''),
            'low_stock_count' => count($low_stock),
            'out_of_stock_count' => count($out_of_stock),
            'pending_orders' => $pending_count,
            'total_products' => count($all_products),
        ],
    ]);
    exit;
}

$low_text = empty($low_stock)
    ? 'None — all items are sufficiently stocked.'
    : implode('; ', array_map(static function (array $p): string {
        return (string) ($p['product_name'] ?? 'Unnamed Product')
            . ' (stock:' . (int) ($p['stock_on_hand'] ?? 0)
            . ', reorder:' . (int) ($p['reorder_point'] ?? 0) . ')';
    }, $low_stock));

$out_text = empty($out_of_stock)
    ? 'None.'
    : implode(', ', array_map(static fn(array $p): string => (string) ($p['product_name'] ?? 'Unnamed Product'), $out_of_stock));

$system_prompt = "You are AdminLens AI, inventory assistant for a retail boutique.\n"
    . "Answer using ONLY the data below. Be concise (1-3 sentences max).\n"
    . "Never guess. If a product does not exist in the list, say so.\n"
    . "Currency is Philippine Peso (PHP / ₱).\n\n"
    . "=== TOTAL ACTIVE PRODUCTS === " . count($all_products) . "\n"
    . "=== BEST SELLER === " . (string) ($best_seller['product_name'] ?? 'N/A')
    . ' (' . (int) ($best_seller['units_sold'] ?? 0) . " units sold)\n"
    . "=== LEAST PURCHASED === " . (string) ($least_sold['product_name'] ?? 'N/A')
    . ' (' . (int) ($least_sold['units_sold'] ?? 0) . " units sold)\n"
    . "=== LOW STOCK === " . $low_text . "\n"
    . "=== OUT OF STOCK === " . $out_text . "\n"
    . "=== PENDING ORDERS === " . $pending_count . "\n"
    . "\n=== RELEVANT ITEMS ===\n"
    . adminlens_build_targeted_inventory_context($all_products, $user_message, 8) . "\n";

function call_ollama(string $system_prompt, string $user_message): string
{
    $url = OLLAMA_URL . '/api/chat';
    $body = json_encode([
        'model' => OLLAMA_MODEL,
        'stream' => false,
        'options' => [
            'temperature' => 0.1,
            'num_predict' => 150,
            'top_p' => 0.9,
        ],
        'messages' => [
            ['role' => 'system', 'content' => $system_prompt],
            ['role' => 'user', 'content' => $user_message],
        ],
    ]);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => OLLAMA_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT => OLLAMA_RESPONSE_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Expect:',
        ],
    ]);

    $response = curl_exec($ch);
    $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err = curl_error($ch);
    $curl_no = curl_errno($ch);
    curl_close($ch);

    if ($curl_no !== 0) {
        throw new Exception("cURL #{$curl_no}: {$curl_err}");
    }

    if ($http_code !== 200) {
        throw new Exception('Ollama HTTP ' . $http_code . ': ' . substr((string) $response, 0, 200));
    }

    $data = json_decode((string) $response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON from Ollama: ' . substr((string) $response, 0, 200));
    }

    $content = trim((string) ($data['message']['content'] ?? ''));
    if ($content === '') {
        throw new Exception('Ollama returned empty reply.');
    }

    return $content;
}

function call_openai(string $system_prompt, string $user_message): string
{
    $body = json_encode([
        'model' => OPENAI_MODEL,
        'max_tokens' => 220,
        'temperature' => 0.2,
        'messages' => [
            ['role' => 'system', 'content' => $system_prompt],
            ['role' => 'user', 'content' => $user_message],
        ],
    ]);

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 35,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . OPENAI_API_KEY,
        ],
    ]);

    $response = curl_exec($ch);
    $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err = curl_error($ch);
    $curl_no = curl_errno($ch);
    curl_close($ch);

    if ($curl_no !== 0) {
        throw new Exception("cURL #{$curl_no}: {$curl_err}");
    }

    if ($http_code !== 200) {
        throw new Exception('OpenAI HTTP ' . $http_code . ': ' . substr((string) $response, 0, 200));
    }

    $data = json_decode((string) $response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON from OpenAI: ' . substr((string) $response, 0, 200));
    }

    $content = trim((string) ($data['choices'][0]['message']['content'] ?? ''));
    if ($content === '') {
        throw new Exception('OpenAI returned empty reply.');
    }

    return $content;
}

try {
    $reply = AI_PROVIDER === 'openai'
        ? call_openai($system_prompt, $user_message)
        : call_ollama($system_prompt, $user_message);

    echo json_encode([
        'reply' => $reply,
        'model' => AI_PROVIDER === 'openai' ? OPENAI_MODEL : OLLAMA_MODEL,
        'context' => [
            'best_seller' => (string) ($best_seller['product_name'] ?? ''),
            'least_purchased' => (string) ($least_sold['product_name'] ?? ''),
            'low_stock_count' => count($low_stock),
            'out_of_stock_count' => count($out_of_stock),
            'pending_orders' => $pending_count,
            'total_products' => count($all_products),
        ],
    ]);
} catch (Exception $e) {
    echo json_encode([
        'reply' => adminlens_build_inventory_fallback_reply($all_products, $user_message),
        'fallback' => true,
        'model_error' => $e->getMessage(),
        'model' => AI_PROVIDER === 'openai' ? OPENAI_MODEL : OLLAMA_MODEL,
    ]);
}
