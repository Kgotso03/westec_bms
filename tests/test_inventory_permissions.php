<?php
/**
 * WesTec Eswatini BMS
 * Inventory Granular Permission Tests — IT Manager Control
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

// Start a real PHP session so auth functions can use $_SESSION
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

echo "==============================================================================\n";
echo "INVENTORY ACCESS CONTROL — PERMISSION DELEGATION TESTS\n";
echo "==============================================================================\n\n";

$pdo = getDbConnection();

// Clean up any old test users from previous runs
$pdo->exec("DELETE FROM users WHERE username IN ('test_staff_inv', 'test_mgr_inv')");

// Create IT Manager test user  (role_id = 2 = IT Manager)
$pdo->prepare("INSERT INTO users (username, password_hash, role_id, email, status, created_at)
               VALUES ('test_mgr_inv', 'x', 2, 'mgr_inv@westec.sz', 'Active', NOW())")->execute();
$mgrId = (int)$pdo->lastInsertId();

// Create Staff test user  (role_id = 3 = Senior Technician)
$pdo->prepare("INSERT INTO users (username, password_hash, role_id, email, status, created_at)
               VALUES ('test_staff_inv', 'x', 3, 'staff_inv@westec.sz', 'Active', NOW())")->execute();
$staffId = (int)$pdo->lastInsertId();

$testsPassed = 0;
$totalTests  = 0;

function check($desc, bool $ok, string $detail = ''): void {
    global $testsPassed, $totalTests;
    $totalTests++;
    if ($ok) { $testsPassed++; echo "[PASS] {$desc}\n"; }
    else      { echo "[FAIL] {$desc}\n"; }
    if ($detail) echo "       {$detail}\n";
}

// ─── Test 1: IT Manager always has full access ───────────────────────────────
$_SESSION['user_id']    = $mgrId;
$_SESSION['role_name']  = 'IT Manager';
clearInventoryPermissionsCache();
$mgrPerms = getInventoryPermissions();
check(
    'IT Manager has unconditional full access (all 4 perms true)',
    $mgrPerms['can_view'] && $mgrPerms['can_add_item'] && $mgrPerms['can_edit_item'] && $mgrPerms['can_record_transaction'],
    'All 4 perms = true without any DB record'
);

// ─── Test 2: Staff user has ZERO access by default ───────────────────────────
$_SESSION['user_id']    = $staffId;
$_SESSION['role_name']  = 'Senior Technician';
clearInventoryPermissionsCache();
$defaultPerms = getInventoryPermissions();
check(
    'Staff has zero inventory access by default (no row in inventory_permissions)',
    !$defaultPerms['can_view'] && !$defaultPerms['can_add_item'] && !$defaultPerms['can_edit_item'] && !$defaultPerms['can_record_transaction'],
    'can_view=false, can_add_item=false, can_edit_item=false, can_record_transaction=false'
);

// ─── Test 3: Manager grants can_view + can_record_transaction ─────────────────
// Use explicit positional params to avoid PDO named-param duplicate issue
$pdo->prepare("
    INSERT INTO inventory_permissions (user_id, can_view, can_add_item, can_edit_item, can_record_transaction, granted_by)
    VALUES (?, 1, 0, 0, 1, ?)
    ON DUPLICATE KEY UPDATE can_view=1, can_add_item=0, can_edit_item=0, can_record_transaction=1, granted_by=?
")->execute([$staffId, $mgrId, $mgrId]);

clearInventoryPermissionsCache();
$grantedPerms = getInventoryPermissions();
check(
    'Granted permissions (can_view + can_record_transaction) are returned correctly',
    $grantedPerms['can_view'] && $grantedPerms['can_record_transaction'] && !$grantedPerms['can_add_item'] && !$grantedPerms['can_edit_item'],
    'can_view=true, can_record_tx=true, can_add_item=false, can_edit_item=false'
);

// ─── Test 4: hasInventoryPermission() helper ──────────────────────────────────
clearInventoryPermissionsCache();
check(
    'hasInventoryPermission("can_view") returns true, "can_add_item" returns false',
    hasInventoryPermission('can_view') === true && hasInventoryPermission('can_add_item') === false
);

// ─── Test 5: Full grant (all 4 perms) ─────────────────────────────────────────
$pdo->prepare("
    UPDATE inventory_permissions SET can_view=1, can_add_item=1, can_edit_item=1, can_record_transaction=1 WHERE user_id=?
")->execute([$staffId]);
clearInventoryPermissionsCache();
$fullPerms = getInventoryPermissions();
check(
    'Full permission grant returns all 4 perms as true',
    $fullPerms['can_view'] && $fullPerms['can_add_item'] && $fullPerms['can_edit_item'] && $fullPerms['can_record_transaction']
);

// ─── Test 6: Manager revokes all permissions ──────────────────────────────────
$pdo->prepare("
    UPDATE inventory_permissions SET can_view=0, can_add_item=0, can_edit_item=0, can_record_transaction=0 WHERE user_id=?
")->execute([$staffId]);
clearInventoryPermissionsCache();
$revokedPerms = getInventoryPermissions();
check(
    'Revoking all permissions brings staff back to zero access',
    !$revokedPerms['can_view'] && !$revokedPerms['can_add_item'] && !$revokedPerms['can_edit_item'] && !$revokedPerms['can_record_transaction']
);

// ─── Test 7: inventory_permissions table exists in live DB ────────────────────
$tableCheck = $pdo->query("SHOW TABLES LIKE 'inventory_permissions'")->fetchColumn();
check(
    'inventory_permissions table exists in live westec_bms database',
    $tableCheck === 'inventory_permissions',
    "Table lookup returned: " . ($tableCheck ?: 'NOT FOUND')
);

// ─── Cleanup ──────────────────────────────────────────────────────────────────
$pdo->exec("DELETE FROM users WHERE user_id IN ($mgrId, $staffId)");
unset($_SESSION['user_id'], $_SESSION['role_name']);
clearInventoryPermissionsCache();

echo "\n==============================================================================\n";
echo "RESULT: {$testsPassed} OF {$totalTests} TESTS PASSED";
echo ($testsPassed === $totalTests ? " ✓  ALL CLEAR\n" : "\n  !! SOME TESTS FAILED\n");
echo "==============================================================================\n";
