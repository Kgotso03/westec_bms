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
        'user_id'       => $_SESSION['user_id'],
        'username'      => $_SESSION['username'],
        'role_id'       => $_SESSION['role_id'] ?? null,
        'role_name'     => $_SESSION['role_name'] ?? 'User',
        'email'         => $_SESSION['email'] ?? '',
        'profile_photo' => $_SESSION['profile_photo'] ?? null,
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
    $currentRole = strtolower(trim($_SESSION['role_name'] ?? ''));
    
    $adminAliases = ['administrator', 'system administrator', 'admin', 'system admin'];

    foreach ($allowed as $role) {
        $checkRole = strtolower(trim($role));
        if ($currentRole === $checkRole) {
            return true;
        }
        // Match Administrator aliases interchangeably
        if (in_array($checkRole, $adminAliases, true) && in_array($currentRole, $adminAliases, true)) {
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
    $_SESSION['user_id']       = (int)$user['user_id'];
    $_SESSION['username']      = $user['username'];
    $_SESSION['role_id']       = (int)$user['role_id'];
    $_SESSION['role_name']     = $user['role_name'] ?? 'User';
    $_SESSION['email']         = $user['email'] ?? '';
    $_SESSION['profile_photo'] = $user['profile_photo'] ?? null;
    $_SESSION['logged_at']     = time();
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

/**
 * Retrieve the current user's inventory access permissions from the database.
 * Administrators and IT Managers always receive full access.
 * Other roles must have an explicit row in inventory_permissions.
 *
 * Returns an array with keys: can_view, can_add_item, can_edit_item, can_record_transaction.
 */
function getInventoryPermissions(): array {
    // Full access for admin and IT manager – no DB lookup needed
    if (hasRole(['Administrator', 'IT Manager'])) {
        return [
            'can_view'               => true,
            'can_add_item'           => true,
            'can_edit_item'          => true,
            'can_record_transaction' => true,
        ];
    }

    if (!isLoggedIn()) {
        return ['can_view' => false, 'can_add_item' => false, 'can_edit_item' => false, 'can_record_transaction' => false];
    }

    // Check session cache to avoid repeated DB hits on the same request
    if (isset($_SESSION['inv_permissions'])) {
        return $_SESSION['inv_permissions'];
    }

    try {
        require_once __DIR__ . '/../config/database.php';
        $pdo  = getDbConnection();
        $stmt = $pdo->prepare("SELECT can_view, can_add_item, can_edit_item, can_record_transaction FROM inventory_permissions WHERE user_id = :uid LIMIT 1");
        $stmt->execute([':uid' => $_SESSION['user_id']]);
        $row  = $stmt->fetch();

        $perms = [
            'can_view'               => $row ? (bool)$row['can_view']               : false,
            'can_add_item'           => $row ? (bool)$row['can_add_item']           : false,
            'can_edit_item'          => $row ? (bool)$row['can_edit_item']          : false,
            'can_record_transaction' => $row ? (bool)$row['can_record_transaction'] : false,
        ];
    } catch (Exception $e) {
        $perms = ['can_view' => false, 'can_add_item' => false, 'can_edit_item' => false, 'can_record_transaction' => false];
    }

    $_SESSION['inv_permissions'] = $perms;
    return $perms;
}

/**
 * Check whether the current user holds a specific inventory permission.
 *
 * @param string $permission  One of: can_view, can_add_item, can_edit_item, can_record_transaction
 */
function hasInventoryPermission(string $permission): bool {
    $perms = getInventoryPermissions();
    return $perms[$permission] ?? false;
}

/**
 * Clear cached inventory permissions from the session (call after a manager changes a user's grants).
 */
function clearInventoryPermissionsCache(): void {
    unset($_SESSION['inv_permissions']);
}
