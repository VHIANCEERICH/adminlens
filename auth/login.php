<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

$pdo = adminlens_db();
$errors = [];
$identifier = '';

$currentUser = adminlens_current_user();
if ($currentUser !== null) {
    adminlens_redirect(adminlens_dashboard_path($currentUser['role']));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim((string) ($_POST['identifier'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($identifier === '') {
        $errors[] = 'Username or email is required.';
    }

    if ($password === '') {
        $errors[] = 'Password is required.';
    }

    if ($errors === []) {
        $stmt = $pdo->prepare(
            'SELECT user_id, full_name, username, email, password_hash, role, is_active
             FROM users
             WHERE username = :username OR email = :email
             LIMIT 1'
        );
        $stmt->execute([
            'username' => $identifier,
            'email' => $identifier,
        ]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, (string) $user['password_hash'])) {
            $errors[] = 'Invalid username/email or password.';
        } elseif ((int) $user['is_active'] !== 1) {
            $errors[] = 'Your account is inactive. Please contact support.';
        } elseif ((string) $user['role'] !== 'customer') {
            $errors[] = 'This login page is for customer accounts only.';
        } else {
            adminlens_login_user($user);
            adminlens_redirect(adminlens_url('/customer/dashboard.php'));
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Login</title>
    <style>
        :root {
            --bg: #edf7f2;
            --card: #ffffff;
            --text: #1f2937;
            --muted: #6b7280;
            --accent: #146c43;
            --accent-dark: #0f5333;
            --border: #cfe7d9;
            --error-bg: #fef2f2;
            --error-text: #b91c1c;
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            background: radial-gradient(circle at top, #f6fffb 0%, #dff2e7 100%);
            color: var(--text);
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
        }
        .login-card {
            width: 100%;
            max-width: 420px;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 32px;
            box-shadow: 0 20px 50px rgba(20, 108, 67, 0.12);
        }
        h1 {
            margin: 0 0 8px;
            font-size: 30px;
        }
        p {
            margin: 0 0 24px;
            color: var(--muted);
        }
        .error-box {
            background: var(--error-bg);
            color: var(--error-text);
            border: 1px solid #fecaca;
            border-radius: 12px;
            padding: 12px 14px;
            margin-bottom: 18px;
        }
        .form-group {
            margin-bottom: 16px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
        }
        input {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid var(--border);
            border-radius: 10px;
            font-size: 15px;
        }
        input:focus {
            outline: 2px solid rgba(20, 108, 67, 0.15);
            border-color: var(--accent);
        }
        button {
            width: 100%;
            border: 0;
            border-radius: 10px;
            padding: 13px 16px;
            background: var(--accent);
            color: #fff;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
        }
        button:hover {
            background: var(--accent-dark);
        }
        .switch-link {
            margin-top: 18px;
            text-align: center;
        }
        .switch-link a {
            color: var(--accent);
            text-decoration: none;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <h1>Customer Login</h1>
        <p>Enter your username or email to continue to your account.</p>

        <?php if ($errors !== []): ?>
            <div class="error-box">
                <?php foreach ($errors as $error): ?>
                    <div><?= htmlspecialchars($error) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="post" action="">
            <div class="form-group">
                <label for="identifier">Username or Email</label>
                <input
                    type="text"
                    id="identifier"
                    name="identifier"
                    value="<?= htmlspecialchars($identifier) ?>"
                    required
                >
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>

            <button type="submit">Login as Customer</button>
        </form>

        <div class="switch-link">
            <a href="<?= htmlspecialchars(adminlens_url('/admin/admin_login.php')) ?>">Admin login</a>
        </div>
    </div>
</body>
</html>
