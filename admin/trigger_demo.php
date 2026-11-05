<?php
/**
 * WesTec Eswatini Business Management System (WesTec BMS)
 * Trigger Demo — Removed & Redirected to Dashboard.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

header('Location: /westec_bms/dashboard/index.php');
exit;
