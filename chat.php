<?php

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/helpers/status.php';
require_once __DIR__ . '/helpers/data.php';

ob_start();
session_start();

$isEmbedded = (string) ($_GET['embed'] ?? $_POST['embed'] ?? '') === '1';

function adminlens_chat_url(array $params = []): string {
    global $isEmbedded;

    if ($isEmbedded && !isset($params['embed'])) {
        $params['embed'] = '1';
    }

    $query = http_build_query($params);
    return 'chat.php' . ($query !== '' ? '?' . $query : '');
}

function adminlens_chat_redirect(array $params = []): void {
    header('Location: ' . adminlens_chat_url($params));
    exit;
}


if (!isset($_SESSION['conversations']))  $_SESSION['conversations']  = [];
if (!isset($_SESSION['active_convo']))   $_SESSION['active_convo']   = null;

function make_convo_id(): string {
    return 'c_' . uniqid();
}

function adminlens_chat_inventory_context(array $products): string {
    if ($products === []) {
        return 'Inventory: none.';
    }

    $lines = [];
    foreach ($products as $product) {
        $lines[] = sprintf(
            '%s | %s | stock %d | reorder %d | sold %d | price PHP %s | %s',
            (string) ($product['sku'] ?? ''),
            (string) ($product['product_name'] ?? 'Unnamed Product'),
            (int) ($product['stock_on_hand'] ?? 0),
            (int) ($product['reorder_point'] ?? 0),
            (int) ($product['units_sold'] ?? 0),
            number_format((float) ($product['price'] ?? 0), 2),
            (string) ($product['status'] ?? 'OK')
        );
    }

    return implode("\n", $lines);
}

function get_ai_reply(string $msg, PDO $pdo): string {
    try {
        $products = get_all_products();
        $fastReply = adminlens_try_fast_inventory_answer($products, $msg);
        if ($fastReply !== null) {
            return $fastReply;
        }

        $best = $products[0] ?? [];
        $least = $products === [] ? [] : ($products[array_key_last($products)] ?? []);
        $lowStock = array_values(array_filter($products, static function (array $product): bool {
            $stock = (int) ($product['stock_on_hand'] ?? 0);
            $reorder = (int) ($product['reorder_point'] ?? 0);
            return $stock > 0 && $stock < $reorder;
        }));
        $outOfStock = array_values(array_filter($products, static fn(array $product): bool => (int) ($product['stock_on_hand'] ?? 0) === 0));
        $context = adminlens_build_targeted_inventory_context($products, $msg, 8);

        $system_prompt =
            "You are AdminLens, a fast boutique inventory assistant.\n"
            . "Use only the inventory data below.\n"
            . "Never invent products, SKUs, prices, stock, reorder points, or sales.\n"
            . "If an item is missing from the list, say it is not in the active inventory.\n"
            . "Keep answers short and practical by default.\n"
            . "Always use exact SKUs and product names.\n\n"
            . "Best seller: " . (string) ($best['sku'] ?? 'N/A') . ' | ' . (string) ($best['product_name'] ?? 'N/A') . ' | sold ' . (int) ($best['units_sold'] ?? 0) . "\n"
            . "Least purchased: " . (string) ($least['sku'] ?? 'N/A') . ' | ' . (string) ($least['product_name'] ?? 'N/A') . ' | sold ' . (int) ($least['units_sold'] ?? 0) . "\n"
            . 'Low stock count: ' . count($lowStock) . "\n"
            . 'Out of stock count: ' . count($outOfStock) . "\n\n"
            . $context;

        if (AI_PROVIDER === 'openai') {
            $body = json_encode([
                'model' => OPENAI_MODEL,
                'max_tokens' => 220,
                'temperature' => 0.2,
                'messages' => [
                    ['role' => 'system', 'content' => $system_prompt],
                    ['role' => 'user',   'content' => $msg],
                ],
            ]);
            $ch = curl_init('https://api.openai.com/v1/chat/completions');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body, CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . OPENAI_API_KEY],
            ]);
            $resp = curl_exec($ch); $st = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
            if ($st !== 200) throw new Exception("OpenAI HTTP $st");
            $data = json_decode($resp, true);
            return $data['choices'][0]['message']['content'] ?? '';
        } else {
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
                    ['role' => 'user',   'content' => $msg],
                ],
            ]);
            $ch = curl_init(OLLAMA_URL . '/api/chat');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_CONNECTTIMEOUT => OLLAMA_CONNECT_TIMEOUT,
                CURLOPT_TIMEOUT => OLLAMA_RESPONSE_TIMEOUT,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'Expect:'],
            ]);
            $resp = curl_exec($ch); $st = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
            if ($st !== 200) throw new Exception("Ollama HTTP $st");
            $data = json_decode($resp, true);
            return $data['message']['content'] ?? '';
        }
    } catch (Exception $e) {
        $products = get_all_products();
        return adminlens_build_inventory_fallback_reply($products, $msg);
    }
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'new_convo') {
    $id = make_convo_id();
    $_SESSION['conversations'][$id] = [
        'id'         => $id,
        'title'      => 'New Chat',
        'created_at' => date('Y-m-d H:i:s'),
        'archived'   => false,
        'messages'   => [],
    ];
    $_SESSION['active_convo'] = $id;
    adminlens_chat_redirect();
}

