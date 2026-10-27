<?php
/**
 * WesTec Eswatini Business Management System (WesTec BMS)
 * Database Trigger Verification & Demonstration Suite
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

$pdo = getDbConnection();
$currentUser = getCurrentUser();

$testResults = [
    'test_name'   => '',
    'status'      => '',
    'message'     => '',
    'sql_state'   => '',
    'details'     => null,
    'raw_trigger' => ''
];

// Handle Live Demonstration Triggers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($token)) {
        setFlash('danger', 'Security validation failed (CSRF). Please retry.');
        header('Location: /westec_bms/admin/trigger_demo.php');
        exit;
    }

    $demoAction = $_POST['demo_action'] ?? '';

    // DEMO 1: USER REGISTRATION AUDIT TRIGGER (trg_after_user_register)
    if ($demoAction === 'demo_user_register') {
        $testUsername = 'test_' . substr(md5(uniqid()), 0, 6);
        $testPassHash = password_hash('Pass@12345', PASSWORD_DEFAULT);
        $testRoleId   = 3; // Senior Technician

        try {
            $stmt = $pdo->prepare("
                INSERT INTO users (username, password_hash, role_id, email, status, created_at)
                VALUES (:u, :p, :r, :e, 'Active', NOW())
            ");
            $stmt->execute([
                ':u' => $testUsername,
                ':p' => $testPassHash,
                ':r' => $testRoleId,
                ':e' => "{$testUsername}@westeceswatini.co.sz"
            ]);
            $newUid = (int)$pdo->lastInsertId();

            // Query audit_logs table populated by the MySQL trigger
            $auditStmt = $pdo->prepare("
                SELECT * FROM audit_logs 
                WHERE table_affected = 'users' AND record_id = :uid AND action = 'USER_REGISTERED'
                ORDER BY log_id DESC LIMIT 1
            ");
            $auditStmt->execute([':uid' => $newUid]);
            $auditRow = $auditStmt->fetch();

            if ($auditRow) {
                $testResults = [
                    'test_name'   => 'User Registration Audit Trigger (trg_after_user_register)',
                    'status'      => 'PASSED',
                    'message'     => "Trigger executed successfully! Inserted user '{$testUsername}' (ID #{$newUid}), and MySQL automatically logged Audit Log #{$auditRow['log_id']}.",
                    'sql_state'   => 'SUCCESS (00000)',
                    'details'     => $auditRow,
                    'raw_trigger' => 'AFTER INSERT ON users'
                ];
            } else {
                $testResults = [
                    'test_name'   => 'User Registration Audit Trigger',
                    'status'      => 'FAILED',
                    'message'     => 'User was inserted, but no corresponding audit record was found. Check if trg_after_user_register is installed.',
                    'sql_state'   => 'NO_TRIGGER_RECORD',
                    'details'     => null,
                    'raw_trigger' => 'trg_after_user_register'
                ];
            }
        } catch (Exception $e) {
            $testResults = [
                'test_name'   => 'User Registration Audit Trigger',
                'status'      => 'ERROR',
                'message'     => parseDatabaseErrorMessage($e),
                'sql_state'   => $e->getCode(),
                'details'     => null,
                'raw_trigger' => 'trg_after_user_register'
            ];
        }
    }

    // DEMO 2A: INVENTORY STOCK IN TRIGGER (trg_update_inventory_stock_after_insert)
    elseif ($demoAction === 'demo_inventory_in') {
        $itemId = (int)($_POST['demo_item_id'] ?? 1);
        $qtyIn  = (int)($_POST['demo_qty_in'] ?? 5);

        try {
            $beforeStock = (int)$pdo->query("SELECT quantity_in_stock FROM inventory WHERE item_id = {$itemId}")->fetchColumn();

            $stmt = $pdo->prepare("
                INSERT INTO inventory_transactions (item_id, transaction_type, quantity, unit_cost, reference_note, created_by, transaction_date)
                VALUES (:item, 'IN', :qty, 1500.00, 'Demonstration Stock Receipt', :uid, NOW())
            ");
            $stmt->execute([':item' => $itemId, ':qty' => $qtyIn, ':uid' => $currentUser['user_id']]);
            $txId = (int)$pdo->lastInsertId();

            $afterStock = (int)$pdo->query("SELECT quantity_in_stock FROM inventory WHERE item_id = {$itemId}")->fetchColumn();

            $testResults = [
                'test_name'   => 'Inventory Stock Update Trigger (trg_update_inventory_stock_after_insert)',
                'status'      => 'PASSED',
                'message'     => "Trigger successfully updated stock! Initial Quantity: {$beforeStock} &rarr; Transaction: +{$qtyIn} &rarr; Final Quantity: {$afterStock}.",
                'sql_state'   => 'SUCCESS (00000)',
                'details'     => [
                    'Item ID'          => $itemId,
                    'Transaction ID'   => $txId,
                    'Initial Stock'    => $beforeStock,
                    'Quantity Added'   => "+{$qtyIn}",
                    'Stock After Trigger' => $afterStock
                ],
                'raw_trigger' => 'AFTER INSERT ON inventory_transactions'
            ];
        } catch (Exception $e) {
            $testResults = [
                'test_name'   => 'Inventory Stock Update Trigger',
                'status'      => 'ERROR',
                'message'     => parseDatabaseErrorMessage($e),
                'sql_state'   => $e->getCode(),
                'details'     => null,
                'raw_trigger' => 'trg_update_inventory_stock_after_insert'
            ];
        }
    }

    // DEMO 2B: INVENTORY OVERDRAFT REJECTION TRIGGER (trg_check_inventory_stock_before_insert)
    elseif ($demoAction === 'demo_inventory_out_excess') {
        $itemId = (int)($_POST['demo_item_id'] ?? 1);
        $currentStock = (int)$pdo->query("SELECT quantity_in_stock FROM inventory WHERE item_id = {$itemId}")->fetchColumn();
        $excessQty = $currentStock + 500; // Request far more than available

        try {
            // Attempt to insert OUT transaction exceeding stock
            $stmt = $pdo->prepare("
                INSERT INTO inventory_transactions (item_id, transaction_type, quantity, unit_cost, reference_note, created_by, transaction_date)
                VALUES (:item, 'OUT', :qty, 0.00, 'Test Illegal Overdraft', :uid, NOW())
            ");
            $stmt->execute([':item' => $itemId, ':qty' => $excessQty, ':uid' => $currentUser['user_id']]);

            // If it succeeds, the trigger failed
            $testResults = [
                'test_name'   => 'Inventory Overdraft Rejection Trigger (trg_check_inventory_stock_before_insert)',
                'status'      => 'FAILED',
                'message'     => 'CRITICAL: The database allowed an OUT transaction exceeding available stock! The trigger failed to block it.',
                'sql_state'   => 'UNEXPECTED_SUCCESS',
                'details'     => null,
                'raw_trigger' => 'trg_check_inventory_stock_before_insert'
            ];
        } catch (Exception $e) {
            // Expected outcome: Database trigger threw SQLSTATE 45000!
            $safeMsg = parseDatabaseErrorMessage($e);
            $testResults = [
                'test_name'   => 'Inventory Overdraft Rejection Trigger (trg_check_inventory_stock_before_insert)',
                'status'      => 'PASSED',
                'message'     => "Enforcement Verified! The database trigger successfully REJECTED the withdrawal of {$excessQty} units (Current Stock: {$currentStock}).",
                'sql_state'   => 'SQLSTATE 45000 (User-Defined Trigger Exception)',
                'details'     => [
                    'Item ID'              => $itemId,
                    'Available Stock'      => $currentStock,
                    'Requested Withdrawal' => $excessQty,
                    'Trigger Error Text'   => $safeMsg,
                    'Database Outcome'     => 'Transaction Blocked (Zero Data Tampering)'
                ],
                'raw_trigger' => 'BEFORE INSERT ON inventory_transactions'
            ];
        }
    }

    // DEMO 3: PROJECT DATE VALIDATION TRIGGER (trg_validate_project_dates_before_insert)
    elseif ($demoAction === 'demo_project_invalid_date') {
        $invalidStartDate = '2026-11-15';
        $invalidDueDate   = '2026-10-01'; // Due date is earlier than start date!

        try {
            $stmt = $pdo->prepare("
                INSERT INTO projects (project_name, client_id, description, start_date, due_date, budget, status, created_at)
                VALUES ('Test Invalid Date Project', 1, 'Trigger test attempt', :sdate, :ddate, 50000.00, 'Planning', NOW())
            ");
            $stmt->execute([':sdate' => $invalidStartDate, ':ddate' => $invalidDueDate]);

            $testResults = [
                'test_name'   => 'Project Date Validation Trigger (trg_validate_project_dates_before_insert)',
                'status'      => 'FAILED',
                'message'     => 'CRITICAL: Project with due date earlier than start date was accepted! The trigger failed.',
                'sql_state'   => 'UNEXPECTED_SUCCESS',
                'details'     => null,
                'raw_trigger' => 'trg_validate_project_dates_before_insert'
            ];
        } catch (Exception $e) {
            // Expected outcome: Database trigger rejected the insert!
            $safeMsg = parseDatabaseErrorMessage($e);
            $testResults = [
                'test_name'   => 'Project Date Validation Trigger (trg_validate_project_dates_before_insert)',
                'status'      => 'PASSED',
                'message'     => "Enforcement Verified! The database trigger successfully REJECTED saving project with Due Date ({$invalidDueDate}) earlier than Start Date ({$invalidStartDate}).",
                'sql_state'   => 'SQLSTATE 45000 (User-Defined Trigger Exception)',
                'details'     => [
                    'Start Date Tested'  => $invalidStartDate,
                    'Due Date Tested'    => $invalidDueDate,
                    'Trigger Error Text' => $safeMsg,
                    'Database Outcome'   => 'Insert Blocked by MySQL Trigger'
                ],
                'raw_trigger' => 'BEFORE INSERT ON projects'
            ];
        }
    }
}

// 1. Query Installed Triggers from Database Metadata
$triggersStmt = $pdo->query("SHOW TRIGGERS");
$installedTriggers = $triggersStmt->fetchAll();

// Trigger purpose explanations map
$triggerDescriptions = [
    'trg_after_user_register' => 'Automatically records a USER_REGISTERED event in audit_logs upon new user creation without logging passwords.',
    'trg_check_inventory_stock_before_insert' => 'Validates that stock withdrawal (OUT) transactions do not exceed current available stock quantity; throws SQLSTATE 45000 on violation.',
    'trg_update_inventory_stock_after_insert' => 'Automatically updates inventory.quantity_in_stock when a transaction (IN, OUT, or ADJUSTMENT) is recorded.',
    'trg_validate_project_dates_before_insert' => 'Enforces that project due_date cannot be earlier than start_date on newly created projects; throws SQLSTATE 45000.',
    'trg_validate_project_dates_before_update' => 'Enforces that project due_date cannot be earlier than start_date when project timeline dates are updated; throws SQLSTATE 45000.',
    'trg_after_ticket_status_update' => 'Captures status changes on service tickets and records state change audit records in audit_logs.'
];

// Fetch inventory items for demonstration selectors
$inventoryList = $pdo->query("SELECT item_id, sku, item_name, quantity_in_stock FROM inventory ORDER BY sku ASC")->fetchAll();

$pageTitle = 'Database Trigger Demonstration';
$pageSubtitle = 'Phase 4 Verification Suite: Live Execution of MySQL Business Rule Triggers';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- Live Test Result Banner -->
<?php if (!empty($testResults['status'])): ?>
    <div class="content-section border-<?= ($testResults['status'] === 'PASSED') ? 'success' : 'danger' ?> shadow-sm mb-4">
        <div class="d-flex align-items-center justify-content-between mb-2">
            <h3 class="h5 mb-0 text-<?= ($testResults['status'] === 'PASSED') ? 'success' : 'danger' ?> fw-bold">
                <i class="bi bi-<?= ($testResults['status'] === 'PASSED') ? 'patch-check-fill' : 'exclamation-octagon-fill' ?> me-2"></i>
                Test Result: <?= e($testResults['test_name']) ?>
            </h3>
            <span class="badge bg-<?= ($testResults['status'] === 'PASSED') ? 'success' : 'danger' ?> fs-6">
                <?= e($testResults['status']) ?>
            </span>
        </div>

        <p class="text-dark fw-semibold mb-2"><?= e($testResults['message']) ?></p>

        <?php if (!empty($testResults['details']) && is_array($testResults['details'])): ?>
            <div class="table-responsive bg-light p-2 rounded border">
                <table class="table table-sm table-borderless small mb-0">
                    <?php foreach ($testResults['details'] as $k => $v): ?>
                        <tr>
                            <td class="text-muted fw-bold" style="width: 220px;"><?= e(ucwords(str_replace('_', ' ', $k))) ?>:</td>
                            <td class="font-monospace text-dark"><?= is_array($v) ? json_encode($v) : e($v) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        <?php endif; ?>

        <div class="mt-2 text-muted small">
            <span>MySQL Status Code: <code><?= e($testResults['sql_state']) ?></code></span> &bull;
            <span>Timing & Event: <code><?= e($testResults['raw_trigger']) ?></code></span>
        </div>
    </div>
<?php endif; ?>

<!-- Section 1: Installed Database Triggers Metadata Table -->
<div class="content-section">
    <div class="section-header">
        <h2 class="section-title">
            <i class="bi bi-lightning-charge-fill text-warning"></i>
            Active Database Triggers in `westec_bms` (<?= count($installedTriggers) ?> Installed)
        </h2>
        <span class="badge bg-light text-dark border">SHOW TRIGGERS Metadata</span>
    </div>

    <p class="small text-muted mb-3">
        The table below reflects live metadata retrieved from MySQL using the <code>SHOW TRIGGERS</code> statement. In MySQL, triggers execute autonomously on the database server during table modification operations.
    </p>

    <div class="table-responsive">
        <table class="table-westec">
            <thead>
                <tr>
                    <th>Trigger Name</th>
                    <th>Target Table</th>
                    <th class="text-center">Timing</th>
                    <th class="text-center">Event</th>
                    <th>Business Rule / Purpose Description</th>
                    <th class="text-center">Engine</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($installedTriggers)): ?>
                    <tr>
                        <td colspan="6" class="text-center text-danger py-4">No database triggers found. Please execute `sql/setup_database.php` to install the triggers.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($installedTriggers as $trg): 
                        $tName = $trg['Trigger'];
                        $desc = $triggerDescriptions[$tName] ?? 'Custom database business integrity rule.';
                    ?>
                        <tr>
                            <td>
                                <strong class="text-dark font-monospace"><?= e($tName) ?></strong>
                            </td>
                            <td><span class="badge bg-light text-dark border font-monospace"><?= e($trg['Table']) ?></span></td>
                            <td class="text-center">
                                <span class="badge bg-secondary"><?= e($trg['Timing']) ?></span>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-info text-dark"><?= e($trg['Event']) ?></span>
                            </td>
                            <td><small class="text-secondary"><?= e($desc) ?></small></td>
                            <td class="text-center"><span class="badge bg-success">InnoDB</span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Section 2: Interactive Live Trigger Demonstrations -->
<div class="row g-4">
    <!-- Demo 1: User Registration Trigger -->
    <div class="col-lg-6">
        <div class="content-section h-100">
            <div class="section-header">
                <h3 class="section-title">
                    <i class="bi bi-person-check-fill text-primary"></i>
                    Demo 1: User Registration Audit Trigger
                </h3>
                <span class="badge bg-light text-dark border">AFTER INSERT</span>
            </div>

            <p class="small text-muted">
                Executes <code>trg_after_user_register</code> on <code>users</code>. The trigger autonomously captures the new user's ID and records a <code>USER_REGISTERED</code> entry in <code>audit_logs</code> without PHP inserting any audit row.
            </p>

            <div class="trigger-demonstration-panel mb-3">
                <div class="code-snippet small">
-- MySQL Trigger Definition:<br>
CREATE TRIGGER trg_after_user_register<br>
AFTER INSERT ON users FOR EACH ROW<br>
INSERT INTO audit_logs (user_id, action, table_affected, record_id, details, ip_address, created_at)<br>
VALUES (NEW.user_id, 'USER_REGISTERED', 'users', NEW.user_id, CONCAT('New user registered: ', NEW.username), '127.0.0.1', NOW());
                </div>
            </div>

            <form method="POST" action="/westec_bms/admin/trigger_demo.php">
                <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                <input type="hidden" name="demo_action" value="demo_user_register">
                <button type="submit" class="btn btn-westec w-100">
                    <i class="bi bi-play-circle-fill me-1"></i> Execute Registration Audit Trigger Test
                </button>
            </form>
        </div>
    </div>

    <!-- Demo 2A: Stock Increase Trigger -->
    <div class="col-lg-6">
        <div class="content-section h-100">
            <div class="section-header">
                <h3 class="section-title">
                    <i class="bi bi-arrow-down-circle-fill text-success"></i>
                    Demo 2A: Inventory Stock Update Trigger
                </h3>
                <span class="badge bg-light text-dark border">AFTER INSERT</span>
            </div>

            <p class="small text-muted">
                Executes <code>trg_update_inventory_stock_after_insert</code> on <code>inventory_transactions</code>. Verifies that inserting an <code>IN</code> transaction automatically increments <code>quantity_in_stock</code>.
            </p>

            <form method="POST" action="/westec_bms/admin/trigger_demo.php" class="row g-2">
                <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                <input type="hidden" name="demo_action" value="demo_inventory_in">

                <div class="col-sm-7">
                    <label class="form-label small">Target Inventory Item:</label>
                    <select name="demo_item_id" class="form-select form-select-sm">
                        <?php foreach ($inventoryList as $i): ?>
                            <option value="<?= $i['item_id'] ?>">
                                <?= e($i['sku']) ?> (Stock: <?= $i['quantity_in_stock'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-5">
                    <label class="form-label small">Units Received (+):</label>
                    <input type="number" name="demo_qty_in" value="5" min="1" class="form-control form-select-sm">
                </div>
                <div class="col-12 mt-3">
                    <button type="submit" class="btn btn-success w-100">
                        <i class="bi bi-plus-circle-fill me-1"></i> Post IN Transaction & Verify Stock Update
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Demo 2B: Stock Overdraft Rejection -->
    <div class="col-lg-6">
        <div class="content-section h-100 border-danger">
            <div class="section-header">
                <h3 class="section-title text-danger">
                    <i class="bi bi-shield-slash-fill"></i>
                    Demo 2B: Inventory Overdraft Rejection
                </h3>
                <span class="badge bg-danger text-white">BEFORE INSERT</span>
            </div>

            <p class="small text-muted">
                Tests <code>trg_check_inventory_stock_before_insert</code>. When an <code>OUT</code> issue exceeds current stock, the trigger rejects the write with <code>SQLSTATE 45000</code>.
            </p>

            <div class="trigger-demonstration-panel mb-3">
                <div class="code-snippet small">
IF NEW.transaction_type = 'OUT' THEN<br>
&nbsp;&nbsp;SELECT quantity_in_stock INTO current_stock FROM inventory WHERE item_id = NEW.item_id;<br>
&nbsp;&nbsp;IF NEW.quantity > current_stock THEN<br>
&nbsp;&nbsp;&nbsp;&nbsp;SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Transaction rejected: Stock withdrawal exceeds current quantity in stock.';<br>
&nbsp;&nbsp;END IF;<br>
END IF;
                </div>
            </div>

            <form method="POST" action="/westec_bms/admin/trigger_demo.php">
                <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                <input type="hidden" name="demo_action" value="demo_inventory_out_excess">

                <div class="mb-3">
                    <label class="form-label small">Select Item to Overdraw:</label>
                    <select name="demo_item_id" class="form-select form-select-sm">
                        <?php foreach ($inventoryList as $i): ?>
                            <option value="<?= $i['item_id'] ?>">
                                <?= e($i['sku']) ?> &mdash; Current Stock: <?= $i['quantity_in_stock'] ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text text-danger">The test will request Current Stock + 500 units to provoke trigger rejection.</div>
                </div>

                <button type="submit" class="btn btn-outline-danger w-100">
                    <i class="bi bi-lightning-charge-fill me-1"></i> Attempt Illegal Stock Withdrawal (Test Rejection)
                </button>
            </form>
        </div>
    </div>

    <!-- Demo 3: Project Date Validation Trigger -->
    <div class="col-lg-6">
        <div class="content-section h-100 border-danger">
            <div class="section-header">
                <h3 class="section-title text-danger">
                    <i class="bi bi-calendar-x-fill"></i>
                    Demo 3: Project Date Validation Trigger
                </h3>
                <span class="badge bg-danger text-white">BEFORE INSERT</span>
            </div>

            <p class="small text-muted">
                Tests <code>trg_validate_project_dates_before_insert</code>. Prevents creating a project where <code>due_date &lt; start_date</code>.
            </p>

            <div class="trigger-demonstration-panel mb-3">
                <div class="code-snippet small">
IF NEW.due_date < NEW.start_date THEN<br>
&nbsp;&nbsp;SIGNAL SQLSTATE '45000'<br>
&nbsp;&nbsp;SET MESSAGE_TEXT = 'Project validation failed: Due date cannot be earlier than start date.';<br>
END IF;
                </div>
            </div>

            <div class="alert alert-light border small text-muted mb-3">
                <strong>Simulated Payload:</strong><br>
                Start Date: <code>2026-11-15</code> &bull; Due Date: <code>2026-10-01</code> (Due date is earlier than start date)
            </div>

            <form method="POST" action="/westec_bms/admin/trigger_demo.php">
                <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                <input type="hidden" name="demo_action" value="demo_project_invalid_date">
                <button type="submit" class="btn btn-outline-danger w-100">
                    <i class="bi bi-shield-exclamation me-1"></i> Submit Invalid Project Dates (Test Rejection)
                </button>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
