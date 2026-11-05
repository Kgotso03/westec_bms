<?php
/**
 * WesTec Eswatini Business Management System (WesTec BMS)
 * Staff Management Module (CRUD & User Profile Linkage)
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

// Only System Administrator and IT Manager can access the Staff Directory
requireRole(['Administrator', 'IT Manager']);

$pdo = getDbConnection();
$currentUser = getCurrentUser();

$errors = [];
$editStaff = null;

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($token)) {
        setFlash('danger', 'Security validation failed (CSRF). Please retry.');
        header('Location: /westec_bms/staff/index.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    // ADD STAFF
    if ($action === 'add') {
        $fullName   = cleanInput($_POST['full_name'] ?? '');
        $email      = cleanInput($_POST['email'] ?? '');
        $phone      = cleanInput($_POST['phone'] ?? '');
        $jobTitle   = cleanInput($_POST['job_title'] ?? '');
        $department = cleanInput($_POST['department'] ?? 'IT Technical Services');
        $userId     = !empty($_POST['user_id']) ? (int)$_POST['user_id'] : null;
        $managerId  = !empty($_POST['manager_id']) ? (int)$_POST['manager_id'] : null;
        $isActive   = isset($_POST['is_active']) ? 1 : 0;

        if (empty($fullName)) {
            $errors[] = 'Full staff name is required.';
        }
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid staff email address is required.';
        }
        if (empty($phone)) {
            $errors[] = 'Contact phone number is required.';
        }
        if (empty($jobTitle)) {
            $errors[] = 'Job title / designation is required.';
        }

        // Admin must specify which manager the staff is under
        if (hasRole('Administrator')) {
            if (empty($managerId)) {
                $errors[] = 'Please specify which IT Manager this staff member reports under.';
            }
        } else {
            // If an IT Manager is adding staff, default to themselves if unspecified
            if (empty($managerId)) {
                $managerId = (int)$currentUser['user_id'];
            }
        }

        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO staff (full_name, email, phone, job_title, department, user_id, manager_id, is_active, created_at)
                    VALUES (:name, :email, :phone, :job, :dept, :uid, :mid, :active, NOW())
                ");
                $stmt->execute([
                    ':name'   => $fullName,
                    ':email'  => $email,
                    ':phone'  => $phone,
                    ':job'    => $jobTitle,
                    ':dept'   => $department,
                    ':uid'    => $userId,
                    ':mid'    => $managerId,
                    ':active' => $isActive
                ]);

                $newId = (int)$pdo->lastInsertId();
                setFlash('success', "Staff member '{$fullName}' registered successfully (Staff ID #{$newId}).");
                header('Location: /westec_bms/staff/index.php');
                exit;
            } catch (Exception $e) {
                $errors[] = parseDatabaseErrorMessage($e);
            }
        }
    }

    // EDIT STAFF
    elseif ($action === 'edit') {
        $staffId    = (int)($_POST['staff_id'] ?? 0);
        $fullName   = cleanInput($_POST['full_name'] ?? '');
        $email      = cleanInput($_POST['email'] ?? '');
        $phone      = cleanInput($_POST['phone'] ?? '');
        $jobTitle   = cleanInput($_POST['job_title'] ?? '');
        $department = cleanInput($_POST['department'] ?? 'IT Technical Services');
        $userId     = !empty($_POST['user_id']) ? (int)$_POST['user_id'] : null;
        $managerId  = !empty($_POST['manager_id']) ? (int)$_POST['manager_id'] : null;
        $isActive   = isset($_POST['is_active']) ? 1 : 0;

        if ($staffId <= 0) {
            $errors[] = 'Invalid staff identifier.';
        }
        if (empty($fullName)) {
            $errors[] = 'Full staff name is required.';
        }
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid staff email address is required.';
        }
        if (empty($phone)) {
            $errors[] = 'Contact phone number is required.';
        }
        if (empty($jobTitle)) {
            $errors[] = 'Job title is required.';
        }

        // Admin must specify which manager the staff is under
        if (hasRole('Administrator') && empty($managerId)) {
            $errors[] = 'Please specify which IT Manager this staff member reports under.';
        }

        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare("
                    UPDATE staff
                    SET full_name = :name,
                        email = :email,
                        phone = :phone,
                        job_title = :job,
                        department = :dept,
                        user_id = :uid,
                        manager_id = :mid,
                        is_active = :active
                    WHERE staff_id = :id
                ");
                $stmt->execute([
                    ':name'   => $fullName,
                    ':email'  => $email,
                    ':phone'  => $phone,
                    ':job'    => $jobTitle,
                    ':dept'   => $department,
                    ':uid'    => $userId,
                    ':mid'    => $managerId,
                    ':active' => $isActive,
                    ':id'     => $staffId
                ]);

                setFlash('success', "Staff profile for '{$fullName}' updated successfully.");
                header('Location: /westec_bms/staff/index.php');
                exit;
            } catch (Exception $e) {
                $errors[] = parseDatabaseErrorMessage($e);
            }
        }
    }

    // TOGGLE STATUS
    elseif ($action === 'toggle_status') {
        $staffId = (int)($_POST['staff_id'] ?? 0);
        $newStatus = (int)($_POST['new_status'] ?? 0);

        if ($staffId > 0) {
            try {
                $stmt = $pdo->prepare("UPDATE staff SET is_active = :st WHERE staff_id = :id");
                $stmt->execute([':st' => $newStatus, ':id' => $staffId]);
                $statusLabel = $newStatus === 1 ? 'activated' : 'deactivated';
                setFlash('info', "Staff member successfully {$statusLabel}.");
                header('Location: /westec_bms/staff/index.php');
                exit;
            } catch (Exception $e) {
                setFlash('danger', parseDatabaseErrorMessage($e));
                header('Location: /westec_bms/staff/index.php');
                exit;
            }
        }
    }
}

// Fetch edit target if requested
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM staff WHERE staff_id = :id");
    $stmt->execute([':id' => $editId]);
    $editStaff = $stmt->fetch();
}

// Fetch user accounts that can be linked
$currentLinkedUserId = $editStaff['user_id'] ?? 0;
$usersStmt = $pdo->prepare("
    SELECT u.user_id, u.username, r.role_name
    FROM users u
    JOIN roles r ON u.role_id = r.role_id
    WHERE u.user_id NOT IN (SELECT user_id FROM staff WHERE user_id IS NOT NULL AND user_id != :current)
    ORDER BY u.username ASC
");
$usersStmt->execute([':current' => $currentLinkedUserId]);
$availableUsers = $usersStmt->fetchAll();

// Fetch available IT Managers for staff reporting assignment
$managersStmt = $pdo->query("
    SELECT u.user_id, u.username, u.email, s.full_name
    FROM users u
    JOIN roles r ON u.role_id = r.role_id
    LEFT JOIN staff s ON s.user_id = u.user_id
    WHERE r.role_name = 'IT Manager'
    ORDER BY u.username ASC
");
$managersList = $managersStmt->fetchAll();

// Fetch all staff members with linked user info, reporting manager, and ticket assignment count
$staffList = $pdo->query("
    SELECT s.*, 
           u.username AS linked_username, u.profile_photo, r.role_name AS linked_role,
           m.username AS manager_username, ms.full_name AS manager_name,
           (SELECT COUNT(*) FROM tickets t WHERE t.assigned_staff_id = s.staff_id) AS assigned_tickets,
           (SELECT COUNT(*) FROM project_assignments pa WHERE pa.staff_id = s.staff_id) AS assigned_projects
    FROM staff s
    LEFT JOIN users u ON s.user_id = u.user_id
    LEFT JOIN roles r ON u.role_id = r.role_id
    LEFT JOIN users m ON s.manager_id = m.user_id
    LEFT JOIN staff ms ON ms.user_id = m.user_id
    ORDER BY s.is_active DESC, s.full_name ASC
")->fetchAll();

$pageTitle = 'Staff Directory';
$pageSubtitle = 'Manage Technical Personnel, Engineers, Job Roles & User Account Linkages';
require_once __DIR__ . '/../includes/header.php';
?>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger mb-4">
        <ul class="mb-0 ps-3">
            <?php foreach ($errors as $e): ?>
                <li><?= e($e) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<!-- Staff Form Section -->
<div class="content-section">
    <div class="section-header">
        <h2 class="section-title">
            <i data-lucide="badge-check" class="text-primary"></i>
            <?= $editStaff ? 'Edit Staff: ' . e($editStaff['full_name']) : 'Register New Staff Member' ?>
        </h2>
        <?php if ($editStaff): ?>
            <a href="/westec_bms/staff/index.php" class="btn btn-sm btn-outline-secondary">
                <i data-lucide="x-circle" class="me-1"></i> Cancel Edit
            </a>
        <?php endif; ?>
    </div>

    <form method="POST" action="/westec_bms/staff/index.php" novalidate>
        <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
        <input type="hidden" name="action" value="<?= $editStaff ? 'edit' : 'add' ?>">
        <?php if ($editStaff): ?>
            <input type="hidden" name="staff_id" value="<?= $editStaff['staff_id'] ?>">
        <?php endif; ?>

        <div class="row g-3">
            <div class="col-md-6 col-lg-4">
                <label for="full_name" class="form-label">Full Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="full_name" name="full_name" required value="<?= e($editStaff['full_name'] ?? ($_POST['full_name'] ?? '')) ?>" placeholder="e.g. Sibusiso Dlamini">
            </div>

            <div class="col-md-6 col-lg-4">
                <label for="email" class="form-label">Email Address <span class="text-danger">*</span></label>
                <input type="email" class="form-control" id="email" name="email" required value="<?= e($editStaff['email'] ?? ($_POST['email'] ?? '')) ?>" placeholder="e.g. s.dlamini@westeceswatini.co.sz">
            </div>

            <div class="col-md-6 col-lg-4">
                <label for="phone" class="form-label">Phone Contact <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="phone" name="phone" required value="<?= e($editStaff['phone'] ?? ($_POST['phone'] ?? '')) ?>" placeholder="e.g. +268 7633 4455">
            </div>

            <div class="col-md-6 col-lg-4">
                <label for="job_title" class="form-label">Job Title / Designation <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="job_title" name="job_title" required value="<?= e($editStaff['job_title'] ?? ($_POST['job_title'] ?? '')) ?>" placeholder="e.g. Senior Network & Systems Engineer">
            </div>

            <div class="col-md-6 col-lg-4">
                <label for="department" class="form-label">Department</label>
                <select class="form-select" id="department" name="department">
                    <?php 
                    $depts = ['IT Technical Services', 'Network & Infrastructure', 'Operations', 'Cybersecurity', 'Client Support', 'Executive'];
                    $currDept = $editStaff['department'] ?? 'IT Technical Services';
                    foreach ($depts as $d): ?>
                        <option value="<?= $d ?>" <?= ($currDept === $d) ? 'selected' : '' ?>><?= $d ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-6 col-lg-4">
                <label for="manager_id" class="form-label">
                    Reporting Manager <?= hasRole('Administrator') ? '<span class="text-danger">*</span>' : '' ?>
                </label>
                <select class="form-select" id="manager_id" name="manager_id" <?= hasRole('Administrator') ? 'required' : '' ?>>
                    <option value="">-- Select Reporting Manager --</option>
                    <?php foreach ($managersList as $mgr): ?>
                        <?php 
                            $defaultMgrId = $editStaff['manager_id'] ?? ($_POST['manager_id'] ?? ($currentUser['role_name'] === 'IT Manager' ? $currentUser['user_id'] : 0));
                            $selectedMgr = ($defaultMgrId == $mgr['user_id']) ? 'selected' : '';
                            $mgrDisplayName = !empty($mgr['full_name']) ? e($mgr['full_name']) . ' (' . e($mgr['username']) . ')' : e($mgr['username']);
                        ?>
                        <option value="<?= $mgr['user_id'] ?>" <?= $selectedMgr ?>>
                            <?= $mgrDisplayName ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Specify the IT Manager overseeing this team member.</div>
            </div>

            <div class="col-md-6 col-lg-4">
                <label for="user_id" class="form-label">Link to User Account</label>
                <select class="form-select" id="user_id" name="user_id">
                    <option value="">-- No Linked System Account --</option>
                    <?php foreach ($availableUsers as $u): ?>
                        <option value="<?= $u['user_id'] ?>" <?= (($editStaff['user_id'] ?? 0) == $u['user_id']) ? 'selected' : '' ?>>
                            <?= e($u['username']) ?> (<?= e($u['role_name']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Connects personnel profile with login account.</div>
            </div>

            <div class="col-12">
                <div class="form-check form-switch mt-2">
                    <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" <?= (!isset($editStaff) || $editStaff['is_active'] == 1) ? 'checked' : '' ?>>
                    <label class="form-check-label fw-semibold" for="is_active">Staff Member is Active & Available for Ticket/Project Assignments</label>
                </div>
            </div>
        </div>

        <div class="text-end mt-3 pt-3 border-top">
            <button type="submit" class="btn btn-westec px-4">
                <i data-lucide="save" class="me-1"></i> <?= $editStaff ? 'Save Changes' : 'Register Staff Member' ?>
            </button>
        </div>
    </form>
</div>

<!-- Staff Directory Table -->
<div class="content-section">
    <div class="section-header">
        <h2 class="section-title">
            <i data-lucide="users" class="text-primary"></i>
            Staff Directory (<?= count($staffList) ?>)
        </h2>
        <div class="input-group input-group-sm" style="max-width: 250px;">
            <span class="input-group-text bg-light"><i data-lucide="search"></i></span>
            <input type="text" class="form-control" placeholder="Search staff..." data-table-filter="staffTable">
        </div>
    </div>

    <div class="table-responsive">
        <table class="table-westec" id="staffTable">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Full Name & Role</th>
                    <th>Department</th>
                    <th>Reporting Manager</th>
                    <th>Contact Info</th>
                    <th>Linked User Account</th>
                    <th class="text-center">Workload</th>
                    <th class="text-center">Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($staffList)): ?>
                    <tr>
                        <td colspan="9" class="text-center text-muted py-4">No staff members registered.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($staffList as $st): ?>
                        <tr>
                            <td><span class="badge bg-light text-dark border">#<?= $st['staff_id'] ?></span></td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <?php $stAvatar = getUserAvatarUrl(['profile_photo' => $st['profile_photo']]); ?>
                                    <?php if ($stAvatar): ?>
                                        <img src="<?= $stAvatar ?>" alt="<?= e($st['full_name']) ?>" class="rounded-circle border" style="width: 34px; height: 34px; object-fit: cover;">
                                    <?php else: ?>
                                        <div class="rounded-circle bg-light border text-primary d-inline-flex align-items-center justify-content-center fw-bold" style="width: 34px; height: 34px; font-size: 0.8rem;">
                                            <?= strtoupper(substr($st['full_name'], 0, 2)) ?>
                                        </div>
                                    <?php endif; ?>
                                    <div>
                                        <strong class="text-dark d-block"><?= e($st['full_name']) ?></strong>
                                        <span class="text-muted small"><?= e($st['job_title']) ?></span>
                                    </div>
                                </div>
                            </td>
                            <td><span class="badge bg-light text-secondary border"><?= e($st['department']) ?></span></td>
                            <td>
                                <?php if (!empty($st['manager_username'])): ?>
                                    <span class="badge bg-light text-dark border">
                                        <i data-lucide="shield" class="me-1 text-primary"></i>
                                        <?= e($st['manager_name'] ?: $st['manager_username']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted small"><em>Unassigned</em></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="small"><i data-lucide="mail" class="me-1 text-muted"></i><?= e($st['email']) ?></div>
                                <div class="small"><i data-lucide="phone" class="me-1 text-muted"></i><?= e($st['phone']) ?></div>
                            </td>
                            <td>
                                <?php if ($st['linked_username']): ?>
                                    <span class="badge bg-info text-dark">
                                        <i data-lucide="user-check" class="me-1"></i><?= e($st['linked_username']) ?>
                                    </span>
                                    <span class="d-block small text-muted"><?= e($st['linked_role']) ?></span>
                                <?php else: ?>
                                    <span class="text-muted small"><em>Unlinked</em></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-primary" title="Assigned Tickets"><?= $st['assigned_tickets'] ?> Tickets</span>
                                <span class="badge bg-secondary" title="Project Assignments"><?= $st['assigned_projects'] ?> Proj</span>
                            </td>
                            <td class="text-center">
                                <?php if ($st['is_active']): ?>
                                    <span class="badge bg-success">Active</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">Deactivated</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                    <a href="/westec_bms/staff/index.php?edit=<?= $st['staff_id'] ?>" class="btn btn-outline-secondary" title="Edit Staff Details">
                                        <i data-lucide="file-edit"></i>
                                    </a>
                                    <!-- Toggle Active Status Form -->
                                    <form method="POST" action="/westec_bms/staff/index.php" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="staff_id" value="<?= $st['staff_id'] ?>">
                                        <input type="hidden" name="new_status" value="<?= $st['is_active'] ? 0 : 1 ?>">
                                        <button type="submit" class="btn <?= $st['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>" title="<?= $st['is_active'] ? 'Deactivate' : 'Activate' ?>">
                                            <i data-lucide="<?= $st['is_active'] ? 'user-x' : 'user-check' ?>"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