if ($action === 'select' && isset($_GET['id'])) {
    $sel = $_GET['id'];
    if (isset($_SESSION['conversations'][$sel])) {
        $_SESSION['active_convo'] = $sel;
    }
    adminlens_chat_redirect();
}

if ($action === 'delete' && isset($_POST['id'])) {
    $del = $_POST['id'];
    unset($_SESSION['conversations'][$del]);
    if ($_SESSION['active_convo'] === $del) {
        $_SESSION['active_convo'] = array_key_first($_SESSION['conversations']) ?? null;
    }
    adminlens_chat_redirect();
}

if ($action === 'archive' && isset($_POST['id'])) {
    $aid = $_POST['id'];
    if (isset($_SESSION['conversations'][$aid])) {
        $_SESSION['conversations'][$aid]['archived'] = !$_SESSION['conversations'][$aid]['archived'];
        if ($_SESSION['active_convo'] === $aid) {
            $_SESSION['active_convo'] = null;
        }
    }
    adminlens_chat_redirect();
}

if ($action === 'delete_msg' && isset($_POST['convo_id'], $_POST['msg_index'])) {
    $cid = $_POST['convo_id'];
    $idx = (int)$_POST['msg_index'];
    if (isset($_SESSION['conversations'][$cid]['messages'][$idx])) {
        array_splice($_SESSION['conversations'][$cid]['messages'], $idx, 1);
    }
    adminlens_chat_redirect();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'send') {
    $msg   = trim($_POST['message'] ?? '');
    $cid   = $_POST['convo_id'] ?? '';

    if (empty($cid) || !isset($_SESSION['conversations'][$cid])) {
        $cid = make_convo_id();
        $_SESSION['conversations'][$cid] = [
            'id'         => $cid,
            'title'      => 'New Chat',
            'created_at' => date('Y-m-d H:i:s'),
            'archived'   => false,
            'messages'   => [],
        ];
        $_SESSION['active_convo'] = $cid;
    }

    if (!empty($msg)) {
        $ts = date('g:i A');

        if (empty($_SESSION['conversations'][$cid]['messages'])) {
            $_SESSION['conversations'][$cid]['title'] = mb_substr($msg, 0, 36) . (mb_strlen($msg) > 36 ? '…' : '');
        }

        $_SESSION['conversations'][$cid]['messages'][] = [
            'role'      => 'user',
            'message'   => $msg,
            'timestamp' => $ts,
        ];

        global $pdo;
        $reply = get_ai_reply($msg, $pdo);

        $_SESSION['conversations'][$cid]['messages'][] = [
            'role'      => 'assistant',
            'message'   => $reply,
            'timestamp' => date('g:i A'),
        ];
    }
    adminlens_chat_redirect();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'send_ajax') {
    header('Content-Type: application/json');

    $msg   = trim($_POST['message'] ?? '');
    $cid   = $_POST['convo_id'] ?? '';

    if (empty($cid) || !isset($_SESSION['conversations'][$cid])) {
        $cid = make_convo_id();
        $_SESSION['conversations'][$cid] = [
            'id'         => $cid,
            'title'      => 'New Chat',
            'created_at' => date('Y-m-d H:i:s'),
            'archived'   => false,
            'messages'   => [],
        ];
        $_SESSION['active_convo'] = $cid;
    }

    if (!empty($msg)) {
        $ts = date('g:i A');

        if (empty($_SESSION['conversations'][$cid]['messages'])) {
            $_SESSION['conversations'][$cid]['title'] = mb_substr($msg, 0, 36) . (mb_strlen($msg) > 36 ? '…' : '');
        }

        $_SESSION['conversations'][$cid]['messages'][] = [
            'role'      => 'user',
            'message'   => $msg,
            'timestamp' => $ts,
        ];

        session_write_close();

        global $pdo;
        $reply = get_ai_reply($msg, $pdo);

        session_start();

        $_SESSION['conversations'][$cid]['messages'][] = [
            'role'      => 'assistant',
            'message'   => $reply,
            'timestamp' => date('g:i A'),
        ];

        if (ob_get_length()) {
            ob_clean();
        }

        echo json_encode([
            'reply' => $reply,
            'conversation_id' => $cid,
            'timestamp' => date('g:i A'),
        ]);
        exit;
    }

    if (ob_get_length()) {
        ob_clean();
    }

    echo json_encode(['error' => 'Message is required.']);
    exit;
}

$all_convos    = $_SESSION['conversations'];
$active_id     = $_SESSION['active_convo'];

uasort($all_convos, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));

$active_convos   = array_filter($all_convos, fn($c) => !$c['archived']);
$archived_convos = array_filter($all_convos, fn($c) =>  $c['archived']);
$active_convo    = ($active_id && isset($all_convos[$active_id])) ? $all_convos[$active_id] : null;

