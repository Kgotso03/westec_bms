<?php
/**
 * WesTec Eswatini Business Management System (WesTec BMS)
 * User Registration Capturing Form & Trigger Demonstration
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

$pdo = getDbConnection();
$currentUser = getCurrentUser();
$isAdminUser = isAdmin();

// Retrieve available roles from database
$rolesStmt = $pdo->query("SELECT role_id, role_name, description FROM roles ORDER BY role_id ASC");
$allRoles = $rolesStmt->fetchAll();

// Enforce security rule: Unauthenticated visitors cannot select the Administrator role
$allowedRoles = [];
foreach ($allRoles as $r) {
    if (!$isAdminUser && strcasecmp($r['role_name'], 'Administrator') === 0) {
        continue; // Omit Administrator role for public visitors
    }
    $allowedRoles[] = $r;
}

$errors = [];
$successMessage = '';
$triggerAuditRecord = null;
$username = '';
$email = '';
$selectedRoleId = '';
$status = 'Active';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($token)) {
        $errors[] = 'Security validation failed (Invalid CSRF token). Please refresh and resubmit.';
    } else {
        $username        = cleanInput($_POST['username'] ?? '');
        $email           = cleanInput($_POST['email'] ?? '');
        $password        = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        $selectedRoleId  = (int)($_POST['role_id'] ?? 0);
        $status          = $isAdminUser ? cleanInput($_POST['status'] ?? 'Active') : 'Active';

        // 1. Validate required fields
        if (empty($username)) {
            $errors[] = 'Username is required.';
        } elseif (strlen($username) < 3 || strlen($username) > 50) {
            $errors[] = 'Username must be between 3 and 50 characters.';
        } elseif (!preg_match('/^[a-zA-Z0-9_.-]+$/', $username)) {
            $errors[] = 'Username may only contain letters, numbers, underscores, dots, and hyphens.';
        }

        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please provide a valid email address.';
        }

        // 2. Validate password
        if (empty($password)) {
            $errors[] = 'Password is required.';
        } elseif (strlen($password) < 6) {
            $errors[] = 'Password must be at least 6 characters long.';
        }

        if ($password !== $confirmPassword) {
            $errors[] = 'The password and confirm password fields do not match.';
        }

        // 3. Verify selected role exists and permissions
        $roleValid = false;
        $selectedRoleName = '';
        foreach ($allowedRoles as $r) {
            if ($r['role_id'] === $selectedRoleId) {
                $roleValid = true;
                $selectedRoleName = $r['role_name'];
                break;
            }
        }

        if (!$roleValid) {
            $errors[] = 'Please select a valid role. Non-administrators are strictly prohibited from selecting the Administrator role.';
        }

        // 4. Check for duplicate username
        if (empty($errors)) {
            $dupStmt = $pdo->prepare("SELECT user_id FROM users WHERE username = :username LIMIT 1");
            $dupStmt->execute([':username' => $username]);
            if ($dupStmt->fetch()) {
                $errors[] = "The username '{$username}' is already registered. Please choose another username.";
            }
        }

        // 5. Create user record and demonstrate trigger
        if (empty($errors)) {
            try {
                // Hash password securely with PHP password_hash() using PASSWORD_DEFAULT
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                // Insert into users table using PDO prepared statements
                $insertStmt = $pdo->prepare("
                    INSERT INTO users (username, password_hash, role_id, email, status, created_at)
                    VALUES (:username, :password_hash, :role_id, :email, :status, NOW())
                ");

                $insertStmt->execute([
                    ':username'      => $username,
                    ':password_hash' => $passwordHash,
                    ':role_id'       => $selectedRoleId,
                    ':email'         => !empty($email) ? $email : null,
                    ':status'        => $status
                ]);

                $newUserId = (int)$pdo->lastInsertId();

                // Retrieve the audit log record created automatically by MySQL database trigger `trg_after_user_register`
                $auditCheckStmt = $pdo->prepare("
                    SELECT log_id, user_id, action, table_affected, record_id, details, ip_address, created_at
                    FROM audit_logs
                    WHERE table_affected = 'users' 
                      AND record_id = :uid 
                      AND action = 'USER_REGISTERED'
                    ORDER BY log_id DESC
                    LIMIT 1
                ");
                $auditCheckStmt->execute([':uid' => $newUserId]);
                $triggerAuditRecord = $auditCheckStmt->fetch();

                $successMessage = "User '{$username}' was successfully registered in the database with User ID #{$newUserId}!";
                
                // Reset form values on success
                $username = '';
                $email = '';
                $selectedRoleId = '';
                $status = 'Active';

            } catch (Exception $e) {
                $errors[] = parseDatabaseErrorMessage($e);
            }
        }
    }
}

$pageTitle = 'User Registration';
$pageSubtitle = 'Create System Accounts & Demonstrate Registration Database Trigger';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row">
    <!-- Registration Capturing Form -->
    <div class="col-lg-7">
        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title">
                    <i class="bi bi-person-plus-fill text-primary"></i>
                    User Registration Form
                </h2>
                <span class="badge bg-light text-dark border">
                    <?= $isAdminUser ? 'Admin Mode (All Roles)' : 'Public Registration' ?>
                </span>
            </div>

            <?php if (!empty($successMessage)): ?>
                <div class="alert alert-success d-flex align-items-center mb-4" role="alert">
                    <i class="bi bi-check-circle-fill me-2 fs-5"></i>
                    <div class="flex-grow-1">
                        <strong>Success!</strong> <?= e($successMessage) ?>
                        <div class="mt-2">
                            <a href="/westec_bms/auth/login.php" class="btn btn-sm btn-success text-white">
                                <i class="bi bi-box-arrow-in-right me-1"></i> Proceed to Login Page
                            </a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger mb-4" role="alert">
                    <div class="d-flex align-items-center mb-2">
                        <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
                        <strong>Please correct the following errors:</strong>
                    </div>
                    <ul class="mb-0 ps-3">
                        <?php foreach ($errors as $err): ?>
                            <li><?= e($err) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="POST" action="/westec_bms/users/register.php" id="registrationForm" novalidate>
                <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">

                <div class="row g-3">
                    <!-- Username -->
                    <div class="col-md-6">
                        <label for="username" class="form-label">
                            Username <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control" id="username" name="username" value="<?= e($username) ?>" required placeholder="e.g. thabo_tech" autocomplete="off">
                        <div class="form-text">Must be unique across all system users.</div>
                    </div>

                    <!-- Email -->
                    <div class="col-md-6">
                        <label for="email" class="form-label">Email Address</label>
                        <input type="email" class="form-control" id="email" name="email" value="<?= e($email) ?>" placeholder="e.g. staff@westeceswatini.co.sz" autocomplete="off">
                        <div class="form-text">Used for communications and service alerts.</div>
                    </div>

                    <!-- Password -->
                    <div class="col-md-6">
                        <label for="password" class="form-label">
                            Password <span class="text-danger">*</span>
                        </label>
                        <input type="password" class="form-control" id="password" name="password" required autocomplete="new-password" placeholder="At least 6 characters">
                        <div class="form-text">Encrypted via <code>password_hash()</code> before storage.</div>
                    </div>

                    <!-- Confirm Password -->
                    <div class="col-md-6">
                        <label for="confirm_password" class="form-label">
                            Confirm Password <span class="text-danger">*</span>
                        </label>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required autocomplete="new-password" placeholder="Re-type password">
                        <div id="password_match_feedback" class="form-text"></div>
                    </div>

                    <!-- Role Dropdown (Dynamically Populated from Database) -->
                    <div class="col-md-6">
                        <label for="role_id" class="form-label">
                            Assigned System Role <span class="text-danger">*</span>
                        </label>
                        <select class="form-select" id="role_id" name="role_id" required>
                            <option value="">-- Select an Authorized Role --</option>
                            <?php foreach ($allowedRoles as $role): ?>
                                <option value="<?= $role['role_id'] ?>" <?= ($selectedRoleId == $role['role_id']) ? 'selected' : '' ?>>
                                    <?= e($role['role_name']) ?> &mdash; <?= e($role['description']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text text-muted">
                            Roles retrieved dynamically from MySQL <code>roles</code> table.
                        </div>
                    </div>

                    <!-- Account Status (Permitted only for Authenticated Administrators) -->
                    <div class="col-md-6">
                        <label for="status" class="form-label">Account Status</label>
                        <?php if ($isAdminUser): ?>
                            <select class="form-select" id="status" name="status">
                                <option value="Active" <?= ($status === 'Active') ? 'selected' : '' ?>>Active</option>
                                <option value="Inactive" <?= ($status === 'Inactive') ? 'selected' : '' ?>>Inactive</option>
                                <option value="Suspended" <?= ($status === 'Suspended') ? 'selected' : '' ?>>Suspended</option>
                            </select>
                            <div class="form-text">Administrator privilege: Set status directly.</div>
                        <?php else: ?>
                            <input type="text" class="form-control bg-light" value="Active (Default)" readonly>
                            <input type="hidden" name="status" value="Active">
                            <div class="form-text">New registrations default to Active status.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="d-flex align-items-center justify-content-between mt-4 pt-3 border-top">
                    <a href="/westec_bms/auth/login.php" class="text-decoration-none small text-muted">
                        <i class="bi bi-arrow-left me-1"></i> Return to Login
                    </a>
                    <button type="submit" class="btn btn-westec px-4 py-2">
                        <i class="bi bi-save-fill me-2"></i> Register System User
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Right Column: Trigger Verification & Architecture Details -->
    <div class="col-lg-5">
        <!-- Live Trigger Evidence Demonstration Box -->
        <div class="content-section">
            <div class="section-header">
                <h3 class="section-title">
                    <i class="bi bi-lightning-charge-fill text-warning"></i>
                    Trigger Evidence: trg_after_user_register
                </h3>
                <span class="badge bg-success">TRIGGER ACTIVE</span>
            </div>

            <p class="small text-muted">
                Per Phase 4 specifications, user creation executes MySQL trigger <code>trg_after_user_register</code> on the <code>users</code> table, which generates a row in <code>audit_logs</code> without PHP inserting the audit entry.
            </p>

            <?php if ($triggerAuditRecord): ?>
                <div class="alert alert-success border-success bg-light shadow-sm">
                    <div class="d-flex align-items-center mb-2">
                        <i class="bi bi-patch-check-fill text-success fs-4 me-2"></i>
                        <h4 class="h6 mb-0 text-success fw-bold">Live Trigger Execution Verified!</h4>
                    </div>
                    <p class="small text-dark mb-2">
                        The MySQL database trigger fired automatically upon row insertion into <code>users</code>.
                    </p>
                    <table class="table table-sm table-borderless small mb-0 bg-white border rounded">
                        <tr>
                            <td class="text-muted fw-bold">Audit Log ID:</td>
                            <td><span class="badge bg-dark">#<?= e($triggerAuditRecord['log_id']) ?></span></td>
                        </tr>
                        <tr>
                            <td class="text-muted fw-bold">Triggered Action:</td>
                            <td><span class="badge bg-primary"><?= e($triggerAuditRecord['action']) ?></span></td>
                        </tr>
                        <tr>
                            <td class="text-muted fw-bold">Affected Table:</td>
                            <td><code><?= e($triggerAuditRecord['table_affected']) ?></code></td>
                        </tr>
                        <tr>
                            <td class="text-muted fw-bold">Recorded User ID:</td>
                            <td><strong><?= e($triggerAuditRecord['user_id']) ?></strong></td>
                        </tr>
                        <tr>
                            <td class="text-muted fw-bold">Timestamp:</td>
                            <td><?= e($triggerAuditRecord['created_at']) ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted fw-bold align-top">Trigger Details:</td>
                            <td class="text-wrap small text-secondary"><?= e($triggerAuditRecord['details']) ?></td>
                        </tr>
                    </table>
                    <div class="mt-2 text-end">
                        <a href="/westec_bms/admin/audit_logs.php" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-journal-text me-1"></i> View in Full Audit Trail
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <div class="alert alert-light border small text-muted">
                    <i class="bi bi-info-circle me-1"></i> Submit the registration form on the left to witness live verification of the <code>trg_after_user_register</code> database trigger.
                </div>
            <?php endif; ?>

            <div class="trigger-demonstration-panel mt-3">
                <h4 class="h6 fw-bold text-dark mb-2">Trigger SQL Definition in Database:</h4>
                <div class="code-snippet">
CREATE TRIGGER trg_after_user_register<br>
AFTER INSERT ON users<br>
FOR EACH ROW<br>
BEGIN<br>
&nbsp;&nbsp;INSERT INTO audit_logs (<br>
&nbsp;&nbsp;&nbsp;&nbsp;user_id, action, table_affected,<br>
&nbsp;&nbsp;&nbsp;&nbsp;record_id, details, ip_address, created_at<br>
&nbsp;&nbsp;) VALUES (<br>
&nbsp;&nbsp;&nbsp;&nbsp;NEW.user_id, 'USER_REGISTERED', 'users',<br>
&nbsp;&nbsp;&nbsp;&nbsp;NEW.user_id, CONCAT('New user registered: ', NEW.username),<br>
&nbsp;&nbsp;&nbsp;&nbsp;'127.0.0.1', NOW()<br>
&nbsp;&nbsp;);<br>
END;
                </div>
            </div>

            <div class="mt-3">
                <h4 class="h6 fw-bold text-dark mb-2">Security Rules Enforced:</h4>
                <ul class="small text-muted mb-0 ps-3">
                    <li>Passwords hashed using PHP <code>password_hash(..., PASSWORD_DEFAULT)</code>.</li>
                    <li>Passwords and hashes are <strong>never</strong> recorded in the audit trail.</li>
                    <li>Unauthenticated visitors cannot select the <code>Administrator</code> role.</li>
                    <li>All queries executed using native PDO prepared statements.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
