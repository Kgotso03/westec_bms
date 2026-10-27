<?php
/**
 * WesTec Eswatini Business Management System (WesTec BMS)
 * User Logout Handler
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

$currentUser = getCurrentUser();

if ($currentUser) {
    try {
        $pdo = getDbConnection();
        $auditStmt = $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, table_affected, record_id, details, ip_address, created_at)
            VALUES (:uid, 'USER_LOGOUT', 'users', :rec_id, 'User signed out securely from session.', :ip, NOW())
        ");
        $auditStmt->execute([
            ':uid'    => $currentUser['user_id'],
            ':rec_id' => $currentUser['user_id'],
            ':ip'     => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
        ]);
    } catch (Exception $e) {
        // Continue logout even if audit fails
    }
}

logoutUser();

// Start fresh session just to hold flash notification
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
setFlash('info', 'You have been safely signed out of the WesTec Eswatini management system.');
header('Location: /westec_bms/auth/login.php');
exit;
