<?php
/**
 * WesTec Eswatini Business Management System (WesTec BMS)
 * User Registration — Redirects to role-appropriate management panel.
 *
 * Direct self-registration is not permitted:
 *  - Administrators  → /admin/user_management.php  (manage all users, create managers)
 *  - IT Managers     → /manager/my_team.php        (add & manage their own staff)
 *  - All others      → Access Denied
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

if (hasRole('Administrator')) {
    header('Location: /westec_bms/admin/user_management.php');
    exit;
} elseif (hasRole('IT Manager')) {
    header('Location: /westec_bms/manager/my_team.php');
    exit;
} else {
    setFlash('warning', 'User accounts are created by the IT Manager or System Administrator.');
    header('Location: /westec_bms/dashboard/index.php');
    exit;
}
