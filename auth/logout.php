<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';

$user = adminlens_current_user();
$redirectTarget = (string) ($_GET['redirect'] ?? '');

adminlens_logout_user();

if ($redirectTarget === 'admin') {
    adminlens_redirect(adminlens_url('/admin/admin_login.php'));
}

if ($redirectTarget === 'customer') {
    adminlens_redirect(adminlens_url('/auth/login.php'));
}

if ($user !== null && $user['role'] === 'admin') {
    adminlens_redirect(adminlens_url('/admin/admin_login.php'));
}

adminlens_redirect(adminlens_url('/auth/login.php'));
