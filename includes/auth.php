<?php
/**
 * WesTec Eswatini Business Management System (WesTec BMS)
 * Authentication, Authorization, and Session Management
 */

if (session_status() === PHP_SESSION_NONE) {
    // Configure secure session parameters
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => false, // Set to true if running over HTTPS in production
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

/**
 * Check if the client is currently authenticated.
 */
function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Retrieve current authenticated user session data.
 */
function getCurrentUser(): ?array {
    if (!isLoggedIn()) {
        return null;
    }
    return [
        'user_id'   => $_SESSION['user_id'],
        'username'  => $_SESSION['username'],
        'role_id'   => $_SESSION['role_id'] ?? null,
        'role_name' => $_SESSION['role_name'] ?? 'User',
        'email'     => $_SESSION['email'] ?? ''
    ];
}

/**
 * Enforce authentication on protected pages.
 */
function requireLogin(): void {
    if (!isLoggedIn()) {
        // Save target URL for post-login redirect
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
        setFlash('warning', 'Please log in with your credentials to access the requested system module.');
        header('Location: /westec_bms/auth/login.php');
        exit;
    }
}

/**
 * Check if current user has any of the specified roles.
 *
 * @param string|array $roles
 */
function hasRole(string|array $roles): bool {
    if (!isLoggedIn()) {
        return false;
    }
    $allowed = is_array($roles) ? $roles : [$roles];
    $currentRole = $_SESSION['role_name'] ?? '';
    
    foreach ($allowed as $role) {
        if (strcasecmp($currentRole, $role) === 0) {
            return true;
        }
    }
    return false;
}

/**
 * Enforce role-based authorization check on administrative pages.
 *
 * @param string|array $roles
 */
function requireRole(string|array $roles): void {
    requireLogin();
    if (!hasRole($roles)) {
        setFlash('danger', 'Access Denied: You do not possess the required administrative privileges for that resource.');
        header('Location: /westec_bms/dashboard/index.php');
        exit;
    }
}

/**
 * Check if user is an Administrator.
 */
function isAdmin(): bool {
    return hasRole('Administrator');
}

/**
 * Authenticate session and regenerate session ID to prevent fixation.
 */
function loginUser(array $user): void {
    session_regenerate_id(true);
    $_SESSION['user_id']   = (int)$user['user_id'];
    $_SESSION['username']  = $user['username'];
    $_SESSION['role_id']   = (int)$user['role_id'];
    $_SESSION['role_name'] = $user['role_name'] ?? 'User';
    $_SESSION['email']     = $user['email'] ?? '';
    $_SESSION['logged_at'] = time();
}

/**
 * Terminate session safely and destroy session cookies.
 */
function logoutUser(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }
    session_destroy();
}
