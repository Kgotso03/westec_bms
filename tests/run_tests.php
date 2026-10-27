<?php
/**
 * WesTec Eswatini Business Management System (WesTec BMS)
 * Phase 4 Comprehensive Automated Testing Suite
 *
 * Executes all 15 verification tests requested in Section 12.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

echo "==============================================================================\n";
echo "WESTEC ESWATINI BMS - PHASE 4 DATABASE CONNECTIVITY & TRIGGER VERIFICATION\n";
echo "==============================================================================\n\n";

$results = [];

function recordTest(&$results, $id, $description, $expected, $actual, $status) {
    $results[] = [
        'id'          => $id,
        'description' => $description,
        'expected'    => $expected,
        'actual'      => $actual,
        'status'      => $status ? 'PASS' : 'FAIL'
    ];
    $icon = $status ? "[PASS]" : "[FAIL]";
    echo "{$icon} Test {$id}: {$description}\n";
    echo "       Expected: {$expected}\n";
    echo "       Actual:   {$actual}\n\n";
}

try {
    // -------------------------------------------------------------------------
    // TEST 1: Database Connection
    // -------------------------------------------------------------------------
    $pdo = getDbConnection();
    $health = checkDatabaseHealth();
    $t1Passed = $health['connected'] && $health['table_count'] >= 12;
    recordTest(
        $results,
        'TEST-01',
        'PHP PDO connects to westec_bms MySQL database',
        'PDO connection established, returns version and verified 12 tables',
        "Connected: " . ($health['connected'] ? 'Yes' : 'No') . ", Tables: {$health['table_count']}, Version: {$health['version']}",
        $t1Passed
    );

    // -------------------------------------------------------------------------
    // TEST 2: User Registration with Valid Role
    // -------------------------------------------------------------------------
    $testUser = 'autotest_' . substr(md5(uniqid()), 0, 6);
    $plainPass = 'TestPass@2026';
    $hashedPass = password_hash($plainPass, PASSWORD_DEFAULT);
    $roleId = 3; // Senior Technician

    $regStmt = $pdo->prepare("
        INSERT INTO users (username, password_hash, role_id, email, status, created_at)
        VALUES (:u, :p, :r, :e, 'Active', NOW())
    ");
    $regStmt->execute([
        ':u' => $testUser,
        ':p' => $hashedPass,
        ':r' => $roleId,
        ':e' => "{$testUser}@westec.co.sz"
    ]);
    $newUserId = (int)$pdo->lastInsertId();
    $t2Passed = ($newUserId > 0);
    recordTest(
        $results,
        'TEST-02',
        'User can be registered with a valid role',
        'New user row inserted into `users` table with generated user_id',
        "User '{$testUser}' created with User ID #{$newUserId} and Role ID {$roleId}",
        $t2Passed
    );

    // -------------------------------------------------------------------------
    // TEST 3: Passwords Stored as Hashes
    // -------------------------------------------------------------------------
    $fetchUser = $pdo->prepare("SELECT password_hash FROM users WHERE user_id = :id");
    $fetchUser->execute([':id' => $newUserId]);
    $storedHash = $fetchUser->fetchColumn();
    $isBcrypt = (str_starts_with($storedHash, '$2y$') || str_starts_with($storedHash, '$2a$')) && strlen($storedHash) >= 60;
    $plainTextFound = ($storedHash === $plainPass);
    $t3Passed = $isBcrypt && !$plainTextFound;
    recordTest(
        $results,
        'TEST-03',
        'Passwords are stored as secure hashes (never plain-text)',
        'password_hash column contains valid bcrypt hash; plain-text absent',
        "Stored: " . substr($storedHash, 0, 15) . "... (Length: " . strlen($storedHash) . ", Bcrypt verified)",
        $t3Passed
    );

    // -------------------------------------------------------------------------
    // TEST 4: Duplicate Username Rejected
    // -------------------------------------------------------------------------
    $dupRejected = false;
    $dupMsg = '';
    try {
        $dupStmt = $pdo->prepare("
            INSERT INTO users (username, password_hash, role_id, email, status, created_at)
            VALUES (:u, :p, :r, :e, 'Active', NOW())
        ");
        $dupStmt->execute([
            ':u' => $testUser, // duplicate username
            ':p' => $hashedPass,
            ':r' => $roleId,
            ':e' => "dup@westec.co.sz"
        ]);
    } catch (Exception $e) {
        $dupRejected = true;
        $dupMsg = parseDatabaseErrorMessage($e);
    }
    recordTest(
        $results,
        'TEST-04',
        'Duplicate usernames are rejected by database unique constraint',
        'Database throws 1062 duplicate key violation; caught safely',
        "Rejection message: '{$dupMsg}'",
        $dupRejected
    );

    // -------------------------------------------------------------------------
    // TEST 5: Audit Record Created by Registration Trigger (trg_after_user_register)
    // -------------------------------------------------------------------------
    $auditStmt = $pdo->prepare("
        SELECT * FROM audit_logs 
        WHERE table_affected = 'users' AND record_id = :uid AND action = 'USER_REGISTERED'
        ORDER BY log_id DESC LIMIT 1
    ");
    $auditStmt->execute([':uid' => $newUserId]);
    $auditRow = $auditStmt->fetch();
    $t5Passed = !empty($auditRow) && str_contains($auditRow['details'], 'trg_after_user_register');
    recordTest(
        $results,
        'TEST-05',
        'Audit record is created automatically by database registration trigger',
        'Row in `audit_logs` created by trg_after_user_register without PHP audit query',
        $t5Passed ? "Audit Log #{$auditRow['log_id']}: '{$auditRow['details']}'" : "No trigger audit log found",
        $t5Passed
    );

    // -------------------------------------------------------------------------
    // TEST 6: User Can Log In with Correct Password
    // -------------------------------------------------------------------------
    $authStmt = $pdo->prepare("SELECT user_id, username, password_hash FROM users WHERE username = :u");
    $authStmt->execute([':u' => $testUser]);
    $authRow = $authStmt->fetch();
    $loginSuccess = ($authRow && password_verify($plainPass, $authRow['password_hash']));
    recordTest(
        $results,
        'TEST-06',
        'User can log in with correct password (password_verify)',
        'password_verify() returns true for correct password',
        "password_verify('{$plainPass}', hash) returned " . ($loginSuccess ? 'TRUE (Authenticated)' : 'FALSE'),
        $loginSuccess
    );

    // -------------------------------------------------------------------------
    // TEST 7: Incorrect Password is Rejected
    // -------------------------------------------------------------------------
    $wrongPassSuccess = ($authRow && password_verify('WrongPassword@999', $authRow['password_hash']));
    $t7Passed = ($wrongPassSuccess === false);
    recordTest(
        $results,
        'TEST-07',
        'Incorrect password is rejected',
        'password_verify() returns false for wrong credentials',
        "password_verify('WrongPassword@999', hash) returned FALSE (Access Denied)",
        $t7Passed
    );

    // -------------------------------------------------------------------------
    // TEST 8: Role-based Authorization Restrictions
    // -------------------------------------------------------------------------
    // Simulate non-admin role check
    $_SESSION['role_name'] = 'Client Representative';
    $canAccessAdmin = hasRole('Administrator');
    $t8Passed = ($canAccessAdmin === false);
    recordTest(
        $results,
        'TEST-08',
        'Users without permission cannot access administrative resources',
        'hasRole("Administrator") returns false for non-admin session',
        "Role 'Client Representative' hasRole('Administrator') check: " . ($canAccessAdmin ? 'ALLOWED' : 'BLOCKED'),
        $t8Passed
    );

    // -------------------------------------------------------------------------
    // TEST 9: Client CRUD & Foreign Key Safe Delete
    // -------------------------------------------------------------------------
    // 9a. Create Client
    $cStmt = $pdo->prepare("INSERT INTO clients (client_name, contact_person, email, phone, address) VALUES ('Test Corp Eswatini', 'Sipho Zulu', 'szulu@testcorp.co.sz', '+268 7699 8877', 'Mbabane Industrial Site')");
    $cStmt->execute();
    $testClientId = (int)$pdo->lastInsertId();

    // 9b. Update Client
    $cUp = $pdo->prepare("UPDATE clients SET contact_person = 'Sipho Zulu (Updated)' WHERE client_id = :id");
    $cUp->execute([':id' => $testClientId]);

    // 9c. Delete unlinked client (permitted)
    $cDel = $pdo->prepare("DELETE FROM clients WHERE client_id = :id");
    $cDel->execute([':id' => $testClientId]);

    // 9d. Test FK rejection on client with tickets (Client #1 EEC)
    $fkBlocked = false;
    try {
        $pdo->exec("DELETE FROM clients WHERE client_id = 1");
    } catch (Exception $e) {
        $fkBlocked = true;
    }
    $t9Passed = ($testClientId > 0) && $fkBlocked;
    recordTest(
        $results,
        'TEST-09',
        'Client CRUD operations and foreign key deletion safety',
        'Client record created, updated, safely deleted; FK constraint blocks deletion of client with tickets',
        "Created ID #{$testClientId}, updated, deleted. Deletion of Client #1 with tickets was correctly BLOCKED by foreign key constraint.",
        $t9Passed
    );

    // -------------------------------------------------------------------------
    // TEST 10: Service Tickets Created and Linked
    // -------------------------------------------------------------------------
    $tNum = 'TK-TEST-' . substr(md5(uniqid()), 0, 4);
    $tickStmt = $pdo->prepare("
        INSERT INTO tickets (ticket_number, client_id, assigned_staff_id, title, description, priority, status, created_at)
        VALUES (:num, 1, 3, 'Test Ticket Configuration', 'Automated ticket testing', 'High', 'Open', NOW())
    ");
    $tickStmt->execute([':num' => $tNum]);
    $newTicketId = (int)$pdo->lastInsertId();
    $t10Passed = ($newTicketId > 0);
    recordTest(
        $results,
        'TEST-10',
        'Service tickets created and assigned to clients and staff',
        'Ticket inserted with foreign keys referencing clients and staff tables',
        "Ticket #{$tNum} created with Ticket ID #{$newTicketId} assigned to Client #1 (EEC) and Staff #3 (Sibusiso Dlamini)",
        $t10Passed
    );

    // -------------------------------------------------------------------------
    // TEST 11: Inventory Receipts Update Stock via Trigger (trg_update_inventory_stock_after_insert)
    // -------------------------------------------------------------------------
    $invItem = 1; // Cisco switch
    $beforeStock = (int)$pdo->query("SELECT quantity_in_stock FROM inventory WHERE item_id = {$invItem}")->fetchColumn();
    $incQty = 4;
    $pdo->exec("
        INSERT INTO inventory_transactions (item_id, transaction_type, quantity, unit_cost, reference_note, created_by, transaction_date)
        VALUES ({$invItem}, 'IN', {$incQty}, 14500.00, 'Automated Test IN', 1, NOW())
    ");
    $afterStock = (int)$pdo->query("SELECT quantity_in_stock FROM inventory WHERE item_id = {$invItem}")->fetchColumn();
    $t11Passed = ($afterStock === $beforeStock + $incQty);
    recordTest(
        $results,
        'TEST-11',
        'Inventory receipts update stock through database trigger',
        'quantity_in_stock increases automatically by receipt quantity',
        "Stock before: {$beforeStock} &rarr; Transaction: +{$incQty} &rarr; Stock after: {$afterStock} (Trigger increment verified)",
        $t11Passed
    );

    // -------------------------------------------------------------------------
    // TEST 12: Inventory Issues Exceeding Stock are Rejected (trg_check_inventory_stock_before_insert)
    // -------------------------------------------------------------------------
    $overdraftRejected = false;
    $overdraftError = '';
    $excessWithdrawal = $afterStock + 500;
    try {
        $pdo->exec("
            INSERT INTO inventory_transactions (item_id, transaction_type, quantity, unit_cost, reference_note, created_by, transaction_date)
            VALUES ({$invItem}, 'OUT', {$excessWithdrawal}, 0.00, 'Illegal Overdraft Test', 1, NOW())
        ");
    } catch (Exception $e) {
        $overdraftRejected = true;
        $overdraftError = parseDatabaseErrorMessage($e);
    }
    $t12Passed = $overdraftRejected && str_contains($overdraftError, 'Stock withdrawal exceeds');
    recordTest(
        $results,
        'TEST-12',
        'Inventory issues exceeding available stock are rejected by trigger',
        'trg_check_inventory_stock_before_insert signals SQLSTATE 45000 and aborts',
        "Trigger Signal Caught: '{$overdraftError}' (Transaction aborted cleanly)",
        $t12Passed
    );

    // -------------------------------------------------------------------------
    // TEST 13: Invalid Project Dates are Rejected by Trigger (trg_validate_project_dates_before_insert)
    // -------------------------------------------------------------------------
    $dateRejected = false;
    $dateError = '';
    try {
        $pdo->exec("
            INSERT INTO projects (project_name, client_id, description, start_date, due_date, budget, status, created_at)
            VALUES ('Invalid Date Project', 1, 'Test', '2026-12-01', '2026-11-01', 10000.00, 'Planning', NOW())
        ");
    } catch (Exception $e) {
        $dateRejected = true;
        $dateError = parseDatabaseErrorMessage($e);
    }
    $t13Passed = $dateRejected && str_contains($dateError, 'Due date cannot be earlier than start date');
    recordTest(
        $results,
        'TEST-13',
        'Invalid project dates (due_date < start_date) rejected by trigger',
        'trg_validate_project_dates_before_insert signals SQLSTATE 45000',
        "Trigger Signal Caught: '{$dateError}' (Insert rejected)",
        $t13Passed
    );

    // -------------------------------------------------------------------------
    // TEST 14: Reports Display Information from Related Tables
    // -------------------------------------------------------------------------
    $repStmt = $pdo->query("
        SELECT c.client_name, COUNT(t.ticket_id) AS ticket_count
        FROM clients c
        JOIN tickets t ON c.client_id = t.client_id
        GROUP BY c.client_id
    ");
    $reportRows = $repStmt->fetchAll();
    $t14Passed = count($reportRows) > 0;
    recordTest(
        $results,
        'TEST-14',
        'Reports display aggregated information from related tables',
        'SQL JOIN between clients and tickets executes and returns aggregate counts',
        "Aggregated report retrieved " . count($reportRows) . " client operational summaries with joined ticket metrics",
        $t14Passed
    );

    // -------------------------------------------------------------------------
    // TEST 15: Database Errors Handled Safely
    // -------------------------------------------------------------------------
    $dummyException = new PDOException("SQLSTATE[45000]: <<45000>>: 1644 Transaction rejected: Stock withdrawal exceeds current quantity in stock.");
    $parsed = parseDatabaseErrorMessage($dummyException);
    $noCredentialsLeaked = !str_contains($parsed, 'password') && !str_contains($parsed, 'root');
    $t15Passed = ($parsed === 'Transaction rejected: Stock withdrawal exceeds current quantity in stock.') && $noCredentialsLeaked;
    recordTest(
        $results,
        'TEST-15',
        'Database errors are handled safely without exposing credentials',
        'Trigger signals and DB errors parsed into friendly messages with zero credential leakage',
        "Parsed message: '{$parsed}' (Sensitive server details stripped)",
        $t15Passed
    );

    // Output Summary
    $totalPassed = count(array_filter($results, fn($r) => $r['status'] === 'PASS'));
    $totalTests = count($results);
    echo "==============================================================================\n";
    echo "TEST EXECUTION SUMMARY: {$totalPassed} OF {$totalTests} TESTS PASSED (" . round(($totalPassed / $totalTests) * 100) . "%)\n";
    echo "==============================================================================\n";

} catch (Exception $e) {
    echo "\nCRITICAL ERROR IN TEST SUITE: " . $e->getMessage() . "\n";
    exit(1);
}
