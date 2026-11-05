<?php
/**
 * Test Suite: Welcome Email Notification Service
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';

$pdo = getDbConnection();

echo "==============================================================================\n";
echo "WESTEC BMS — USER CREATION EMAIL NOTIFICATION TESTS\n";
echo "==============================================================================\n\n";

$passCount = 0;
$totalTests = 0;

function assertTest(bool $condition, string $title, string $details = ''): void {
    global $passCount, $totalTests;
    $totalTests++;
    if ($condition) {
        $passCount++;
        echo "[PASS] {$title}\n";
        if ($details) echo "       {$details}\n";
    } else {
        echo "[FAIL] {$title}\n";
        if ($details) echo "       {$details}\n";
    }
}

// Test 1: Invalid email rejection
$res1 = sendNewUserWelcomeEmail([
    'to_email' => 'invalid-email',
    'username' => 'testuser',
    'temporary_password' => 'Pass@123'
]);
assertTest($res1['success'] === false, 'Test 1: Invalid email address is safely rejected', 'Result message: ' . $res1['message']);

// Test 2: Missing password rejection
$res2 = sendNewUserWelcomeEmail([
    'to_email' => 'sipho@example.com',
    'username' => 'testuser',
    'temporary_password' => ''
]);
assertTest($res2['success'] === false, 'Test 2: Missing temporary password is safely rejected', 'Result message: ' . $res2['message']);

// Test 3: Successful welcome email dispatch
$testEmail = 'sipho.dlamini@westeceswatini.co.sz';
$testUser  = 'sipho_tech_' . substr(md5(uniqid()), 0, 4);
$testPass  = 'SecureTemp@2026';
$res3 = sendNewUserWelcomeEmail([
    'to_email'           => $testEmail,
    'full_name'          => 'Sipho Dlamini',
    'username'           => $testUser,
    'temporary_password' => $testPass,
    'role_name'          => 'Senior Technician',
    'created_by_name'    => 'admin (System Administrator)',
    'job_title'          => 'Network Infrastructure Lead',
    'department'         => 'IT Field Technical Services'
]);
assertTest($res3['success'] === true && !empty($res3['archive_file']), 'Test 3: Welcome email generated and archived successfully', 'Archive path: ' . ($res3['archive_file'] ?? 'none'));

// Test 4: Verify email content contains credentials and brand elements
$archiveContent = file_get_contents($res3['archive_file']);
$hasBrand = str_contains($archiveContent, 'WesTec Eswatini') && str_contains($archiveContent, 'WESTEC ESWATINI BMS');
$hasUser  = str_contains($archiveContent, $testUser);
$hasPass  = str_contains($archiveContent, $testPass);
$hasRole  = str_contains($archiveContent, 'Senior Technician');
$hasLink  = str_contains($archiveContent, '/westec_bms/auth/login.php');

assertTest(
    $hasBrand && $hasUser && $hasPass && $hasRole && $hasLink,
    'Test 4: Archived email contains credentials, role, login URL, and WesTec branding',
    "Contains username: " . ($hasUser ? 'Yes' : 'No') . ", password: " . ($hasPass ? 'Yes' : 'No') . ", role: " . ($hasRole ? 'Yes' : 'No')
);

// Test 5: Verify outbound email ledger JSON exists and contains the entry
$ledgerPath = __DIR__ . '/../storage/logs/outbound_emails.json';
$ledgerExists = file_exists($ledgerPath);
$ledgerData = $ledgerExists ? json_decode(file_get_contents($ledgerPath), true) : [];
$foundInLedger = false;
foreach ($ledgerData as $entry) {
    if (($entry['username'] ?? '') === $testUser) {
        $foundInLedger = true;
        break;
    }
}
assertTest($ledgerExists && $foundInLedger, 'Test 5: Outbound email logged in storage/logs/outbound_emails.json ledger', "Found ledger entry for '{$testUser}'");

// Test 6: Verify audit_logs contains WELCOME_EMAIL_DISPATCHED
$auditStmt = $pdo->prepare("SELECT * FROM audit_logs WHERE action = 'WELCOME_EMAIL_DISPATCHED' AND details LIKE ? ORDER BY log_id DESC LIMIT 1");
$auditStmt->execute(["%{$testUser}%"]);
$auditRow = $auditStmt->fetch();
assertTest(!empty($auditRow), 'Test 6: Database audit trail recorded WELCOME_EMAIL_DISPATCHED entry', 'Audit detail: ' . ($auditRow['details'] ?? 'none'));

// Cleanup test archive file
if (!empty($res3['archive_file']) && file_exists($res3['archive_file'])) {
    @unlink($res3['archive_file']);
}

echo "\n==============================================================================\n";
echo "SUMMARY: {$passCount} OF {$totalTests} TESTS PASSED\n";
echo "==============================================================================\n";

if ($passCount !== $totalTests) {
    exit(1);
}
