<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../helpers/status.php';
require_once __DIR__ . '/../helpers/data.php';

function send_json_error(string $message, int $statusCode = 500): void
{
    http_response_code($statusCode);
    echo json_encode(['error' => $message]);
    exit;
}

function post_json_request(string $url, array $payload, array $headers = []): array
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 90,
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $error !== '') {
        throw new RuntimeException('AI service request failed: ' . ($error !== '' ? $error : 'Unknown cURL error.'));
    }

    return [
        'http_code' => $httpCode,
        'body' => json_decode($response, true),
    ];
}

function get_chat_reply_from_payload(array $input): array
{
    $user_message = trim((string) ($input['message'] ?? ''));
    if ($user_message === '') {
        throw new InvalidArgumentException('Message is required.');
    }

    $products = get_all_products();
    $best = get_best_seller();
    $least = get_least_sold();
    $lowStock = get_low_stock();
    $outOfStock = get_out_of_stock();

    $system_prompt = "You are AdminLens, the boutique inventory assistant.\n"
        . "Use ONLY the inventory snapshot below. Do not invent or infer products, SKUs, prices, stock counts, reorder points, or units sold.\n"
        . "If the user asks for a product not in the snapshot, say it is not currently in the active inventory list.\n\n"
        . "KEY METRICS:\n"
        . '- Best seller: ' . (string) ($best['sku'] ?? 'N/A') . ' | ' . (string) ($best['product_name'] ?? 'N/A') . ' | sold ' . (int) ($best['units_sold'] ?? 0) . "\n"
        . '- Least purchased: ' . (string) ($least['sku'] ?? 'N/A') . ' | ' . (string) ($least['product_name'] ?? 'N/A') . ' | sold ' . (int) ($least['units_sold'] ?? 0) . "\n"
        . '- Low stock items: ' . count($lowStock) . "\n"
        . '- Out of stock items: ' . count($outOfStock) . "\n\n"
        . adminlens_build_ai_inventory_context($products)
        . "\n\nAnswer the owner using exact SKUs and product names."
        . "\nReference stock_on_hand, reorder_point, units_sold, price, and status when relevant."
        . "\nIf the question is a list request, include all matching products and their SKUs.";

    if (AI_PROVIDER === 'openai') {
        if (OPENAI_API_KEY === '' || OPENAI_API_KEY === 'your-key-here') {
            throw new RuntimeException('OpenAI API key is not configured.');
        }

        $response = post_json_request(
            'https://api.openai.com/v1/chat/completions',
            [
                'model' => OPENAI_MODEL,
                'max_tokens' => 400,
                'messages' => [
                    ['role' => 'system', 'content' => $system_prompt],
                    ['role' => 'user', 'content' => $user_message],
                ],
            ],
            ['Authorization: Bearer ' . OPENAI_API_KEY]
        );

        if ($response['http_code'] !== 200) {
            throw new RuntimeException('OpenAI request failed.');
        }

        $reply = $response['body']['choices'][0]['message']['content'] ?? '';
    } elseif (AI_PROVIDER === 'ollama') {
        $response = post_json_request(
            rtrim(OLLAMA_URL, '/') . '/api/chat',
            [
                'model' => OLLAMA_MODEL,
                'stream' => false,
                'messages' => [
                    ['role' => 'system', 'content' => $system_prompt],
                    ['role' => 'user', 'content' => $user_message],
                ],
            ]
        );

        if ($response['http_code'] !== 200) {
            throw new RuntimeException('Ollama request failed.');
        }

        $reply = $response['body']['message']['content'] ?? '';
    } else {
        throw new RuntimeException('Invalid AI provider configuration.');
    }

    $reply = trim((string) $reply);
    if ($reply === '') {
        throw new RuntimeException('AI provider returned an empty response.');
    }

    return [
        'reply' => $reply,
        'context' => [
            'best_seller' => (string) ($best['product_name'] ?? ''),
            'least_purchased' => (string) ($least['product_name'] ?? ''),
            'low_stock_count' => count($lowStock),
        ],
    ];
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json_error('Method not allowed.', 405);
}

try {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    echo json_encode(get_chat_reply_from_payload($input));
} catch (InvalidArgumentException $e) {
    send_json_error($e->getMessage(), 400);
} catch (Throwable $e) {
    send_json_error($e->getMessage(), 500);
}
