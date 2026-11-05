<?php
/**
 * WesTec Eswatini Business Management System (WesTec BMS)
 * User Management Panel — Administrator Only
 *
 * System Admin can:
 *  - Create IT Manager / Administrator accounts
 *  - Edit user details (username, email, role, status)
 *  - Reset any user's password
 *  - Delete any user account (except self)
 *  - Activate / Deactivate / Suspend any account
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';

requireLogin();

if (!hasRole('Administrator')) {
    setFlash('danger', 'Access Denied: Only the System Administrator may manage system users.');
    header('Location: /westec_bms/dashboard/index.php');
    exit;
}

$pdo         = getDbConnection();
$currentUser = getCurrentUser();
$errors      = [];

// All roles available to Admin
$allRoles = $pdo->query("
    SELECT role_id, role_name, description FROM roles ORDER BY role_id ASC
")->fetchAll();

$managerRoles = array_filter($allRoles, fn($r) => in_array($r['role_name'], ['IT Manager', 'Administrator']));

// ── Handle POST: Add new user ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_user') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Security validation failed. Please retry.';
    } else {
        $username   = cleanInput($_POST['username'] ?? '');
        $email      = cleanInput($_POST['email'] ?? '');
        $password   = $_POST['password'] ?? '';
        $confirmPw  = $_POST['confirm_password'] ?? '';
        $roleId     = (int)($_POST['role_id'] ?? 0);
        $status     = in_array($_POST['status'] ?? '', ['Active','Inactive','Suspended']) ? $_POST['status'] : 'Active';

        if (empty($username) || strlen($username) < 3 || strlen($username) > 50) {
            $errors[] = 'Username must be 3–50 characters.';
        } elseif (!preg_match('/^[a-zA-Z0-9_.\-]+$/', $username)) {
            $errors[] = 'Username may only contain letters, numbers, underscores, dots, and hyphens.';
        }
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid email address is required.';
        }
        if (strlen($password) < 6) {
            $errors[] = 'Password must be at least 6 characters.';
        }
        if ($password !== $confirmPw) {
            $errors[] = 'Passwords do not match.';
        }
        $validRoleIds = array_column($allRoles, 'role_id');
        if (!in_array($roleId, $validRoleIds)) {
            $errors[] = 'Invalid role selected.';
        }

        if (empty($errors)) {
            $dup = $pdo->prepare("SELECT user_id FROM users WHERE username = ? LIMIT 1");
            $dup->execute([$username]);
            if ($dup->fetch()) $errors[] = "Username '{$username}' is already taken.";
        }

        if (empty($errors)) {
            try {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $ins  = $pdo->prepare("
                    INSERT INTO users (username, password_hash, role_id, email, status, created_at)
                    VALUES (?, ?, ?, ?, ?, NOW())
                ");
                $ins->execute([$username, $hash, $roleId, $email ?: null, $status]);
                $newId = (int)$pdo->lastInsertId();

                $pdo->prepare("
                    INSERT INTO audit_logs (user_id, action, table_affected, record_id, details, ip_address, created_at)
                    VALUES (?, 'USER_CREATED_BY_ADMIN', 'users', ?, ?, ?, NOW())
                ")->execute([
                    $currentUser['user_id'], $newId,
                    "Admin '{$currentUser['username']}' created user '{$username}' (role_id: {$roleId}).",
                    $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
                ]);

                $roleNameStmt = $pdo->prepare("SELECT role_name FROM roles WHERE role_id = ?");
                $roleNameStmt->execute([$roleId]);
                $roleName = $roleNameStmt->fetchColumn() ?: 'Manager';

                $mailResult = sendNewUserWelcomeEmail([
                    'to_email'           => $email,
                    'username'           => $username,
                    'temporary_password' => $password,
                    'role_name'          => $roleName,
                    'created_by_name'    => $currentUser['username'] . ' (System Administrator)'
                ]);

                $mailNotice = $mailResult['sent']
                    ? " A welcome email was sent to {$email}."
                    : " (Email delivery pending — check mail config.)";

                setFlash('success', "User '{$username}' created successfully (ID #{$newId})." . $mailNotice);
                header('Location: /westec_bms/admin/user_management.php');
                exit;
            } catch (Exception $e) {
                $errors[] = parseDatabaseErrorMessage($e);
            }
        }
    }
}

// ── Handle POST: Edit user ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_user') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Security validation failed.');
    } else {
        $targetId   = (int)($_POST['target_user_id'] ?? 0);
        $newUsername = cleanInput($_POST['edit_username'] ?? '');
        $newEmail    = cleanInput($_POST['edit_email'] ?? '');
        $newRoleId   = (int)($_POST['edit_role_id'] ?? 0);
        $newStatus   = in_array($_POST['edit_status'] ?? '', ['Active','Inactive','Suspended']) ? $_POST['edit_status'] : 'Active';

        $editErrors = [];
        if (empty($newUsername) || strlen($newUsername) < 3) $editErrors[] = 'Username too short.';
        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL))    $editErrors[] = 'Invalid email.';
        $validRoleIds = array_column($allRoles, 'role_id');
        if (!in_array($newRoleId, $validRoleIds))             $editErrors[] = 'Invalid role.';
        if ($targetId === (int)$currentUser['user_id'] && $newStatus !== 'Active') {
            $editErrors[] = 'You cannot deactivate your own account.';
        }

        if (empty($editErrors) && $targetId > 0) {
            // Check username not taken by someone else
            $dup = $pdo->prepare("SELECT user_id FROM users WHERE username = ? AND user_id != ? LIMIT 1");
            $dup->execute([$newUsername, $targetId]);
            if ($dup->fetch()) $editErrors[] = "Username '{$newUsername}' is already taken.";
        }

        if (empty($editErrors) && $targetId > 0) {
            try {
                $pdo->prepare("
                    UPDATE users SET username = ?, email = ?, role_id = ?, status = ?, updated_at = NOW()
                    WHERE user_id = ?
                ")->execute([$newUsername, $newEmail, $newRoleId, $newStatus, $targetId]);

                // Also sync staff email if staff record exists
                $pdo->prepare("UPDATE staff SET email = ?, updated_at = NOW() WHERE user_id = ?")
                    ->execute([$newEmail, $targetId]);

                $pdo->prepare("
                    INSERT INTO audit_logs (user_id, action, table_affected, record_id, details, ip_address, created_at)
                    VALUES (?, 'USER_EDITED_BY_ADMIN', 'users', ?, ?, ?, NOW())
                ")->execute([
                    $currentUser['user_id'], $targetId,
                    "Admin '{$currentUser['username']}' edited user #{$targetId}: username='{$newUsername}', email='{$newEmail}', role_id={$newRoleId}, status='{$newStatus}'.",
                    $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
                ]);

                setFlash('success', "User '{$newUsername}' updated successfully.");
            } catch (Exception $e) {
                setFlash('danger', 'Update failed: ' . parseDatabaseErrorMessage($e));
            }
        } else if (!empty($editErrors)) {
            setFlash('danger', implode(' | ', $editErrors));
        }
    }
    header('Location: /westec_bms/admin/user_management.php');
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
                $hash = password_hash($newPw, PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE user_id = ?")
                    ->execute([$hash, $targetId]);

                $pdo->prepare("
                    INSERT INTO audit_logs (user_id, action, table_affected, record_id, details, ip_address, created_at)
                    VALUES (?, 'PASSWORD_RESET_BY_ADMIN', 'users', ?, ?, ?, NOW())
                ")->execute([
                    $currentUser['user_id'], $targetId,
                    "Admin '{$currentUser['username']}' reset password for user #{$targetId}.",
                    $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
                ]);

                setFlash('success', "Password reset successfully for user #{$targetId}.");
            } catch (Exception $e) {
                setFlash('danger', 'Password reset failed: ' . parseDatabaseErrorMessage($e));
            }
        }
    }
    header('Location: /westec_bms/admin/user_management.php');
    exit;
}

// ── Handle POST: Delete user ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_user') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Security validation failed.');
    } else {
        $targetId = (int)($_POST['target_user_id'] ?? 0);

        if ($targetId === (int)$currentUser['user_id']) {
            setFlash('danger', 'You cannot delete your own account.');
        } elseif ($targetId > 0) {
            try {
                // Fetch user info for audit before deleting
                $delUser = $pdo->prepare("SELECT username, email FROM users WHERE user_id = ?");
                $delUser->execute([$targetId]);
                $delInfo = $delUser->fetch();

                if (!$delInfo) {
                    setFlash('warning', 'User not found.');
                } else {
                    $pdo->beginTransaction();

                    // Delete staff record if exists
                    $pdo->prepare("DELETE FROM staff WHERE user_id = ?")->execute([$targetId]);
                    // Delete user
                    $pdo->prepare("DELETE FROM users WHERE user_id = ?")->execute([$targetId]);

                    // Audit log
                    $pdo->prepare("
                        INSERT INTO audit_logs (user_id, action, table_affected, record_id, details, ip_address, created_at)
                        VALUES (?, 'USER_DELETED_BY_ADMIN', 'users', ?, ?, ?, NOW())
                    ")->execute([
                        $currentUser['user_id'], $targetId,
                        "Admin '{$currentUser['username']}' deleted user '{$delInfo['username']}' (#{$targetId}, email: {$delInfo['email']}).",
                        $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
                    ]);

                    $pdo->commit();
                    setFlash('success', "User '{$delInfo['username']}' has been permanently deleted.");
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                setFlash('danger', 'Delete failed: ' . parseDatabaseErrorMessage($e));
            }
        }
    }
    header('Location: /westec_bms/admin/user_management.php');
    exit;
}

// ── Handle POST: Toggle user status ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_status') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Security validation failed.');
    } else {
        $targetId  = (int)($_POST['target_user_id'] ?? 0);
        $newStatus = in_array($_POST['new_status'] ?? '', ['Active','Inactive','Suspended']) ? $_POST['new_status'] : 'Inactive';

        if ($targetId === (int)$currentUser['user_id']) {
            setFlash('warning', 'You cannot change your own account status.');
        } elseif ($targetId > 0) {
            $pdo->prepare("UPDATE users SET status = ?, updated_at = NOW() WHERE user_id = ?")->execute([$newStatus, $targetId]);
            $pdo->prepare("
                INSERT INTO audit_logs (user_id, action, table_affected, record_id, details, ip_address, created_at)
                VALUES (?, 'USER_STATUS_CHANGED', 'users', ?, ?, ?, NOW())
            ")->execute([
                $currentUser['user_id'], $targetId,
                "Admin '{$currentUser['username']}' set user #{$targetId} status to '{$newStatus}'.",
                $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
            ]);
            setFlash('success', "User status updated to '{$newStatus}'.");
        }
    }
    header('Location: /westec_bms/admin/user_management.php');
    exit;
}

// ── Fetch all users ───────────────────────────────────────────────────────────
$allUsers = $pdo->query("
    SELECT u.user_id, u.username, u.email, u.status, u.created_at, u.profile_photo,
           r.role_name, r.role_id
    FROM users u
    JOIN roles r ON u.role_id = r.role_id
    ORDER BY r.role_id ASC, u.username ASC
")->fetchAll();

$usersByRole = [];
foreach ($allUsers as $u) {
    $usersByRole[$u['role_name']][] = $u;
}

$pageTitle    = 'User Management';
$pageSubtitle = 'System Administrator — Full User CRUD & Account Control';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- Quick Navigation Bar for Admin Suite -->
<div class="d-flex gap-2 mb-4">
    <a href="/westec_bms/admin/user_management.php" class="btn btn-westec btn-sm">
        <i data-lucide="users" class="me-1"></i> User Management
    </a>
    <a href="/westec_bms/admin/audit_logs.php" class="btn btn-outline-secondary btn-sm">
        <i data-lucide="book-open" class="me-1"></i> Audit Trail Logs
    </a>
    <a href="/westec_bms/admin/inventory_access.php" class="btn btn-outline-secondary btn-sm">
        <i data-lucide="shield" class="me-1"></i> Inventory Access Control
    </a>
    <a href="/westec_bms/admin/system_settings.php" class="btn btn-outline-secondary btn-sm">
        <i data-lucide="image" class="me-1"></i> System Branding &amp; Logo
    </a>
</div>

<div class="row g-4">

    <!-- ── Add New User Form ──────────────────────────────────────────────── -->
    <div class="col-xl-4 col-lg-5">
        <div class="content-section h-100">
            <div class="section-header">
                <h2 class="section-title">
                    <i data-lucide="user-plus" class="text-primary"></i>
                    Add New User
                </h2>
                <span class="badge bg-danger">Admin Only</span>
            </div>
            <p class="text-muted small mb-3">
                Create <strong>IT Manager</strong> or <strong>Administrator</strong> accounts.
                IT Managers onboard their own staff from <em>My Team</em>.
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

            <form method="POST" action="/westec_bms/admin/user_management.php" id="addUserForm" novalidate>
                <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                <input type="hidden" name="action" value="add_user">

                <div class="mb-3">
                    <label for="username" class="form-label">Username <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="username" name="username"
                           value="<?= e($_POST['username'] ?? '') ?>"
                           placeholder="e.g. mgr_thabo" autocomplete="off" required>
                </div>

                <div class="mb-3">
                    <label for="email" class="form-label">Email Address <span class="text-danger">*</span></label>
                    <input type="email" class="form-control" id="email" name="email" required
                           value="<?= e($_POST['email'] ?? '') ?>"
                           placeholder="manager@westeceswatini.co.sz" autocomplete="off">
                    <div class="form-text text-muted">Welcome email with credentials will be sent here.</div>
                </div>

                <div class="mb-3">
                    <label for="role_id" class="form-label">Assign Role <span class="text-danger">*</span></label>
                    <select class="form-select" id="role_id" name="role_id" required>
                        <option value="">-- Select Role --</option>
                        <?php foreach ($allRoles as $r): ?>
                            <option value="<?= $r['role_id'] ?>"
                                <?= (($_POST['role_id'] ?? '') == $r['role_id']) ? 'selected' : '' ?>>
                                <?= e($r['role_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label for="password" class="form-label">Password <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" id="password" name="password" required
                               placeholder="Min. 6 characters" autocomplete="new-password">
                    </div>
                    <div class="col-md-6">
                        <label for="confirm_password" class="form-label">Confirm Password <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required
                               placeholder="Re-type password" autocomplete="new-password">
                        <div id="pwMatchFeedback" class="form-text"></div>
                    </div>
                </div>

                <div class="mb-4">
                    <label for="status" class="form-label">Account Status</label>
                    <select class="form-select" id="status" name="status">
                        <option value="Active">Active</option>
                        <option value="Inactive">Inactive</option>
                        <option value="Suspended">Suspended</option>
                    </select>
                </div>

                <button type="submit" class="btn btn-westec w-100 py-2" id="addUserBtn">
                    <i data-lucide="user-check" class="me-2"></i> Create Account
                </button>
            </form>
        </div>
    </div>

    <!-- ── User List ──────────────────────────────────────────────────────── -->
    <div class="col-xl-8 col-lg-7">
        <div class="content-section">
            <div class="section-header mb-3">
                <h2 class="section-title">
                    <i data-lucide="users" class="text-primary"></i>
                    All System Users
                </h2>
                <span class="badge bg-secondary"><?= count($allUsers) ?> Total</span>
            </div>

            <?php
            $roleOrder   = ['Administrator', 'IT Manager', 'Senior Technician', 'Support Specialist', 'Client Representative'];
            $roleIcons   = [
                'Administrator'         => 'shield-check',
                'IT Manager'            => 'user-cog',
                'Senior Technician'     => 'wrench',
                'Support Specialist'    => 'headphones',
                'Client Representative' => 'building-2',
            ];
            $roleIconColor = [
                'Administrator'         => 'text-danger',
                'IT Manager'            => 'text-warning',
                'Senior Technician'     => 'text-primary',
                'Support Specialist'    => 'text-info',
                'Client Representative' => 'text-success',
            ];
            $roleBadge   = [
                'Administrator'        => 'bg-danger',
                'IT Manager'           => 'bg-warning text-dark',
                'Senior Technician'    => 'bg-primary',
                'Support Specialist'   => 'bg-info text-dark',
                'Client Representative'=> 'bg-success',
            ];
            foreach ($roleOrder as $roleName):
                if (empty($usersByRole[$roleName])) continue;
            ?>
                <div class="mb-4">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <i data-lucide="<?= $roleIcons[$roleName] ?? 'user' ?>" class="<?= $roleIconColor[$roleName] ?? '' ?>" style="width:18px;height:18px;"></i>
                        <h3 class="h6 fw-bold mb-0"><?= e($roleName) ?></h3>
                        <span class="badge <?= $roleBadge[$roleName] ?? 'bg-secondary' ?>"><?= count($usersByRole[$roleName]) ?></span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover table-sm align-middle mb-0 border rounded">
                            <thead class="table-light">
                                <tr>
                                    <th>#ID</th>
                                    <th>Username</th>
                                    <th>Email</th>
                                    <th>Status</th>
                                    <th>Created</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($usersByRole[$roleName] as $u): ?>
                                    <?php
                                    $isSelf    = $u['user_id'] === (int)$currentUser['user_id'];
                                    $statusMap = ['Active' => 'success', 'Inactive' => 'secondary', 'Suspended' => 'danger'];
                                    $dot = $statusMap[$u['status']] ?? 'secondary';
                                    ?>
                                    <tr>
                                        <td><span class="badge bg-dark">#<?= $u['user_id'] ?></span></td>
                                        <td>
                                            <?php $uAvatar = getUserAvatarUrl(['profile_photo' => $u['profile_photo'] ?? null]); ?>
                                            <div class="d-flex align-items-center gap-2">
                                                <?php if ($uAvatar): ?>
                                                    <img src="<?= $uAvatar ?>" alt="<?= e($u['username']) ?>" class="rounded-circle border" style="width:30px;height:30px;object-fit:cover;">
                                                <?php else: ?>
                                                    <div class="rounded-circle bg-light border text-primary d-inline-flex align-items-center justify-content-center fw-bold" style="width:30px;height:30px;font-size:0.72rem;">
                                                        <?= strtoupper(substr($u['username'], 0, 2)) ?>
                                                    </div>
                                                <?php endif; ?>
                                                <div>
                                                    <strong><?= e($u['username']) ?></strong>
                                                    <?php if ($isSelf): ?>
                                                        <span class="badge bg-light text-dark border ms-1" style="font-size:0.65rem;">You</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-muted small"><?= e($u['email'] ?: '—') ?></td>
                                        <td>
                                            <span class="badge bg-<?= $dot ?>">
                                                <?= e($u['status']) ?>
                                            </span>
                                        </td>
                                        <td class="text-muted small"><?= e(date('d M Y', strtotime($u['created_at']))) ?></td>
                                        <td class="text-end">
                                            <div class="d-flex gap-1 justify-content-end flex-wrap">
                                                <!-- Edit -->
                                                <button type="button" class="btn btn-outline-primary btn-sm py-0 px-2"
                                                        title="Edit User"
                                                        onclick="openEditModal(<?= htmlspecialchars(json_encode([
                                                            'user_id'  => $u['user_id'],
                                                            'username' => $u['username'],
                                                            'email'    => $u['email'],
                                                            'role_id'  => $u['role_id'],
                                                            'status'   => $u['status'],
                                                        ]), ENT_QUOTES) ?>)">
                                                    <i data-lucide="pencil"></i>
                                                </button>

                                                <!-- Reset Password -->
                                                <button type="button" class="btn btn-outline-warning btn-sm py-0 px-2"
                                                        title="Reset Password"
                                                        onclick="openResetModal(<?= $u['user_id'] ?>, '<?= e($u['username']) ?>')">
                                                    <i data-lucide="key"></i>
                                                </button>

                                                <!-- Status toggles -->
                                                <?php if (!$isSelf): ?>
                                                    <?php if ($u['status'] !== 'Active'): ?>
                                                        <form method="POST" class="d-inline">
                                                            <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                                                            <input type="hidden" name="action" value="set_status">
                                                            <input type="hidden" name="target_user_id" value="<?= $u['user_id'] ?>">
                                                            <input type="hidden" name="new_status" value="Active">
                                                            <button type="submit" class="btn btn-success btn-sm py-0 px-2" title="Activate">
                                                                <i data-lucide="check-circle-2"></i>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                    <?php if ($u['status'] !== 'Suspended'): ?>
                                                        <form method="POST" class="d-inline">
                                                            <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                                                            <input type="hidden" name="action" value="set_status">
                                                            <input type="hidden" name="target_user_id" value="<?= $u['user_id'] ?>">
                                                            <input type="hidden" name="new_status" value="Suspended">
                                                            <button type="submit" class="btn btn-danger btn-sm py-0 px-2" title="Suspend"
                                                                    onclick="return confirm('Suspend <?= e($u['username']) ?>?')">
                                                                <i data-lucide="ban"></i>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>

                                                    <!-- Delete -->
                                                    <button type="button" class="btn btn-danger btn-sm py-0 px-2"
                                                            title="Delete User"
                                                            onclick="openDeleteModal(<?= $u['user_id'] ?>, '<?= e($u['username']) ?>')">
                                                        <i data-lucide="trash-2"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <span class="text-muted small fst-italic">Current session</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ── Edit User Modal ────────────────────────────────────────────────────── -->
<div class="modal fade" id="editUserModal" tabindex="-1" aria-labelledby="editUserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="/westec_bms/admin/user_management.php" id="editUserForm">
                <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                <input type="hidden" name="action" value="edit_user">
                <input type="hidden" name="target_user_id" id="editUserId">

                <div class="modal-header">
                    <h5 class="modal-title" id="editUserModalLabel">
                        <i data-lucide="pencil" class="text-primary me-2"></i>Edit User
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Username <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="edit_username" id="editUsername" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email Address <span class="text-danger">*</span></label>
                        <input type="email" class="form-control" name="edit_email" id="editEmail" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Role <span class="text-danger">*</span></label>
                        <select class="form-select" name="edit_role_id" id="editRoleId" required>
                            <?php foreach ($allRoles as $r): ?>
                                <option value="<?= $r['role_id'] ?>"><?= e($r['role_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Account Status</label>
                        <select class="form-select" name="edit_status" id="editStatus">
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                            <option value="Suspended">Suspended</option>
                        </select>
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
            <form method="POST" action="/westec_bms/admin/user_management.php" id="resetPwForm">
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
<div class="modal fade" id="deleteUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-danger">
            <form method="POST" action="/westec_bms/admin/user_management.php">
                <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                <input type="hidden" name="action" value="delete_user">
                <input type="hidden" name="target_user_id" id="deleteUserId">

                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">
                        <i data-lucide="trash-2" class="me-2"></i>Delete User
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center py-4">
                    <i data-lucide="alert-triangle" class="text-danger fs-1 mb-3 d-block"></i>
                    <p class="mb-1">Permanently delete user:</p>
                    <strong id="deleteUsername" class="fs-5 text-danger"></strong>
                    <p class="text-muted small mt-2 mb-0">This action cannot be undone. All associated data will be removed.</p>
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
// ── Password match feedback (Add form)
document.getElementById('confirm_password').addEventListener('input', function() {
    const pw  = document.getElementById('password').value;
    const fb  = document.getElementById('pwMatchFeedback');
    const btn = document.getElementById('addUserBtn');
    if (!this.value) { fb.textContent = ''; return; }
    if (this.value === pw) {
        fb.textContent = '✓ Passwords match'; fb.className = 'form-text text-success'; btn.disabled = false;
    } else {
        fb.textContent = '✗ Passwords do not match'; fb.className = 'form-text text-danger';
    }
});

// ── Password match feedback (Reset modal)
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

// ── Open Edit Modal
function openEditModal(data) {
    document.getElementById('editUserId').value   = data.user_id;
    document.getElementById('editUsername').value = data.username;
    document.getElementById('editEmail').value    = data.email || '';
    document.getElementById('editRoleId').value   = data.role_id;
    document.getElementById('editStatus').value   = data.status;
    new bootstrap.Modal(document.getElementById('editUserModal')).show();
}

// ── Open Reset Password Modal
function openResetModal(userId, username) {
    document.getElementById('resetUserId').value    = userId;
    document.getElementById('resetUsername').textContent = username;
    document.getElementById('newPw').value          = '';
    document.getElementById('confirmNewPw').value   = '';
    document.getElementById('resetPwFeedback').textContent = '';
    new bootstrap.Modal(document.getElementById('resetPwModal')).show();
}

// ── Open Delete Modal
function openDeleteModal(userId, username) {
    document.getElementById('deleteUserId').value        = userId;
    document.getElementById('deleteUsername').textContent = username;
    new bootstrap.Modal(document.getElementById('deleteUserModal')).show();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
