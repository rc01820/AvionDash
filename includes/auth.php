<?php
// ============================================================
// auth.php — Session & Authentication Helpers
// ============================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

function session_start_secure(): void {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure',   0);  // Set to 1 in production with HTTPS
    ini_set('session.use_strict_mode', 1);
    session_name(SESSION_NAME);
    session_start();
}

function auth_check(): void {
    if (!isset($_SESSION['user_id'])) {
        header('Location: ' . APP_BASE . '/login.php');
        exit;
    }
    // Session timeout check
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT) {
        session_unset();
        session_destroy();
        header('Location: ' . APP_BASE . '/login.php?reason=timeout');
        exit;
    }
    $_SESSION['last_activity'] = time();
}

function auth_login(string $username, string $password): bool {
    $username = trim($username);
    if (empty($username) || empty($password)) return false;

    try {
        $rows = DB::query(
            "SELECT user_id, username, password_hash, full_name, role
             FROM users WHERE username = ? AND is_active = 1",
            [$username]
        );

        if (empty($rows)) return false;
        $user = $rows[0];

        if (!password_verify($password, $user['password_hash'])) {
            return false;
        }

        DB::query(
            "UPDATE users SET last_login = NOW() WHERE user_id = ?",
            [$user['user_id']]
        );

        session_regenerate_id(true);

        $_SESSION['user_id']       = $user['user_id'];
        $_SESSION['username']      = $user['username'];
        $_SESSION['full_name']     = $user['full_name'];
        $_SESSION['role']          = $user['role'];
        $_SESSION['last_activity'] = time();

        return true;
    } catch (RuntimeException $e) {
        return false;
    }
}

function auth_logout(): void {
    session_unset();
    session_destroy();
    header('Location: ' . APP_BASE . '/login.php?reason=logout');
    exit;
}

function current_user(): array {
    return [
        'id'        => $_SESSION['user_id']   ?? 0,
        'username'  => $_SESSION['username']  ?? '',
        'full_name' => $_SESSION['full_name'] ?? '',
        'role'      => $_SESSION['role']      ?? 'viewer',
    ];
}

function has_role(string ...$roles): bool {
    $userRole = $_SESSION['role'] ?? '';
    return in_array($userRole, $roles, true);
}
