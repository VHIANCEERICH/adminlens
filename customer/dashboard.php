<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';

adminlens_require_role('customer');
$user = adminlens_current_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Dashboard</title>
    <style>
        :root {
            --bg: #edf8f2;
            --card: #ffffff;
            --text: #1f2937;
            --muted: #6b7280;
            --accent: #146c43;
            --border: #cfe7d9;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            background: radial-gradient(circle at top, #f8fffb 0%, #dcefe3 100%);
            color: var(--text);
            padding: 32px 20px;
        }
        .wrap {
            max-width: 900px;
            margin: 0 auto;
        }
        .topbar {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            align-items: center;
            margin-bottom: 24px;
        }
        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 28px;
            box-shadow: 0 14px 40px rgba(20, 108, 67, 0.10);
        }
        .pill {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 999px;
            background: #dff3e8;
            color: var(--accent);
            font-weight: 700;
            font-size: 13px;
        }
        .logout-btn {
            text-decoration: none;
            background: var(--accent);
            color: #fff;
            padding: 11px 16px;
            border-radius: 10px;
            font-weight: 700;
        }
        .meta {
            color: var(--muted);
            line-height: 1.7;
        }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="topbar">
            <div>
                <div class="pill">Customer Area</div>
                <h1>Welcome, <?= htmlspecialchars((string) ($user['full_name'] ?? 'Customer')) ?></h1>
            </div>
            <a class="logout-btn" href="<?= htmlspecialchars(adminlens_url('/auth/logout.php?redirect=customer')) ?>">Logout</a>
        </div>

        <div class="card">
            <p class="meta">
                You are logged in successfully as a <strong>customer</strong>.
                This page is protected and only users with the customer role can access it.
            </p>
            <p class="meta">
                Stored session values:
                <br>user_id: <?= (int) ($user['user_id'] ?? 0) ?>
                <br>role: <?= htmlspecialchars((string) ($user['role'] ?? '')) ?>
                <br>full_name: <?= htmlspecialchars((string) ($user['full_name'] ?? '')) ?>
            </p>
        </div>
    </div>
</body>
</html>