$prefill = htmlspecialchars(trim($_GET['q'] ?? ''));

$suggested = [
    'What should I restock today?',
    'Which product is selling the most?',
    'Which product should I promote?',
    'Summarize my inventory status',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>AI Assistant - AdminLens</title>
  <link rel="stylesheet" href="assets/style.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,400&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <style>
    :root {
      --chat-sidebar-w: 280px;
      --chat-header-h: 58px;
      --font-chat: 'DM Sans', system-ui, sans-serif;
      --font-chat-mono: 'DM Mono', monospace;
      --bubble-user-bg:   #2563eb;
      --bubble-user-text: #ffffff;
      --bubble-ai-bg:     #f1f5f9;
      --bubble-ai-text:   #1e293b;
      --sidebar-bg:       #0f172a;
      --sidebar-text:     #cbd5e1;
      --sidebar-hover:    #1e293b;
      --sidebar-active:   #1d4ed8;
      --chat-bg:          #f8fafc;
    }

    body { font-family: var(--font-chat); }

    .app-wrapper {
      min-height: 100vh;
      display: flex;
      flex-direction: column;
    }

    .topnav { z-index: 200; }

    .messenger-shell {
      display: flex;
      flex: 1;
      min-height: 0;
      background: var(--chat-bg);
      overflow: hidden;
    }

    .chat-sidebar {
      width: var(--chat-sidebar-w);
      flex-shrink: 0;
      min-height: 0;
      background: var(--sidebar-bg);
      display: flex;
      flex-direction: column;
      border-right: 1px solid #1e293b;
      overflow: hidden;
    }

    .sidebar-head {
      padding: 14px 16px 10px;
      border-bottom: 1px solid #1e293b;
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-shrink: 0;
    }

    .sidebar-head__title {
      font-size: 0.75rem;
      font-weight: 600;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: #64748b;
    }

    .btn-new-chat {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 5px 10px;
      background: var(--sidebar-active);
      color: #fff;
      border: none;
      border-radius: 6px;
      font-family: var(--font-chat);
      font-size: 0.75rem;
      font-weight: 600;
      cursor: pointer;
      text-decoration: none;
      transition: background 0.15s;
    }
    .btn-new-chat:hover { background: #1e40af; }

    .sidebar-scroll {
      flex: 1;
      overflow-y: auto;
      padding: 8px 0;
    }

    .sidebar-section-label {
      padding: 8px 16px 4px;
      font-size: 0.6875rem;
      font-weight: 700;
      letter-spacing: 0.07em;
      text-transform: uppercase;
      color: #475569;
    }

    .convo-item {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 9px 14px;
      cursor: pointer;
      border-left: 3px solid transparent;
      transition: background 0.12s;
      text-decoration: none;
    }
    .convo-item:hover { background: var(--sidebar-hover); }
    .convo-item.is-active {
      background: #172554;
      border-left-color: var(--sidebar-active);
    }

    .convo-item__avatar {
      width: 32px; height: 32px;
      border-radius: 50%;
      background: #1e3a5f;
      display: flex; align-items: center; justify-content: center;
      font-size: 0.75rem;
      color: #93c5fd;
      flex-shrink: 0;
      font-weight: 700;
    }
    .convo-item.is-active .convo-item__avatar {
      background: var(--sidebar-active);
      color: #fff;
    }

    .convo-item__body { flex: 1; min-width: 0; }
    .convo-item__name {
      font-size: 0.8125rem;
      font-weight: 500;
      color: #e2e8f0;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      line-height: 1.3;
    }
    .convo-item.is-active .convo-item__name { color: #fff; font-weight: 600; }
    .convo-item__time {
      font-size: 0.6875rem;
      color: #475569;
      margin-top: 2px;
    }

    .convo-item__actions {
      display: none;
      gap: 4px;
      flex-shrink: 0;
    }
    .convo-item:hover .convo-item__actions { display: flex; }

    .icon-btn {
      width: 26px; height: 26px;
      border-radius: 5px;
      border: none;
      background: transparent;
      cursor: pointer;
      display: flex; align-items: center; justify-content: center;
      font-size: 0.8125rem;
      transition: background 0.12s;
      color: #94a3b8;
    }
    .icon-btn:hover { background: #334155; color: #e2e8f0; }
    .icon-btn--danger:hover { background: #7f1d1d; color: #fca5a5; }

    .sidebar-empty {
      padding: 32px 16px;
      text-align: center;
      color: #475569;
      font-size: 0.8125rem;
      line-height: 1.6;
    }

    .chat-main {
      flex: 1;
      display: flex;
      flex-direction: column;
      min-height: 0;
      overflow: hidden;
      background: var(--chat-bg);
    }

    .chat-header {
      height: var(--chat-header-h);
      flex-shrink: 0;
      background: #fff;
      border-bottom: 1px solid var(--color-border);
      padding: 0 20px;
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .chat-header__avatar {
      width: 36px; height: 36px;
      border-radius: 50%;
      background: linear-gradient(135deg, #2563eb, #7c3aed);
      display: flex; align-items: center; justify-content: center;
      font-size: 1rem;
      flex-shrink: 0;
    }

    .chat-header__info { flex: 1; }
    .chat-header__name {
      font-size: 0.9375rem;
      font-weight: 600;
      color: var(--color-text);
      line-height: 1.2;
    }
    .chat-header__status {
      font-size: 0.75rem;
      color: var(--color-success);
      display: flex;
      align-items: center;
      gap: 4px;
    }
    .chat-header__status::before {
      content: '';
      width: 7px; height: 7px;
      border-radius: 50%;
      background: var(--color-success);
      display: inline-block;
    }

    .messages-area {
      flex: 1;
      overflow-y: auto;
      padding: 20px 24px;
      display: flex;
      flex-direction: column;
      gap: 4px;
      scroll-behavior: smooth;
    }

    .date-divider {
      display: flex;
      align-items: center;
      gap: 10px;
      margin: 12px 0;
      color: #94a3b8;
      font-size: 0.6875rem;
      font-weight: 600;
      letter-spacing: 0.05em;
      text-transform: uppercase;
    }
    .date-divider::before, .date-divider::after {
      content: ''; flex: 1; height: 1px;
      background: var(--color-border);
    }

    .msg-row {
      display: flex;
      gap: 8px;
      align-items: flex-end;
      max-width: 78%;
      position: relative;
    }
    .msg-row--user {
      align-self: flex-end;
      flex-direction: row-reverse;
    }
    .msg-row--ai {
      align-self: flex-start;
    }

    .msg-avatar {
      width: 28px; height: 28px;
      border-radius: 50%;
      flex-shrink: 0;
      display: flex; align-items: center; justify-content: center;
      font-size: 0.8125rem;
      margin-bottom: 2px;
    }
    .msg-avatar--ai {
      background: linear-gradient(135deg, #2563eb, #7c3aed);
    }
    .msg-avatar--user {
      background: #334155;
      color: #94a3b8;
      font-size: 0.75rem;
      font-weight: 700;
    }

    .msg-content { display: flex; flex-direction: column; gap: 2px; }
    .msg-row--user .msg-content { align-items: flex-end; }

    .msg-bubble {
      padding: 10px 14px;
      border-radius: 18px;
      font-size: 0.9375rem;
      line-height: 1.55;
      word-break: break-word;
      position: relative;
    }

    .msg-bubble--user {
      background: var(--bubble-user-bg);
      color: var(--bubble-user-text);
      border-bottom-right-radius: 5px;
    }

    .msg-bubble--ai {
      background: var(--bubble-ai-bg);
      color: var(--bubble-ai-text);
      border-bottom-left-radius: 5px;
      border: 1px solid #e2e8f0;
    }

    .msg-meta {
      display: flex;
      align-items: center;
      gap: 6px;
      padding: 0 4px;
    }

    .msg-time {
      font-size: 0.6875rem;
      color: #94a3b8;
    }

    .msg-delete-btn {
      width: 20px; height: 20px;
      border-radius: 4px;
      border: none;
      background: transparent;
      cursor: pointer;
      display: none;
      align-items: center;
      justify-content: center;
      font-size: 0.6875rem;
      color: #94a3b8;
      transition: all 0.12s;
      padding: 0;
    }
    .msg-row:hover .msg-delete-btn { display: flex; }
    .msg-delete-btn:hover { background: #fee2e2; color: #dc2626; }

    .chat-empty-state {
      flex: 1;
      min-height: 0;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 20px;
      padding: 40px 20px;
      text-align: center;
    }

    .empty-icon {
      width: 72px; height: 72px;
      border-radius: 50%;
      background: #eff6ff;
      display: flex; align-items: center; justify-content: center;
      font-size: 2rem;
    }

    .empty-title {
      font-size: 1.125rem;
      font-weight: 600;
      color: var(--color-text);
    }

    .empty-sub {
      font-size: 0.875rem;
      color: var(--color-muted);
      max-width: 320px;
      line-height: 1.6;
    }

    .suggested-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 8px;
      max-width: 480px;
      width: 100%;
    }

    .suggest-chip {
      padding: 10px 14px;
      background: #fff;
      border: 1px solid var(--color-border);
      border-radius: 12px;
      font-size: 0.8125rem;
      color: var(--color-text);
      text-align: left;
      line-height: 1.4;
      cursor: pointer;
      text-decoration: none;
      display: block;
      transition: all 0.15s;
    }
    .suggest-chip:hover {
      background: #eff6ff;
      border-color: #bfdbfe;
      color: var(--color-primary);
    }
    .suggest-chip::before {
      content: '→ ';
      color: var(--color-primary);
      font-weight: 700;
    }

    .input-area {
      flex-shrink: 0;
      padding: 12px 20px 16px;
      background: #fff;
      border-top: 1px solid var(--color-border);
    }

    .input-row {
      display: flex;
      gap: 10px;
      align-items: flex-end;
      background: var(--chat-bg);
      border: 1.5px solid var(--color-border);
      border-radius: 24px;
      padding: 8px 8px 8px 18px;
      transition: border-color 0.15s;
    }
    .input-row:focus-within {
      border-color: var(--color-primary);
      background: #fff;
    }

    .input-row textarea {
      flex: 1;
      border: none;
      background: transparent;
      resize: none;
      font-family: var(--font-chat);
      font-size: 0.9375rem;
      color: var(--color-text);
      line-height: 1.5;
      max-height: 120px;
      min-height: 24px;
      outline: none;
      padding: 4px 0;
    }
    .input-row textarea::placeholder { color: #94a3b8; }

    .send-btn {
      width: 38px; height: 38px;
      border-radius: 50%;
      background: var(--color-primary);
      border: none;
      cursor: pointer;
      display: flex; align-items: center; justify-content: center;
      flex-shrink: 0;
      transition: background 0.15s, transform 0.1s;
      color: #fff;
      font-size: 1rem;
    }
    .send-btn:hover { background: #1d4ed8; transform: scale(1.05); }
    .send-btn:active { transform: scale(0.97); }

    .send-btn[disabled] {
      cursor: wait;
      opacity: 0.78;
      transform: none;
    }

    .typing-status {
      margin-top: 8px;
      min-height: 18px;
      font-size: 0.82rem;
      color: var(--color-muted);
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .thinking-row {
      align-self: flex-start;
      max-width: 78%;
      display: flex;
      gap: 8px;
      align-items: flex-end;
    }

    .thinking-bubble {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: #f8fafc;
      color: var(--color-text);
      border: 1px solid var(--color-border);
      padding: 10px 14px;
      border-radius: 18px;
      border-bottom-left-radius: 5px;
    }

    .thinking-dots {
      display: inline-flex;
      gap: 4px;
      align-items: center;
    }

    .thinking-dots span {
      width: 6px;
      height: 6px;
      border-radius: 50%;
      background: var(--color-primary);
      animation: thinkingPulse 1s infinite ease-in-out;
    }

    .thinking-dots span:nth-child(2) { animation-delay: 0.15s; }
    .thinking-dots span:nth-child(3) { animation-delay: 0.3s; }

    @keyframes thinkingPulse {
      0%, 80%, 100% { opacity: 0.35; transform: translateY(0); }
      40% { opacity: 1; transform: translateY(-3px); }
    }

    .no-convo-panel {
      flex: 1;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 16px;
      color: var(--color-muted);
      text-align: center;
      padding: 40px;
    }

    .no-convo-icon { font-size: 3rem; opacity: 0.3; }

    .archived-label {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding: 2px 8px;
      background: #fef3c7;
      color: #92400e;
      border-radius: 999px;
      font-size: 0.6875rem;
      font-weight: 700;
      letter-spacing: 0.04em;
    }

    .messages-area::-webkit-scrollbar,
    .sidebar-scroll::-webkit-scrollbar { width: 4px; }
    .messages-area::-webkit-scrollbar-track { background: transparent; }
    .messages-area::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 99px; }
    .sidebar-scroll::-webkit-scrollbar-thumb { background: #334155; border-radius: 99px; }

    @media (max-width: 768px) {
      .chat-sidebar { width: 72px; }
      .convo-item__body, .sidebar-head__title,
      .sidebar-section-label, .convo-item__actions { display: none; }
      .convo-item { justify-content: center; padding: 10px; }
      .suggested-grid { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body class="chat-modal-page<?= $isEmbedded ? ' chat-embedded' : '' ?>">
<div class="app-wrapper">

  <?php if (!$isEmbedded): ?>
  <div class="page-shell">
    <header class="site-header">
      <div class="brand">AdminLens</div>
      <nav class="site-nav">
        <a href="index.php">Dashboard</a>
        <a href="inventory.php">Inventory</a>
        <a href="index.php#charts">Charts</a>
      </nav>
    </header>
  </div>
  <?php endif; ?>

  <div class="messenger-shell">

    <aside class="chat-sidebar">
      <div class="sidebar-head">
        <span class="sidebar-head__title">Chats</span>
        <form method="POST" action="chat.php" style="margin:0;">
          <input type="hidden" name="action" value="new_convo">
          <button type="submit" class="btn-new-chat">+ New</button>
        </form>
      </div>

      <div class="sidebar-scroll">

        <?php if (empty($active_convos) && empty($archived_convos)): ?>
          <div class="sidebar-empty">No conversations yet.<br>Start a new chat!</div>
        <?php endif; ?>

        <?php if (!empty($active_convos)): ?>
          <?php foreach ($active_convos as $c):
            $isActive = ($c['id'] === $active_id);
            $initials = strtoupper(substr($c['title'], 0, 2));
            $lastMsg  = end($c['messages']);
            $preview  = $lastMsg ? mb_substr($lastMsg['message'], 0, 28) . '...' : 'No messages yet';
          ?>
          <div class="convo-item <?= $isActive ? 'is-active' : '' ?>">
            <a href="chat.php?action=select&id=<?= urlencode($c['id']) ?>"
               style="display:contents; text-decoration:none;">
              <div class="convo-item__avatar"><?= htmlspecialchars($initials) ?></div>
              <div class="convo-item__body">
                <div class="convo-item__name"><?= htmlspecialchars($c['title']) ?></div>
                <div class="convo-item__time"><?= htmlspecialchars($preview) ?></div>
              </div>
            </a>
            <div class="convo-item__actions">
              <form method="POST" action="chat.php" style="margin:0;">
                <input type="hidden" name="action" value="archive">
                <input type="hidden" name="id"     value="<?= htmlspecialchars($c['id']) ?>">
                <button type="submit" class="icon-btn" title="Archive">📦</button>
              </form>
              <form method="POST" action="chat.php" style="margin:0;"
                    onsubmit="return confirm('Delete this chat?');">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id"     value="<?= htmlspecialchars($c['id']) ?>">
                <button type="submit" class="icon-btn icon-btn--danger" title="Delete">🗑</button>
              </form>
            </div>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>

        <?php if (!empty($archived_convos)): ?>
          <div class="sidebar-section-label">Archived</div>
          <?php foreach ($archived_convos as $c):
            $isActive = ($c['id'] === $active_id);
            $initials = strtoupper(substr($c['title'], 0, 2));
          ?>
          <div class="convo-item <?= $isActive ? 'is-active' : '' ?>"
               style="opacity: 0.65;">
            <a href="chat.php?action=select&id=<?= urlencode($c['id']) ?>"
               style="display:contents; text-decoration:none;">
              <div class="convo-item__avatar" style="background:#334155;">🤖</div>
              <div class="convo-item__body">
                <div class="convo-item__name"><?= htmlspecialchars($c['title']) ?></div>
                <div class="convo-item__time">
                  <span class="archived-label">Archived</span>
                </div>
              </div>
            </a>
            <div class="convo-item__actions">
              <form method="POST" action="chat.php" style="margin:0;">
                <input type="hidden" name="action" value="archive">
                <input type="hidden" name="id"     value="<?= htmlspecialchars($c['id']) ?>">
                <button type="submit" class="icon-btn" title="Unarchive">📂</button>
              </form>
              <form method="POST" action="chat.php" style="margin:0;"
                    onsubmit="return confirm('Delete this chat?');">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id"     value="<?= htmlspecialchars($c['id']) ?>">
                <button type="submit" class="icon-btn icon-btn--danger" title="Delete">🗑</button>
              </form>
            </div>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>

      </div><!-- /sidebar-scroll -->
    </aside>

    <div class="chat-main">

      <?php if ($active_convo): ?>

        <div class="chat-header">
          <div class="chat-header__avatar">🤖</div>
          <div class="chat-header__info">
            <div class="chat-header__name">
              <?= htmlspecialchars($active_convo['title']) ?>
              <?php if ($active_convo['archived']): ?>
                <span class="archived-label" style="margin-left:8px;">Archived</span>
              <?php endif; ?>
            </div>
            <div class="chat-header__status">AdminLens AI · Online</div>
          </div>
          <div style="display:flex; gap:6px; align-items:center;">
            <form method="POST" action="chat.php" style="margin:0;">
              <input type="hidden" name="action" value="archive">
              <input type="hidden" name="id"     value="<?= htmlspecialchars($active_convo['id']) ?>">
              <button type="submit" class="icon-btn"
                      title="<?= $active_convo['archived'] ? 'Unarchive' : 'Archive' ?>"
                      style="width:34px;height:34px;background:#f1f5f9;color:#64748b;border-radius:8px;">
                <?= $active_convo['archived'] ? '📂' : '📦' ?>
              </button>
            </form>
            <form method="POST" action="chat.php" style="margin:0;"
                  onsubmit="return confirm('Delete this entire conversation?');">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id"     value="<?= htmlspecialchars($active_convo['id']) ?>">
              <button type="submit" class="icon-btn icon-btn--danger"
                      title="Delete conversation"
                      style="width:34px;height:34px;background:#fef2f2;border-radius:8px;">
                🗑
              </button>
            </form>
          </div>
        </div>

        <div class="messages-area" id="messages-area">

          <?php if (empty($active_convo['messages'])): ?>
            <div class="chat-empty-state">
              <div class="empty-icon">🤖</div>
              <div class="empty-title">Start the conversation</div>
              <div class="empty-sub">
                Ask AdminLens anything about your boutique inventory restocking, sales trends, or promotions.
              </div>
              <div class="suggested-grid">
                <?php foreach ($suggested as $s): ?>
                <a href="chat.php?action=select&id=<?= urlencode($active_convo['id']) ?>&q=<?= urlencode($s) ?>"
                   class="suggest-chip"
                   onclick="document.querySelector('textarea[name=message]').value='<?= htmlspecialchars(addslashes($s)) ?>'; return false;"
                ><?= htmlspecialchars($s) ?></a>
                <?php endforeach; ?>
              </div>
            </div>
          <?php else: ?>

            <div class="date-divider">
              <?= date('F j, Y', strtotime($active_convo['created_at'])) ?>
            </div>

            <?php foreach ($active_convo['messages'] as $idx => $m):
              $isUser = ($m['role'] === 'user');
            ?>
            <div class="msg-row msg-row--<?= $isUser ? 'user' : 'ai' ?>">

              <div class="msg-avatar msg-avatar--<?= $isUser ? 'user' : 'ai' ?>">
                <?= $isUser ? 'You' : '🤖' ?>
              </div>

              <div class="msg-content">
                <div class="msg-bubble msg-bubble--<?= $isUser ? 'user' : 'ai' ?>">
                  <?= nl2br(htmlspecialchars($m['message'])) ?>
                </div>
                <div class="msg-meta">
                  <span class="msg-time"><?= htmlspecialchars($m['timestamp'] ?? '') ?></span>
                  <form method="POST" action="chat.php" style="margin:0;"
                        onsubmit="return confirm('Delete this message?');">
                    <input type="hidden" name="action"    value="delete_msg">
                    <input type="hidden" name="convo_id"  value="<?= htmlspecialchars($active_convo['id']) ?>">
                    <input type="hidden" name="msg_index" value="<?= $idx ?>">
                    <button type="submit" class="msg-delete-btn" title="Delete message">🗑</button>
                  </form>
                </div>
              </div>

            </div>
            <?php endforeach; ?>

          <?php endif; ?>

        </div><!-- /messages-area -->

        <?php if (!$active_convo['archived']): ?>
        <div class="input-area">
          <form method="POST" action="chat.php" id="chat-form">
            <input type="hidden" name="action"   value="send">
            <input type="hidden" name="convo_id" value="<?= htmlspecialchars($active_convo['id']) ?>">
            <div class="input-row">
              <textarea
                name="message"
                rows="1"
                placeholder="Message AdminLens..."
                required
              ><?= $prefill ?></textarea>
              <button type="submit" class="send-btn" title="Send" id="send-btn">&#9658;</button>
            </div>
            <div class="typing-status" id="typing-status" aria-live="polite"></div>
          </form>
        </div>
        <?php else: ?>
        <div class="input-area" style="text-align:center; padding:20px;">
          <span style="color:var(--color-muted); font-size:0.875rem;">
            This conversation is archived.
            <form method="POST" action="chat.php" style="display:inline; margin:0;">
              <input type="hidden" name="action" value="archive">
              <input type="hidden" name="id"     value="<?= htmlspecialchars($active_convo['id']) ?>">
              <button type="submit" style="background:none;border:none;color:var(--color-primary);cursor:pointer;font-size:0.875rem;font-weight:600;">
                Unarchive to continue
              </button>
            </form>
          </span>
        </div>
        <?php endif; ?>

      <?php else: ?>

        <div class="no-convo-panel">
          <div class="no-convo-icon">🤖</div>
          <div style="font-size:1.125rem; font-weight:600; color:var(--color-text);">
            AdminLens AI Assistant
          </div>
          <div style="font-size:0.875rem; color:var(--color-muted); max-width:300px; line-height:1.6;">
            Select a conversation from the sidebar, or start a new chat to ask about your inventory.
          </div>
          <form method="POST" action="chat.php" style="margin-top:8px;">
            <input type="hidden" name="action" value="new_convo">
            <button type="submit" class="btn-new-chat" style="height:40px; padding:0 20px; font-size:0.875rem; border-radius:8px;">
              + Start New Chat
            </button>
          </form>
        </div>

      <?php endif; ?>

    </div><!-- /chat-main -->

  </div><!-- /messenger-shell -->

</div><!-- /app-wrapper -->

<script>
  var isEmbedded = <?= $isEmbedded ? 'true' : 'false' ?>;
  var chatBaseUrl = <?= json_encode(adminlens_chat_url()) ?>;
  var chatSelectUrlBase = <?= json_encode(adminlens_chat_url(['action' => 'select'])) ?>;
  var ollamaWarmupUrl = <?= json_encode('api/ollama_warmup.php') ?>;
  var apiChatUrl = <?= json_encode('api/chat.php') ?>;

  (function() {

    if (isEmbedded) {
      Array.prototype.forEach.call(document.querySelectorAll('form'), function(form) {
        var embedField = form.querySelector('input[name="embed"]');
        if (!embedField) {
          embedField = document.createElement('input');
          embedField.type = 'hidden';
          embedField.name = 'embed';
          embedField.value = '1';
          form.appendChild(embedField);
        }
      });

      Array.prototype.forEach.call(document.querySelectorAll('a[href^="chat.php"]'), function(link) {
        try {
          var url = new URL(link.getAttribute('href'), window.location.href);
          url.searchParams.set('embed', '1');
          link.setAttribute('href', url.pathname + url.search);
        } catch (error) {
        }
      });
    }

    var el = document.getElementById('messages-area');
    if (el) el.scrollTop = el.scrollHeight;

    if (window.fetch) {
      window.setTimeout(function() {
        fetch(ollamaWarmupUrl, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
          },
          body: JSON.stringify({ warmup: true })
        }).catch(function() {
        });
      }, 150);
    }
  })();

  function escapeHtml(text) {
    return String(text)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function scrollMessagesToBottom() {
    var el = document.getElementById('messages-area');
    if (el) el.scrollTop = el.scrollHeight;
  }

  function createThinkingBubble() {
    var row = document.createElement('div');
    row.className = 'thinking-row';
    row.id = 'thinking-row';
    row.innerHTML = ''
      + '<div class="msg-avatar msg-avatar--ai">🤖</div>'
      + '<div class="thinking-bubble">'
      + '  <span>AdminLens is thinking</span>'
      + '  <span class="thinking-dots"><span></span><span></span><span></span></span>'
      + '</div>';
    return row;
  }

  function appendMessage(role, message, timestamp) {
    var area = document.getElementById('messages-area');
    if (!area) return;

    var row = document.createElement('div');
    row.className = 'msg-row msg-row--' + (role === 'user' ? 'user' : 'ai');
    row.innerHTML = ''
      + '<div class="msg-avatar msg-avatar--' + (role === 'user' ? 'user' : 'ai') + '">'
      +   (role === 'user' ? 'You' : '🤖')
      + '</div>'
      + '<div class="msg-content">'
      + '  <div class="msg-bubble msg-bubble--' + (role === 'user' ? 'user' : 'ai') + '">'
      +       escapeHtml(message).replace(/\n/g, '<br>')
      + '  </div>'
      + '  <div class="msg-meta"><span class="msg-time">' + escapeHtml(timestamp || '') + '</span></div>'
      + '</div>';

    area.appendChild(row);
    scrollMessagesToBottom();
  }

  var ta = document.querySelector('textarea[name="message"]');
  var form = document.getElementById('chat-form');
  var sendBtn = document.getElementById('send-btn');
  var typingStatus = document.getElementById('typing-status');
  var CHAT_REQUEST_TIMEOUT_MS = <?= json_encode((AI_PROVIDER === 'ollama' ? max(65000, (OLLAMA_RESPONSE_TIMEOUT + 5) * 1000) : 40000)) ?>;

  if (form && ta && sendBtn) {
    form.addEventListener('submit', function(e) {
      e.preventDefault();

      var message = ta.value.trim();
      if (!message) return;

      appendMessage('user', message, new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }));

      ta.value = '';
      ta.style.height = 'auto';
      ta.focus();

      var thinking = document.getElementById('thinking-row');
      if (thinking) thinking.remove();
      var area = document.getElementById('messages-area');
      if (area) {
        area.appendChild(createThinkingBubble());
        scrollMessagesToBottom();
      }

      if (typingStatus) {
        typingStatus.textContent = 'AdminLens is preparing a reply...';
      }

      ta.disabled = true;
      sendBtn.disabled = true;
      sendBtn.textContent = '...';

      var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
      var timeoutId = controller ? window.setTimeout(function() {
        controller.abort();
      }, CHAT_REQUEST_TIMEOUT_MS) : null;

      fetch(apiChatUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({ message: message }),
        signal: controller ? controller.signal : undefined
      })
      .then(function(response) { return response.json(); })
      .then(function(payload) {
        var bubble = document.getElementById('thinking-row');
        if (bubble) bubble.remove();
        if (timeoutId) window.clearTimeout(timeoutId);

        if (payload && payload.error) {
          appendMessage('assistant', 'Error: ' + payload.error, payload.timestamp || new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }));
          if (typingStatus) typingStatus.textContent = '';
        } else if (payload && payload.reply) {
          appendMessage('assistant', payload.reply, payload.timestamp || new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }));
          if (typingStatus) typingStatus.textContent = '';
        } else {
          appendMessage('assistant', 'Unable to generate a response right now.', new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }));
        }
      })
      .catch(function(error) {
        var bubble = document.getElementById('thinking-row');
        if (bubble) bubble.remove();
        if (timeoutId) window.clearTimeout(timeoutId);
        if (error && error.name === 'AbortError') {
          appendMessage('assistant', 'The inventory assistant is taking longer than expected. Please try again.', new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }));
        } else {
          appendMessage('assistant', 'Unable to contact the inventory assistant.', new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }));
        }
      })
      .finally(function() {
        ta.disabled = false;
        sendBtn.disabled = false;
        sendBtn.innerHTML = '&#9658;';
        if (typingStatus) typingStatus.textContent = '';
      });
    });
  }

  if (ta) {
    ta.addEventListener('input', function() {
      this.style.height = 'auto';
      this.style.height = Math.min(this.scrollHeight, 120) + 'px';
    });
    ta.addEventListener('keydown', function(e) {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        var form = document.getElementById('chat-form');
        if (form && this.value.trim()) form.submit();
      }
    });
  }
</script>
</body>
</html>
