<?php
/**
 * WesTec Eswatini Business Management System (WesTec BMS)
 * My Team — IT Manager Panel
 *
 * IT Manager can:
 *  - Add new Staff accounts (Senior Technician, Support Specialist, Client Representative)
 *  - Edit staff details (username, email, full name, phone, job title, department, role, status)
 *  - Reset staff passwords
 *  - Delete staff accounts
 *  - Activate / Deactivate / Suspend team members
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';

requireLogin();

if (!hasRole(['IT Manager', 'Administrator'])) {
    setFlash('danger', 'Access Denied: This panel is for IT Managers only.');
    header('Location: /westec_bms/dashboard/index.php');
    exit;
}

$pdo         = getDbConnection();
$currentUser = getCurrentUser();
$errors      = [];

// Staff-level roles a manager may assign
$staffRoles = $pdo->query("
    SELECT role_id, role_name, description FROM roles
    WHERE role_name IN ('Senior Technician', 'Support Specialist', 'Client Representative')
    ORDER BY role_id ASC
")->fetchAll();

// IT Managers list (for Administrator assigning staff)
$managersList = $pdo->query("
    SELECT u.user_id, u.username, u.email, s.full_name
    FROM users u
    JOIN roles r ON u.role_id = r.role_id
    LEFT JOIN staff s ON s.user_id = u.user_id
    WHERE r.role_name = 'IT Manager'
    ORDER BY u.username ASC
")->fetchAll();

// ── Handle POST: Add new staff member ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_staff') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Security validation failed. Please retry.';
    } else {
        $username  = cleanInput($_POST['username'] ?? '');
        $email     = cleanInput($_POST['email'] ?? '');
        $fullName  = cleanInput($_POST['full_name'] ?? '');
        $phone     = cleanInput($_POST['phone'] ?? '');
        $jobTitle  = cleanInput($_POST['job_title'] ?? '');
        $dept      = cleanInput($_POST['department'] ?? 'IT Technical Services');
        $password  = $_POST['password'] ?? '';
        $confirmPw = $_POST['confirm_password'] ?? '';
        $roleId    = (int)($_POST['role_id'] ?? 0);
        $status    = in_array($_POST['status'] ?? '', ['Active','Inactive']) ? $_POST['status'] : 'Active';

        if (empty($username) || strlen($username) < 3 || strlen($username) > 50) {
            $errors[] = 'Username must be 3–50 characters.';
        } elseif (!preg_match('/^[a-zA-Z0-9_.\-]+$/', $username)) {
            $errors[] = 'Username may only contain letters, numbers, underscores, dots, and hyphens.';
        }
        if (empty($fullName)) $errors[] = 'Full name is required.';
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid email address is required.';
        }
        if (strlen($password) < 6) $errors[] = 'Password must be at least 6 characters.';
        if ($password !== $confirmPw) $errors[] = 'Passwords do not match.';

        $validRoleIds = array_column($staffRoles, 'role_id');
        if (!in_array($roleId, $validRoleIds)) {
            $errors[] = 'Invalid role selected.';
        }

        $managerId = !empty($_POST['manager_id']) ? (int)$_POST['manager_id'] : null;
        if (hasRole('Administrator')) {
            if (empty($managerId)) {
                $errors[] = 'Please specify which IT Manager this staff member reports under.';
            }
        } else {
            if (empty($managerId)) {
                $managerId = (int)$currentUser['user_id'];
            }
        }

        if (empty($errors)) {
            $dup = $pdo->prepare("SELECT user_id FROM users WHERE username = ? LIMIT 1");
            $dup->execute([$username]);
            if ($dup->fetch()) $errors[] = "Username '{$username}' is already taken.";
        }

        if (empty($errors)) {
            try {
                $pdo->beginTransaction();

                $hash = password_hash($password, PASSWORD_DEFAULT);
                $ins  = $pdo->prepare("
                    INSERT INTO users (username, password_hash, role_id, email, status, created_at)
                    VALUES (?, ?, ?, ?, ?, NOW())
                ");
                $ins->execute([$username, $hash, $roleId, $email ?: null, $status]);
                $newUserId = (int)$pdo->lastInsertId();

                $insStaff = $pdo->prepare("
                    INSERT INTO staff (full_name, email, phone, job_title, department, user_id, manager_id, is_active, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $insStaff->execute([
                    $fullName, $email ?: '', $phone ?: '',
                    $jobTitle ?: '', $dept ?: 'IT Technical Services',
                    $newUserId, $managerId, $status === 'Active' ? 1 : 0,
                ]);

                $pdo->prepare("
                    INSERT INTO audit_logs (user_id, action, table_affected, record_id, details, ip_address, created_at)
                    VALUES (?, 'STAFF_CREATED_BY_MANAGER', 'users', ?, ?, ?, NOW())
                ")->execute([
                    $currentUser['user_id'], $newUserId,
                    "Manager '{$currentUser['username']}' created staff account '{$username}' (role_id: {$roleId}).",
                    $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
                ]);

                $pdo->commit();

                $roleNameStmt = $pdo->prepare("SELECT role_name FROM roles WHERE role_id = ?");
                $roleNameStmt->execute([$roleId]);
                $roleName = $roleNameStmt->fetchColumn() ?: 'Staff Member';

                $mailResult = sendNewUserWelcomeEmail([
                    'to_email'           => $email,
                    'full_name'          => $fullName,
                    'username'           => $username,
                    'temporary_password' => $password,
                    'role_name'          => $roleName,
                    'created_by_name'    => $currentUser['username'] . ' (IT Manager)',
                    'job_title'          => $jobTitle,
                    'department'         => $dept,
                ]);

                $mailNotice = $mailResult['sent']
                    ? " A welcome email was sent to {$email}."
                    : " (Email delivery pending — check mail config.)";

                setFlash('success', "Staff member '{$username}' added to your team (ID #{$newUserId})." . $mailNotice);
                header('Location: /westec_bms/manager/my_team.php');
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = parseDatabaseErrorMessage($e);
            }
        }
    }
}

// ── Handle POST: Edit staff member ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_staff') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Security validation failed.');
    } else {
        $targetId    = (int)($_POST['target_user_id'] ?? 0);
        $newUsername = cleanInput($_POST['edit_username'] ?? '');
        $newEmail    = cleanInput($_POST['edit_email'] ?? '');
        $newFullName = cleanInput($_POST['edit_full_name'] ?? '');
        $newPhone    = cleanInput($_POST['edit_phone'] ?? '');
        $newJobTitle = cleanInput($_POST['edit_job_title'] ?? '');
        $newDept     = cleanInput($_POST['edit_department'] ?? '');
        $newRoleId   = (int)($_POST['edit_role_id'] ?? 0);
        $newStatus   = in_array($_POST['edit_status'] ?? '', ['Active','Inactive','Suspended']) ? $_POST['edit_status'] : 'Active';
        $isActive    = $newStatus === 'Active' ? 1 : 0;

        $editErrors  = [];
        if (empty($newUsername) || strlen($newUsername) < 3) $editErrors[] = 'Username too short.';
        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL))   $editErrors[] = 'Invalid email.';
        if (empty($newFullName))                             $editErrors[] = 'Full name is required.';

        $validRoleIds = array_column($staffRoles, 'role_id');
        if (!in_array($newRoleId, $validRoleIds))            $editErrors[] = 'Invalid role.';

        if (empty($editErrors) && $targetId > 0) {
            $dup = $pdo->prepare("SELECT user_id FROM users WHERE username = ? AND user_id != ? LIMIT 1");
            $dup->execute([$newUsername, $targetId]);
            if ($dup->fetch()) $editErrors[] = "Username '{$newUsername}' is already taken.";
        }

        if (empty($editErrors) && $targetId > 0) {
            try {
                $pdo->beginTransaction();

                $pdo->prepare("
                    UPDATE users SET username = ?, email = ?, role_id = ?, status = ?, updated_at = NOW()
                    WHERE user_id = ?
                ")->execute([$newUsername, $newEmail, $newRoleId, $newStatus, $targetId]);

                $staffExists = $pdo->prepare("SELECT staff_id FROM staff WHERE user_id = ?");
                $staffExists->execute([$targetId]);
                if ($staffExists->fetch()) {
                    $pdo->prepare("
                        UPDATE staff SET full_name = ?, email = ?, phone = ?, job_title = ?, department = ?, is_active = ?, updated_at = NOW()
                        WHERE user_id = ?
                    ")->execute([$newFullName, $newEmail, $newPhone, $newJobTitle, $newDept, $isActive, $targetId]);
                } else {
                    $pdo->prepare("
                        INSERT INTO staff (full_name, email, phone, job_title, department, user_id, is_active, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                    ")->execute([$newFullName, $newEmail, $newPhone, $newJobTitle, $newDept, $targetId, $isActive]);
                }

                $pdo->prepare("
                    INSERT INTO audit_logs (user_id, action, table_affected, record_id, details, ip_address, created_at)
                    VALUES (?, 'STAFF_EDITED_BY_MANAGER', 'users', ?, ?, ?, NOW())
                ")->execute([
                    $currentUser['user_id'], $targetId,
                    "Manager '{$currentUser['username']}' edited staff #{$targetId}: username='{$newUsername}', email='{$newEmail}', role_id={$newRoleId}, status='{$newStatus}'.",
                    $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
                ]);

                $pdo->commit();
                setFlash('success', "Staff member '{$newUsername}' updated successfully.");
            } catch (Exception $e) {
                $pdo->rollBack();
                setFlash('danger', 'Update failed: ' . parseDatabaseErrorMessage($e));
            }
        } else if (!empty($editErrors)) {
            setFlash('danger', implode(' | ', $editErrors));
        }
    }
    header('Location: /westec_bms/manager/my_team.php');
    exit;
}

// ── Handle POST: Reset password ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_password') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Security validation failed.');
    } else {
        $targetId   = (int)($_POST['target_user_id'] ?? 0);
        $newPw      = $_POST['new_password'] ?? '';
        $confirmNew = $_POST['confirm_new_password'] ?? '';

        if (strlen($newPw) < 6) {
            setFlash('danger', 'New password must be at least 6 characters.');
        } elseif ($newPw !== $confirmNew) {
            setFlash('danger', 'Passwords do not match.');
        } elseif ($targetId > 0) {
            try {
                // Ensure only staff-level users can be reset by manager
                $chk = $pdo->prepare("
                    SELECT u.user_id FROM users u JOIN roles r ON u.role_id=r.role_id
                    WHERE u.user_id=? AND r.role_name NOT IN ('Administrator','IT Manager') LIMIT 1
                ");
                $chk->execute([$targetId]);
                if (!$chk->fetch()) {
                    setFlash('danger', 'You can only reset passwords for staff-level accounts.');
                } else {
                    $hash = password_hash($newPw, PASSWORD_DEFAULT);
                    $pdo->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE user_id = ?")
                        ->execute([$hash, $targetId]);

                    $pdo->prepare("
                        INSERT INTO audit_logs (user_id, action, table_affected, record_id, details, ip_address, created_at)
                        VALUES (?, 'PASSWORD_RESET_BY_MANAGER', 'users', ?, ?, ?, NOW())
                    ")->execute([
                        $currentUser['user_id'], $targetId,
                        "Manager '{$currentUser['username']}' reset password for staff #{$targetId}.",
                        $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
                    ]);

                    setFlash('success', "Password reset successfully for staff #{$targetId}.");
                }
            } catch (Exception $e) {
                setFlash('danger', 'Password reset failed: ' . parseDatabaseErrorMessage($e));
            }
        }
    }
    header('Location: /westec_bms/manager/my_team.php');
    exit;
}

// ── Handle POST: Delete staff member ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_staff') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Security validation failed.');
    } else {
        $targetId = (int)($_POST['target_user_id'] ?? 0);
        if ($targetId > 0) {
            try {
                // Ensure only staff-level users can be deleted by manager
                $chk = $pdo->prepare("
                    SELECT u.user_id, u.username, u.email FROM users u JOIN roles r ON u.role_id=r.role_id
                    WHERE u.user_id=? AND r.role_name NOT IN ('Administrator','IT Manager') LIMIT 1
                ");
                $chk->execute([$targetId]);
                $delInfo = $chk->fetch();

                if (!$delInfo) {
                    setFlash('danger', 'You can only delete staff-level accounts.');
                } else {
                    $pdo->beginTransaction();

                    $pdo->prepare("DELETE FROM staff WHERE user_id = ?")->execute([$targetId]);
                    $pdo->prepare("DELETE FROM users WHERE user_id = ?")->execute([$targetId]);

                    $pdo->prepare("
                        INSERT INTO audit_logs (user_id, action, table_affected, record_id, details, ip_address, created_at)
                        VALUES (?, 'STAFF_DELETED_BY_MANAGER', 'users', ?, ?, ?, NOW())
                    ")->execute([
                        $currentUser['user_id'], $targetId,
                        "Manager '{$currentUser['username']}' deleted staff '{$delInfo['username']}' (#{$targetId}, email: {$delInfo['email']}).",
                        $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
                    ]);

                    $pdo->commit();
                    setFlash('success', "Staff member '{$delInfo['username']}' has been permanently deleted.");
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                setFlash('danger', 'Delete failed: ' . parseDatabaseErrorMessage($e));
            }
        }
    }
    header('Location: /westec_bms/manager/my_team.php');
    exit;
}

// ── Handle POST: Toggle status ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_status') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Security validation failed.');
    } else {
        $targetId  = (int)($_POST['target_user_id'] ?? 0);
        $newStatus = in_array($_POST['new_status'] ?? '', ['Active','Inactive','Suspended']) ? $_POST['new_status'] : 'Inactive';
        $isActive  = $newStatus === 'Active' ? 1 : 0;

        $chk = $pdo->prepare("
            SELECT u.user_id FROM users u JOIN roles r ON u.role_id=r.role_id
            WHERE u.user_id=? AND r.role_name NOT IN ('Administrator','IT Manager') LIMIT 1
        ");
        $chk->execute([$targetId]);

        if (!$chk->fetch()) {
            setFlash('warning', 'You can only manage status for staff-level accounts.');
        } else {
            $pdo->prepare("UPDATE users SET status=?, updated_at=NOW() WHERE user_id=?")->execute([$newStatus, $targetId]);
            $pdo->prepare("UPDATE staff SET is_active=?, updated_at=NOW() WHERE user_id=?")->execute([$isActive, $targetId]);

            $pdo->prepare("
                INSERT INTO audit_logs (user_id, action, table_affected, record_id, details, ip_address, created_at)
                VALUES (?, 'STAFF_STATUS_CHANGED', 'users', ?, ?, ?, NOW())
            ")->execute([
                $currentUser['user_id'], $targetId,
                "Manager '{$currentUser['username']}' changed staff #{$targetId} status to '{$newStatus}'.",
                $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
            ]);
            setFlash('success', "Staff account status updated to '{$newStatus}'.");
        }
    }
    header('Location: /westec_bms/manager/my_team.php');
    exit;
}

// ── Fetch all staff users ─────────────────────────────────────────────────────
$staffUsers = $pdo->query("
    SELECT u.user_id, u.username, u.email, u.status, u.profile_photo, u.created_at,
           r.role_name, r.role_id,
           s.full_name, s.phone, s.job_title, s.department, s.staff_id, s.manager_id,
           m.username AS manager_username, ms.full_name AS manager_name
    FROM users u
    JOIN roles r ON u.role_id = r.role_id
    LEFT JOIN staff s ON s.user_id = u.user_id
    LEFT JOIN users m ON s.manager_id = m.user_id
    LEFT JOIN staff ms ON ms.user_id = m.user_id
    WHERE r.role_name IN ('Senior Technician', 'Support Specialist', 'Client Representative')
    ORDER BY r.role_id ASC, u.username ASC
")->fetchAll();

$staffCount = count($staffUsers);

$pageTitle    = 'My Team';
$pageSubtitle = 'IT Manager — Onboard & Manage Your Staff Members';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row g-4">

    <!-- ── Add Staff Form ────────────────────────────────────────────────── -->
    <div class="col-xl-4 col-lg-5">
        <div class="content-section h-100">
            <div class="section-header">
                <h2 class="section-title">
                    <i data-lucide="user-plus" class="text-warning"></i>
                    Add Staff Member
                </h2>
                <span class="badge bg-warning text-dark">Manager Only</span>
            </div>
            <p class="text-muted small mb-3">
                Create a system login account <em>and</em> a staff profile in one step.
            </p>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger mb-3">
                    <i data-lucide="alert-triangle" class="me-2"></i>
                    <strong>Please fix the following:</strong>
                    <ul class="mb-0 mt-1 ps-3">
                        <?php foreach ($errors as $err): ?>
                            <li><?= e($err) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="POST" action="/westec_bms/manager/my_team.php" id="addStaffForm" novalidate>
                <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                <input type="hidden" name="action" value="add_staff">

                <p class="fw-semibold text-dark mb-2 small text-uppercase letter-spacing-1">
                    <i data-lucide="key" class="me-1 text-primary"></i>Login Credentials
                </p>

                <div class="mb-2">
                    <label for="username" class="form-label form-label-sm">Username <span class="text-danger">*</span></label>
                    <input type="text" class="form-control form-control-sm" id="username" name="username"
                           value="<?= e($_POST['username'] ?? '') ?>"
                           placeholder="e.g. tech_sipho" autocomplete="off" required>
                </div>

                <div class="row g-2 mb-2">
                    <div class="col-6">
                        <label for="password" class="form-label form-label-sm">Password <span class="text-danger">*</span></label>
                        <input type="password" class="form-control form-control-sm" id="password" name="password"
                               placeholder="Min. 6 chars" autocomplete="new-password" required>
                    </div>
                    <div class="col-6">
                        <label for="confirm_password" class="form-label form-label-sm">Confirm <span class="text-danger">*</span></label>
                        <input type="password" class="form-control form-control-sm" id="confirm_password" name="confirm_password"
                               placeholder="Re-type" autocomplete="new-password" required>
                        <div id="pwFeedback" class="form-text" style="font-size:0.68rem;"></div>
                    </div>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-7">
                        <label for="role_id" class="form-label form-label-sm">Role <span class="text-danger">*</span></label>
                        <select class="form-select form-select-sm" id="role_id" name="role_id" required>
                            <option value="">-- Select Role --</option>
                            <?php foreach ($staffRoles as $r): ?>
                                <option value="<?= $r['role_id'] ?>"
                                    <?= (($_POST['role_id'] ?? '') == $r['role_id']) ? 'selected' : '' ?>>
                                    <?= e($r['role_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-5">
                        <label for="status" class="form-label form-label-sm">Status</label>
                        <select class="form-select form-select-sm" id="status" name="status">
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                        </select>
                    </div>
                </div>

                <hr class="my-3">
                <p class="fw-semibold text-dark mb-2 small text-uppercase">
                    <i data-lucide="contact" class="me-1 text-warning"></i>Staff Profile
                </p>

                <div class="mb-2">
                    <label for="full_name" class="form-label form-label-sm">Full Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control form-control-sm" id="full_name" name="full_name"
                           value="<?= e($_POST['full_name'] ?? '') ?>"
                           placeholder="e.g. Sipho Dlamini" autocomplete="off" required>
                </div>

                <div class="mb-2">
                    <label for="email" class="form-label form-label-sm">Email Address <span class="text-danger">*</span></label>
                    <input type="email" class="form-control form-control-sm" id="email" name="email" required
                           value="<?= e($_POST['email'] ?? '') ?>"
                           placeholder="staff@westeceswatini.co.sz" autocomplete="off">
                    <div class="form-text text-muted" style="font-size:0.7rem;">Welcome email will be sent here.</div>
                </div>

                <div class="row g-2 mb-2">
                    <div class="col-6">
                        <label for="phone" class="form-label form-label-sm">Phone</label>
                        <input type="text" class="form-control form-control-sm" id="phone" name="phone"
                               value="<?= e($_POST['phone'] ?? '') ?>"
                               placeholder="+268 7XXX XXXX">
                    </div>
                    <div class="col-6">
                        <label for="job_title" class="form-label form-label-sm">Job Title</label>
                        <input type="text" class="form-control form-control-sm" id="job_title" name="job_title"
                               value="<?= e($_POST['job_title'] ?? '') ?>"
                               placeholder="e.g. Network Technician">
                    </div>
                </div>

                <div class="mb-3">
                    <label for="department" class="form-label form-label-sm">Department</label>
                    <input type="text" class="form-control form-control-sm" id="department" name="department"
                           value="<?= e($_POST['department'] ?? 'IT Technical Services') ?>"
                           placeholder="IT Technical Services">
                </div>

                <?php if (hasRole('Administrator')): ?>
                <div class="mb-4">
                    <label for="manager_id" class="form-label form-label-sm">Assign Under IT Manager <span class="text-danger">*</span></label>
                    <select class="form-select form-select-sm" id="manager_id" name="manager_id" required>
                        <option value="">-- Select IT Manager --</option>
                        <?php foreach ($managersList as $mgr): ?>
                            <option value="<?= $mgr['user_id'] ?>" <?= (($_POST['manager_id'] ?? '') == $mgr['user_id']) ? 'selected' : '' ?>>
                                <?= e($mgr['full_name'] ?: $mgr['username']) ?> (<?= e($mgr['username']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text" style="font-size:0.7rem;">Select which manager oversees this team member.</div>
                </div>
                <?php endif; ?>

                <button type="submit" class="btn btn-warning w-100 fw-semibold" id="addStaffBtn">
                    <i data-lucide="user-check" class="me-2"></i> Add to My Team
                </button>
            </form>
        </div>
    </div>

    <!-- ── Team List ──────────────────────────────────────────────────────── -->
    <div class="col-xl-8 col-lg-7">
        <div class="content-section">
            <div class="section-header mb-3">
                <h2 class="section-title">
                    <i data-lucide="users" class="text-warning"></i>
                    Your Team
                </h2>
                <span class="badge bg-secondary"><?= $staffCount ?> Staff Member<?= $staffCount !== 1 ? 's' : '' ?></span>
            </div>

            <?php if (empty($staffUsers)): ?>
                <div class="text-center py-5 text-muted">
                    <i data-lucide="users" class="display-3 mb-3 d-block opacity-25"></i>
                    <p class="mb-0">No staff members yet. Add your first team member using the form on the left.</p>
                </div>
            <?php else: ?>
                <?php
                $roleOrder  = ['Senior Technician', 'Support Specialist', 'Client Representative'];
                $roleIcons  = [
                    'Senior Technician'    => 'bi-tools text-primary',
                    'Support Specialist'   => 'bi-headset text-info',
                    'Client Representative'=> 'bi-building text-success',
                ];
                $roleBadge  = [
                    'Senior Technician'    => 'bg-primary',
                    'Support Specialist'   => 'bg-info text-dark',
                    'Client Representative'=> 'bg-success',
                ];
                $grouped = [];
                foreach ($staffUsers as $s) { $grouped[$s['role_name']][] = $s; }

                foreach ($roleOrder as $roleName):
                    if (empty($grouped[$roleName])) continue;
                ?>
                    <div class="mb-4">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <i class="bi <?= $roleIcons[$roleName] ?? 'bi-person' ?> fs-5"></i>
                            <h3 class="h6 fw-bold mb-0"><?= e($roleName) ?></h3>
                            <span class="badge <?= $roleBadge[$roleName] ?? 'bg-secondary' ?>">
                                <?= count($grouped[$roleName]) ?>
                            </span>
                        </div>

                        <div class="row g-2">
                            <?php foreach ($grouped[$roleName] as $s): ?>
                                <?php
                                $statusMap = ['Active'=>'success','Inactive'=>'secondary','Suspended'=>'danger'];
                                $dot = $statusMap[$s['status']] ?? 'secondary';
                                ?>
                                <div class="col-md-6">
                                    <div class="card border shadow-sm h-100" style="border-radius: 10px; overflow:hidden;">
                                        <div class="card-body p-3">
                                            <div class="d-flex align-items-start justify-content-between gap-2">
                                                <div class="flex-grow-1">
                                                    <div class="d-flex align-items-center gap-2 mb-1">
                                                        <?php $cardAvatar = getUserAvatarUrl(['profile_photo' => $s['profile_photo']]); ?>
                                                        <?php if ($cardAvatar): ?>
                                                            <img src="<?= $cardAvatar ?>" alt="<?= e($s['username']) ?>" class="rounded-circle border" style="width: 36px; height: 36px; object-fit: cover;">
                                                        <?php else: ?>
                                                            <div class="rounded-circle bg-light border text-primary d-inline-flex align-items-center justify-content-center fw-bold" style="width: 36px; height: 36px; font-size: 0.82rem;">
                                                                <?= strtoupper(substr($s['full_name'] ?: $s['username'], 0, 2)) ?>
                                                            </div>
                                                        <?php endif; ?>
                                                        <div>
                                                            <div class="fw-bold"><?= e($s['full_name'] ?: $s['username']) ?></div>
                                                            <div class="text-muted small">@<?= e($s['username']) ?></div>
                                                        </div>
                                                    </div>
                                                    <?php if ($s['job_title']): ?>
                                                        <div class="small text-secondary mt-1">
                                                            <i data-lucide="briefcase" class="me-1"></i><?= e($s['job_title']) ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if ($s['email']): ?>
                                                        <div class="small text-secondary">
                                                            <i data-lucide="mail" class="me-1"></i><?= e($s['email']) ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if ($s['phone']): ?>
                                                        <div class="small text-secondary">
                                                            <i data-lucide="phone" class="me-1"></i><?= e($s['phone']) ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if ($s['department']): ?>
                                                        <div class="small text-muted mt-1">
                                                            <i data-lucide="building-2" class="me-1"></i><?= e($s['department']) ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                                <span class="badge bg-<?= $dot ?> align-self-start"><?= e($s['status']) ?></span>
                                            </div>
                                        </div>
                                        <div class="card-footer bg-light border-top p-2 d-flex gap-1 justify-content-end flex-wrap">
                                            <!-- Edit -->
                                            <button type="button" class="btn btn-outline-primary btn-sm py-0 px-2" title="Edit"
                                                    onclick="openEditModal(<?= htmlspecialchars(json_encode([
                                                        'user_id'    => $s['user_id'],
                                                        'username'   => $s['username'],
                                                        'email'      => $s['email'],
                                                        'full_name'  => $s['full_name'],
                                                        'phone'      => $s['phone'],
                                                        'job_title'  => $s['job_title'],
                                                        'department' => $s['department'],
                                                        'role_id'    => $s['role_id'],
                                                        'status'     => $s['status'],
                                                    ]), ENT_QUOTES) ?>)">
                                                <i data-lucide="pencil" class="me-1"></i>Edit
                                            </button>

                                            <!-- Reset Password -->
                                            <button type="button" class="btn btn-outline-warning btn-sm py-0 px-2" title="Reset Password"
                                                    onclick="openResetModal(<?= $s['user_id'] ?>, '<?= e($s['username']) ?>')">
                                                <i data-lucide="key" class="me-1"></i>Reset PW
                                            </button>

                                            <!-- Status toggles -->
                                            <?php if ($s['status'] !== 'Active'): ?>
                                                <form method="POST">
                                                    <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                                                    <input type="hidden" name="action" value="set_status">
                                                    <input type="hidden" name="target_user_id" value="<?= $s['user_id'] ?>">
                                                    <input type="hidden" name="new_status" value="Active">
                                                    <button type="submit" class="btn btn-success btn-sm py-0 px-2" title="Activate"
                                                            onclick="return confirm('Activate <?= e($s['username']) ?>?')">
                                                        <i data-lucide="check-circle-2" class="me-1"></i>Activate
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if ($s['status'] !== 'Suspended'): ?>
                                                <form method="POST">
                                                    <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                                                    <input type="hidden" name="action" value="set_status">
                                                    <input type="hidden" name="target_user_id" value="<?= $s['user_id'] ?>">
                                                    <input type="hidden" name="new_status" value="Suspended">
                                                    <button type="submit" class="btn btn-secondary btn-sm py-0 px-2" title="Suspend"
                                                            onclick="return confirm('Suspend <?= e($s['username']) ?>?')">
                                                        <i data-lucide="ban" class="me-1"></i>Suspend
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <!-- Delete -->
                                            <button type="button" class="btn btn-danger btn-sm py-0 px-2" title="Delete"
                                                    onclick="openDeleteModal(<?= $s['user_id'] ?>, '<?= e($s['username']) ?>')">
                                                <i data-lucide="trash-2" class="me-1"></i>Delete
                                            </button>

                                            <!-- Assign to Project -->
                                            <a href="/westec_bms/projects/assignments.php?staff_id=<?= $s['staff_id'] ?? 0 ?>"
                                               class="btn btn-outline-info btn-sm py-0 px-2" title="Assign to Project">
                                                <i data-lucide="workflow" class="me-1"></i>Assign Project
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Permissions reminder -->
        <div class="alert alert-info d-flex align-items-start gap-3 mt-3 border-0 shadow-sm">
            <i data-lucide="shield" class="fs-4 mt-1 text-primary"></i>
            <div>
                <strong>Inventory Access Control:</strong> Staff have <em>zero</em> inventory access by default.
                After adding a team member, use the
                <a href="/westec_bms/admin/inventory_access.php" class="alert-link">Inventory Access Control</a>
                panel to grant specific permissions.
            </div>
        </div>
    </div>
</div>

<!-- ── Edit Staff Modal ───────────────────────────────────────────────────── -->
<div class="modal fade" id="editStaffModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <form method="POST" action="/westec_bms/manager/my_team.php">
                <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                <input type="hidden" name="action" value="edit_staff">
                <input type="hidden" name="target_user_id" id="editUserId">

                <div class="modal-header">
                    <h5 class="modal-title">
                        <i data-lucide="pencil" class="text-primary me-2"></i>Edit Staff Member
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label form-label-sm fw-semibold text-uppercase small">Login Credentials</label>
                            <div class="mb-2">
                                <label class="form-label form-label-sm">Username <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" name="edit_username" id="editUsername" required>
                            </div>
                            <div class="mb-2">
                                <label class="form-label form-label-sm">Email <span class="text-danger">*</span></label>
                                <input type="email" class="form-control form-control-sm" name="edit_email" id="editEmail" required>
                            </div>
                            <div class="mb-2">
                                <label class="form-label form-label-sm">Role</label>
                                <select class="form-select form-select-sm" name="edit_role_id" id="editRoleId">
                                    <?php foreach ($staffRoles as $r): ?>
                                        <option value="<?= $r['role_id'] ?>"><?= e($r['role_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-0">
                                <label class="form-label form-label-sm">Status</label>
                                <select class="form-select form-select-sm" name="edit_status" id="editStatus">
                                    <option value="Active">Active</option>
                                    <option value="Inactive">Inactive</option>
                                    <option value="Suspended">Suspended</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label form-label-sm fw-semibold text-uppercase small">Staff Profile</label>
                            <div class="mb-2">
                                <label class="form-label form-label-sm">Full Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" name="edit_full_name" id="editFullName" required>
                            </div>
                            <div class="mb-2">
                                <label class="form-label form-label-sm">Phone</label>
                                <input type="text" class="form-control form-control-sm" name="edit_phone" id="editPhone">
                            </div>
                            <div class="mb-2">
                                <label class="form-label form-label-sm">Job Title</label>
                                <input type="text" class="form-control form-control-sm" name="edit_job_title" id="editJobTitle">
                            </div>
                            <div class="mb-0">
                                <label class="form-label form-label-sm">Department</label>
                                <input type="text" class="form-control form-control-sm" name="edit_department" id="editDepartment">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i data-lucide="check" class="me-1"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── Reset Password Modal ───────────────────────────────────────────────── -->
<div class="modal fade" id="resetPwModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="/westec_bms/manager/my_team.php">
                <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="target_user_id" id="resetUserId">

                <div class="modal-header">
                    <h5 class="modal-title">
                        <i data-lucide="key" class="text-warning me-2"></i>Reset Password
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small mb-3">Resetting password for: <strong id="resetUsername"></strong></p>
                    <div class="mb-3">
                        <label class="form-label">New Password <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" name="new_password" id="newPw"
                               placeholder="Min. 6 characters" required autocomplete="new-password">
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Confirm New Password <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" name="confirm_new_password" id="confirmNewPw"
                               placeholder="Re-type new password" required autocomplete="new-password">
                        <div id="resetPwFeedback" class="form-text"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning" id="resetPwBtn">
                        <i data-lucide="lock" class="me-1"></i> Reset Password
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── Delete Confirm Modal ───────────────────────────────────────────────── -->
<div class="modal fade" id="deleteStaffModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-danger">
            <form method="POST" action="/westec_bms/manager/my_team.php">
                <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                <input type="hidden" name="action" value="delete_staff">
                <input type="hidden" name="target_user_id" id="deleteUserId">

                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">
                        <i data-lucide="trash-2" class="me-2"></i>Delete Staff
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center py-4">
                    <i data-lucide="alert-triangle" class="text-danger fs-1 mb-3 d-block"></i>
                    <p class="mb-1">Permanently delete:</p>
                    <strong id="deleteUsername" class="fs-5 text-danger"></strong>
                    <p class="text-muted small mt-2 mb-0">This action cannot be undone.</p>
                </div>
                <div class="modal-footer justify-content-center gap-2">
                    <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger px-4">
                        <i data-lucide="trash-2" class="me-1"></i> Delete
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Password match feedback (Add form)
document.getElementById('confirm_password').addEventListener('input', function() {
    const pw = document.getElementById('password').value;
    const fb = document.getElementById('pwFeedback');
    if (!this.value) { fb.textContent = ''; return; }
    if (this.value === pw) {
        fb.textContent = '✓ Match'; fb.className = 'form-text text-success';
    } else {
        fb.textContent = '✗ No match'; fb.className = 'form-text text-danger';
    }
});

// Password match feedback (Reset modal)
document.getElementById('confirmNewPw').addEventListener('input', function() {
    const pw  = document.getElementById('newPw').value;
    const fb  = document.getElementById('resetPwFeedback');
    const btn = document.getElementById('resetPwBtn');
    if (!this.value) { fb.textContent = ''; return; }
    if (this.value === pw) {
        fb.textContent = '✓ Passwords match'; fb.className = 'form-text text-success'; btn.disabled = false;
    } else {
        fb.textContent = '✗ Passwords do not match'; fb.className = 'form-text text-danger'; btn.disabled = true;
    }
});

// Open Edit Modal — pre-fill all fields
function openEditModal(data) {
    document.getElementById('editUserId').value      = data.user_id;
    document.getElementById('editUsername').value    = data.username;
    document.getElementById('editEmail').value       = data.email || '';
    document.getElementById('editFullName').value    = data.full_name || '';
    document.getElementById('editPhone').value       = data.phone || '';
    document.getElementById('editJobTitle').value    = data.job_title || '';
    document.getElementById('editDepartment').value  = data.department || '';
    document.getElementById('editRoleId').value      = data.role_id;
    document.getElementById('editStatus').value      = data.status;
    new bootstrap.Modal(document.getElementById('editStaffModal')).show();
}

// Open Reset Password Modal
function openResetModal(userId, username) {
    document.getElementById('resetUserId').value         = userId;
    document.getElementById('resetUsername').textContent = username;
    document.getElementById('newPw').value               = '';
    document.getElementById('confirmNewPw').value        = '';
    document.getElementById('resetPwFeedback').textContent = '';
    new bootstrap.Modal(document.getElementById('resetPwModal')).show();
}

// Open Delete Modal
function openDeleteModal(userId, username) {
    document.getElementById('deleteUserId').value         = userId;
    document.getElementById('deleteUsername').textContent = username;
    new bootstrap.Modal(document.getElementById('deleteStaffModal')).show();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
