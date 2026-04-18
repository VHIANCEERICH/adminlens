<?php

declare(strict_types=1);

/**
 * Builds a URL relative to the project folder inside htdocs.
 */
function adminlens_url(string $path = ''): string
{
    $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? (string) $_SERVER['DOCUMENT_ROOT'] : '';
    $projectRoot = realpath(__DIR__ . '/..') ?: '';

    $documentRoot = str_replace('\\', '/', $documentRoot);
    $projectRoot = str_replace('\\', '/', $projectRoot);

    $basePath = '';
    if ($documentRoot !== '' && $projectRoot !== '' && str_starts_with($projectRoot, $documentRoot)) {
        $basePath = substr($projectRoot, strlen($documentRoot));
    }

    $basePath = '/' . trim($basePath, '/');
    if ($basePath === '/') {
        $basePath = '';
    }

    $path = '/' . ltrim($path, '/');
    return $basePath . $path;
}

/**
 * Starts a session only once and applies sensible defaults.
 */
function adminlens_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    ini_set('session.use_strict_mode', '1');
    session_start();
}

/**
 * Returns the correct login page for the requested role.
 */
function adminlens_login_path(string $role): string
{
    return $role === 'admin' ? adminlens_url('/admin/admin_login.php') : adminlens_url('/auth/login.php');
}

/**
 * Returns the dashboard path for the requested role.
 */
function adminlens_dashboard_path(string $role): string
{
    return $role === 'admin' ? adminlens_url('/admin/index.php') : adminlens_url('/customer/dashboard.php');
}

/**
 * Redirect helper used by login and dashboard pages.
 */
function adminlens_redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

/**
 * Returns the current logged-in user from session data.
 */
function adminlens_current_user(): ?array
{
    adminlens_start_session();

    if (empty($_SESSION['user_id']) || empty($_SESSION['role'])) {
        return null;
    }

    return [
        'user_id' => (int) $_SESSION['user_id'],
        'role' => (string) $_SESSION['role'],
        'full_name' => (string) ($_SESSION['full_name'] ?? ''),
    ];
}

/**
 * Enforces role-based access for protected pages.
 */
function adminlens_require_role(string $requiredRole): void
{
    $user = adminlens_current_user();

    if ($user === null) {
        adminlens_redirect(adminlens_login_path($requiredRole));
    }

    if ($user['role'] !== $requiredRole) {
        adminlens_redirect(adminlens_dashboard_path($user['role']));
    }
}

/**
 * Logs in a user by regenerating the session ID and storing the essentials.
 */
function adminlens_login_user(array $user): void
{
    adminlens_start_session();
    session_regenerate_id(true);

    $_SESSION['user_id'] = (int) $user['user_id'];
    $_SESSION['role'] = (string) $user['role'];
    $_SESSION['full_name'] = (string) $user['full_name'];
}

/**
 * Safely logs out the current user.
 */
function adminlens_logout_user(): void
{
    adminlens_start_session();

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            (bool) $params['secure'],
            (bool) $params['httponly']
        );
    }

    session_destroy();
}
